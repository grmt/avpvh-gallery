<?php
/**
 * Sharing fails explicitly when Drive names are ambiguous or transfers fail.
 *
 * @package avpvh-gallery
 */

use Avpvh\Exceptions\Share_Exception;
use Avpvh\Frontend\Share_Drive;
use Avpvh\Frontend\Share_Drive_Files;
use Avpvh\Tests\Google_Transport;
use Avpvh\Vendor\Google\Client;
use Avpvh\Vendor\GuzzleHttp\Psr7\Response;
use Avpvh\Vendor\Psr\Http\Message\RequestInterface;

require_once dirname( __DIR__ ) . '/fixtures/class-google-transport.php';

/** Uses the real service client with synthetic responses. */
final class Share_Drive_Test extends WP_UnitTestCase {

	/**
	 * Captured local-file warnings.
	 *
	 * @var array<string>
	 */
	private $warnings = array();

	/**
	 * Exact duplicates are rejected, rather than choosing an arbitrary destination.
	 *
	 * @return void
	 */
	public function test_duplicate_folder_names_are_rejected() {
		Google_Transport::run(
			static function () {
				return new Response( 200, array(), '{"files":[{"id":"a","name":"Album"},{"id":"b","name":"Album"}]}' );
			},
			function () {
				$this->expectException( Share_Exception::class );
				$this->expectExceptionMessage( 'More than one folder named Album' );
				Share_Drive::folder_at( 'fixture-root', 'Album', true );
			}
		);
	}

	/**
	 * Errors inside successful HTTP batch envelopes must still fail the transfer.
	 *
	 * @return void
	 */
	public function test_batch_errors_fail_and_restore_client_mode() {
		Google_Transport::run(
			static function ( RequestInterface $request ) {
				return Google_Transport::batch(
					$request,
					403,
					array(
						'error' => array(
							'code'    => 403,
							'message' => 'No permission',
						),
					)
				);
			},
			function ( Client $client ) {
				try {
					Share_Drive::parents( array( 'fixture-photo' ) );
					$this->fail( 'A failed batch part must not be silently discarded.' );
				} catch ( Share_Exception $error ) {
					$this->assertStringContainsString( 'No permission', $error->getMessage() );
				}

				$this->assertFalse( $client->shouldDefer() );
			}
		);
	}

	/**
	 * Download preserves the original bytes and asks for shared-drive media.
	 *
	 * @return void
	 */
	public function test_download_preserves_binary_content() {
		Google_Transport::run(
			static function ( RequestInterface $request ) {
				parse_str( $request->getUri()->getQuery(), $query );
				self::assertSame( 'media', $query['alt'] );
				self::assertSame( 'true', $query['supportsAllDrives'] );

				return new Response( 200, array(), "fixture\0photo\xff" );
			},
			function () {
				$this->assertSame( "fixture\0photo\xff", Share_Drive_Files::download( 'fixture-photo' ) );
			}
		);
	}

	/**
	 * Deferred metadata is not mistaken for downloaded contents.
	 *
	 * @return void
	 */
	public function test_download_rejects_a_non_response() {
		Google_Transport::run(
			static function (): Response {
				self::fail( 'Deferred requests do not reach the HTTP transport.' );
			},
			function ( Client $client ) {
				$client->setDefer( true );
				$this->expectException( Share_Exception::class );
				$this->expectExceptionMessage( 'No contents for fixture-photo' );
				Share_Drive_Files::download( 'fixture-photo' );
			}
		);
	}

	/**
	 * A missing local PDF fails clearly before attempting a Google upload.
	 *
	 * @return void
	 */
	public function test_missing_upload_file_reports_the_filename() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- assert the expected fopen warning while allowing the application's error path to run.
		set_error_handler(
			function ( $severity, $message ) {
				self::assertSame( E_WARNING, $severity );
				$this->warnings[] = $message;

				return true;
			}
		);

		try {
			Share_Drive_Files::upload_file( 'folder', 'Missing.pdf', 'application/pdf', '/missing-fixture/file.pdf' );
			$this->fail( 'A missing local file must fail before upload.' );
		} catch ( Share_Exception $error ) {
			$this->assertSame( 'Could not read Missing.pdf', $error->getMessage() );
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $this->warnings );
		$this->assertStringContainsString( 'Failed to open stream', $this->warnings[0] );
	}
}
