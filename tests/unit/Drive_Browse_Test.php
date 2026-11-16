<?php
/**
 * Drive browsing and exact path resolution use synthetic HTTP responses.
 *
 * @package avpvh-gallery
 */

use Avpvh\Admin\Drive_Path_Resolver;
use Avpvh\Admin\Exif_Inspector\Browse_REST;
use Avpvh\API_Client;
use Avpvh\Exceptions\Drive_Path_Exception;
use Avpvh\Vendor\Google\Client as Google_Client;
use Avpvh\Vendor\GuzzleHttp\Client;
use Avpvh\Vendor\GuzzleHttp\HandlerStack;
use Avpvh\Vendor\GuzzleHttp\Promise\FulfilledPromise;
use Avpvh\Vendor\GuzzleHttp\Psr7\Response;
use Avpvh\Vendor\Psr\Http\Message\RequestInterface;

require_once dirname( __DIR__, 2 ) . '/dist/admin/class-drive-path-resolver.php';

/** Exercises real Google batch decoding with a local HTTP transport fixture. */
final class Drive_Browse_Test extends WP_UnitTestCase {

	/**
	 * Google errors become the documented REST errors on every browsing route.
	 *
	 * @return void
	 */
	public function test_upstream_errors_are_reported_consistently() {
		self::with_response(
			500,
			array(
				'error' => array(
					'code'    => 500,
					'message' => 'Fixture failure',
				),
			),
			function () {
				$controller = new Browse_REST();
				$request    = self::request();

				foreach ( array( 'list_folders', 'list_files', 'list_subfolders' ) as $method ) {
					$response = $controller->$method( $request );

					if ( ! $response instanceof WP_Error ) {
						self::fail( 'A failed Drive request must return a REST error.' );
					}

					$this->assertSame( 'api_error', $response->get_error_code() );
					$this->assertSame( array( 'status' => 500 ), $response->get_error_data() );
					$this->assertStringContainsString( 'API error: ', $response->get_error_message() );
				}
			}
		);
	}

	/**
	 * Paths match exact names, including case; ambiguous names fail safely.
	 *
	 * @return void
	 */
	public function test_path_resolution_and_case_sensitive_failures() {
		self::with_response(
			200,
			array(
				'files' => array(
					array(
						'id'   => 'folder',
						'name' => 'Album',
					),
					array(
						'id'   => 'photo',
						'name' => 'Photo.jpg',
					),
					array(
						'id'   => 'duplicate-a',
						'name' => 'Duplicate.jpg',
					),
					array(
						'id'   => 'duplicate-b',
						'name' => 'Duplicate.jpg',
					),
				),
			),
			function () {
				$paths = new Drive_Path_Resolver( 'root' );
				$this->assertSame( 'photo', $paths->photo_id( 'Album/Photo.jpg' ) );
				$this->assert_path_error( $paths, 'Photo.jpg', 'invalid path' );
				$this->assert_path_error( $paths, '/Album/Photo.jpg', 'invalid path' );
				$this->assert_path_error( $paths, 'Album/photo.jpg', 'differs only in case/accents' );
				$this->assert_path_error( $paths, 'Album/Duplicate.jpg', 'ambiguous photo' );
			}
		);
	}

	/**
	 * A missing folder is reported as a 404 after a successful empty listing.
	 *
	 * @return void
	 */
	public function test_missing_folder_is_not_an_api_failure() {
		self::with_response(
			200,
			array( 'files' => array() ),
			function () {
				$response = ( new Browse_REST() )->list_folders( self::request() );

				if ( ! $response instanceof WP_Error ) {
					self::fail( 'A missing folder must return a REST error.' );
				}

				$this->assertSame( 'folder_not_found', $response->get_error_code() );
				$this->assertSame( array( 'status' => 404 ), $response->get_error_data() );
			}
		);
	}

	/**
	 * Verifies a public resolver failure retains RuntimeException compatibility.
	 *
	 * @param Drive_Path_Resolver $paths Resolver.
	 * @param string              $path Requested path.
	 * @param string              $fragment Expected message fragment.
	 * @return void
	 */
	private function assert_path_error( Drive_Path_Resolver $paths, $path, $fragment ) {
		try {
			$paths->photo_id( $path );
		} catch ( Drive_Path_Exception $error ) {
			$this->assertInstanceOf( RuntimeException::class, $error );
			$this->assertStringContainsString( $fragment, $error->getMessage() );

			return;
		}

		$this->fail( 'An invalid or ambiguous path must not resolve a photo.' );
	}

	/**
	 * A JSON request shared by the folder browsing endpoints.
	 *
	 * @return WP_REST_Request
	 */
	private static function request() {
		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"parent_id":"root","folder_name":"Album"}' );

		return $request;
	}

	/**
	 * Replaces only the Google client's HTTP transport, restoring cached clients afterwards.
	 *
	 * @param int                  $status HTTP status of each batch part.
	 * @param array<string, mixed> $payload JSON response of each batch part.
	 * @param callable():void      $run Assertions to execute.
	 * @return void
	 */
	private static function with_response( $status, array $payload, callable $run ) {
		$client = new Google_Client();
		$client->setHttpClient(
			new Client(
				array(
					'handler' => HandlerStack::create(
						static function ( RequestInterface $request ) use ( $status, $payload ) {
							preg_match_all( '/Content-ID: (\S+)/', (string) $request->getBody(), $matches );
							$body = '';

							foreach ( $matches[1] as $content_id ) {
								$body .= "--fixture\r\nContent-Type: application/http\r\n" .
								'Content-ID: response-' . $content_id .
								"\r\n\r\nHTTP/1.1 " . $status . " Fixture\r\nContent-Type: application/json\r\n\r\n" .
								wp_json_encode( $payload ) . "\r\n";
							}

							return new FulfilledPromise(
								new Response(
									200,
									array( 'Content-Type' => 'multipart/mixed; boundary=fixture' ),
									$body . '--fixture--'
								)
							);
						}
					),
				)
			)
		);
		$previous = array();

		foreach ( array(
			'current_batch'    => null,
			'drive_client'     => null,
			'pending_requests' => array(),
			'raw_client'       => $client,
		) as $name => $value ) {
			$property          = new ReflectionProperty( API_Client::class, $name );
			$previous[ $name ] = $property->getValue();
			$property->setValue( null, $value );
		}

		update_option(
			'avpvh_access_token',
			array(
				'access_token' => 'fixture',
				'created'      => time(),
				'expires_in'   => 3600,
			)
		);

		try {
			$run();
		} finally {
			foreach ( $previous as $name => $value ) {
				( new ReflectionProperty( API_Client::class, $name ) )->setValue( null, $value );
			}
		}
	}
}
