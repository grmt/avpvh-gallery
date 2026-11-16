<?php
/**
 * Media controllers send correct metadata, headers and byte-range requests.
 *
 * @package avpvh-gallery
 */

use Avpvh\Admin\Exif_Inspector\Media_Stream_REST;
use Avpvh\Tests\Google_Transport;
use Avpvh\Tests\Media_Output;
use Avpvh\Vendor\GuzzleHttp\Psr7\Response;
use Avpvh\Vendor\Psr\Http\Message\RequestInterface;

require_once dirname( __DIR__ ) . '/fixtures/class-google-transport.php';
require_once dirname( __DIR__ ) . '/fixtures/class-media-output.php';
require_once dirname( __DIR__ ) . '/fixtures/media-output-functions.php';

/** Captures only terminal output; authorization and HTTP response streams remain real. */
final class Media_Output_Test extends WP_UnitTestCase {

	/**
	 * Browser ranges reach Drive verbatim after normalization and return exact stream bytes.
	 *
	 * @dataProvider video_ranges
	 * @param string $range Browser header.
	 * @param string $expected_range Normalized Drive header.
	 * @param int    $length Content length.
	 * @param int    $status HTTP response status.
	 * @return void
	 */
	public function test_video_stream_headers_and_bytes( $range, $expected_range, $length, $status ) {
		Google_Transport::run(
			static function ( RequestInterface $request ) use ( $expected_range, $length ) {
				self::assertSame( '/drive/v3/files/fixture-video', $request->getUri()->getPath() );
				self::assertSame( $expected_range, $request->getHeaderLine( 'Range' ) );

				return new Response( 206, array(), str_repeat( 'v', $length ) );
			},
			function () use ( $range, $expected_range, $length, $status ) {
				$request = new WP_REST_Request( 'GET' );
				$request->set_query_params(
					array(
						'file_id'   => 'fixture-video',
						'mime_type' => 'video/mp4',
						'size'      => 100,
					)
				);
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- restore the original test environment verbatim.
				$original              = $_SERVER['HTTP_RANGE'] ?? null;
				$_SERVER['HTTP_RANGE'] = $range;
				Media_Output::begin();

				try {
					$result = ( new Media_Stream_REST() )->stream_video( $request );
					$this->assertSame( 'Video stream failed: ' . Media_Output::COMPLETE, $result->get_error_message() );
					$this->assertSame( str_repeat( 'v', $length ), Media_Output::$body );
					$this->assertContains( 'Content-Length: ' . $length, Media_Output::$headers );
					$this->assertContains( 'Content-Type: video/mp4', Media_Output::$headers );
					$this->assertContains( 'Cache-Control: private, no-store', Media_Output::$headers );
					$this->assertSame( $status, Media_Output::$status );

					if ( '' !== $range ) {
						$this->assertContains(
							'Content-Range: ' . str_replace( '=', ' ', $expected_range ) . '/100',
							Media_Output::$headers
						);
					}
				} finally {
					Media_Output::$active = false;

					if ( null === $original ) {
						unset( $_SERVER['HTTP_RANGE'] );
					} else {
						$_SERVER['HTTP_RANGE'] = $original;
					}
				}
			}
		);
	}

	/**
	 * Original downloads request metadata and content separately and retain binary contents.
	 *
	 * @return void
	 */
	public function test_original_download_uses_file_metadata() {
		Google_Transport::run(
			static function ( RequestInterface $request ) {
				parse_str( $request->getUri()->getQuery(), $query );
				self::assertSame( '/drive/v3/files/fixture-photo', $request->getUri()->getPath() );
				self::assertSame( 'true', $query['supportsAllDrives'] );

				return isset( $query['fields'] )
					? new Response( 200, array(), '{"name":"Original.jpg","mimeType":"image/jpeg","size":"5"}' )
					: new Response( 200, array(), "jpeg\0" );
			},
			function () {
				$request = new WP_REST_Request( 'GET' );
				$request->set_param( 'file_id', 'fixture-photo' );
				Media_Output::begin();

				try {
					$result = ( new Media_Stream_REST() )->download_original( $request );
					$this->assertSame( 'Download failed: ' . Media_Output::COMPLETE, $result->get_error_message() );
					$this->assertSame( "jpeg\0", Media_Output::$body );
					$this->assertContains(
						'Content-Disposition: attachment; filename="Original.jpg"',
						Media_Output::$headers
					);
					$this->assertContains( 'Content-Type: image/jpeg', Media_Output::$headers );
					$this->assertContains( 'Content-Length: 5', Media_Output::$headers );
				} finally {
					Media_Output::$active = false;
				}
			}
		);
	}

	/**
	 * Proxy headers use the actual byte count and normalize WordPress's array-valued headers.
	 *
	 * @return void
	 */
	public function test_proxy_headers_match_the_upstream_response() {
		$transport = static function () {
			return array(
				'body'     => "jpeg\0",
				'headers'  => array( 'content-type' => array( 'image/png' ) ),
				'response' => array( 'code' => 200 ),
			);
		};
		add_filter( 'pre_http_request', $transport );
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'url', 'https://lh3.googleusercontent.com/fixture' );
		Media_Output::begin( true );

		try {
			( new Media_Stream_REST() )->proxy_image( $request );
			$this->fail( 'The proxy must reach its terminal output boundary.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( Media_Output::COMPLETE, $error->getMessage() );
			$this->assertContains( 'Content-Type: image/png', Media_Output::$headers );
			$this->assertContains( 'Content-Length: 5', Media_Output::$headers );
		} finally {
			Media_Output::$active = false;
			remove_filter( 'pre_http_request', $transport );
		}
	}

	/**
	 * Full, explicit and suffix requests against a 100-byte video.
	 *
	 * @return array<array{string,string,int,int}>
	 */
	public static function video_ranges() {
		return array(
			array( '', 'bytes=0-99', 100, 200 ),
			array( 'bytes=3-8', 'bytes=3-8', 6, 206 ),
			array( 'bytes=-10', 'bytes=90-99', 10, 206 ),
		);
	}
}
