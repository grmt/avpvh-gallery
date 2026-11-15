<?php
/**
 * Media requests validate the remote host and browser byte ranges.
 *
 * @package avpvh-gallery
 */

use Avpvh\Admin\Exif_Inspector\Exif_Data_REST;
use Avpvh\Admin\Exif_Inspector\Media_Stream_REST;

/** Tests the trust boundary without fetching any real media. */
final class Media_Request_Test extends WP_UnitTestCase {

	/**
	 * A Google domain in a path, query, user name or unrelated host is not trusted.
	 *
	 * @dataProvider untrusted_urls
	 * @param string $url An untrusted media URL.
	 * @return void
	 */
	public function test_untrusted_media_urls_are_rejected( $url ) {
		$block = static function () {
			self::fail( 'An untrusted media URL must be rejected before any HTTP request.' );
		};
		add_filter( 'pre_http_request', $block );

		try {
			$request = self::request( $url );
			$this->assertSame( 'invalid_url', ( new Media_Stream_REST() )->proxy_image( $request )->get_error_code() );
			$response = ( new Exif_Data_REST() )->get_preview_exif( $request );
			$this->assertInstanceOf( WP_Error::class, $response );

			if ( $response instanceof WP_Error ) {
				$this->assertSame( 'invalid_url', $response->get_error_code() );
				$this->assertSame( array( 'status' => 400 ), $response->get_error_data() );
			}
		} finally {
			remove_filter( 'pre_http_request', $block );
		}
	}

	/**
	 * Real Google HTTPS hosts are accepted and transport errors are preserved.
	 *
	 * @return void
	 */
	public function test_google_media_hosts_reach_the_transport() {
		$transport = static function () {
			return new WP_Error( 'fixture', 'Synthetic transport failure' );
		};
		add_filter( 'pre_http_request', $transport );
		$urls = array( 'https://lh3.googleusercontent.com/image.jpg', 'https://drive.google.com/file' );

		try {
			foreach ( $urls as $url ) {
				$request = self::request( $url );
				$this->assertSame(
					'request_failed',
					( new Media_Stream_REST() )->proxy_image( $request )->get_error_code()
				);
				$response = ( new Exif_Data_REST() )->get_preview_exif( $request );
				$this->assertInstanceOf( WP_Error::class, $response );

				if ( $response instanceof WP_Error ) {
					$this->assertSame( 'download_error', $response->get_error_code() );
				}
			}
		} finally {
			remove_filter( 'pre_http_request', $transport );
		}
	}

	/**
	 * Suffix, open-ended and clamped ranges select exactly the requested bytes.
	 *
	 * @dataProvider valid_ranges
	 * @param string                $range Range header.
	 * @param array{0: int, 1: int} $expected Inclusive byte positions.
	 * @return void
	 */
	public function test_valid_video_ranges( $range, array $expected ) {
		$method = new ReflectionMethod( Media_Stream_REST::class, 'resolve_byte_range' );
		$this->assertSame( $expected, $method->invoke( null, $range, 100 ) );
	}

	/**
	 * Malformed or unsatisfiable ranges return 416 before accessing Drive.
	 *
	 * @return void
	 */
	public function test_invalid_video_ranges_return_416() {
		$request = new WP_REST_Request( 'GET' );
		$request->set_query_params(
			array(
				'file_id'   => 'fixture-video',
				'mime_type' => 'video/mp4',
				'size'      => 100,
			)
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- preserve the test environment's original header verbatim for restoration.
		$original = $_SERVER['HTTP_RANGE'] ?? null;
		$ranges   = array( 'bytes=-', 'bytes=-0', 'bytes=100-', 'bytes=9-3', 'bytes=0-1,3-4', 'invalid' );

		try {
			foreach ( $ranges as $range ) {
				$_SERVER['HTTP_RANGE'] = $range;
				$response              = ( new Media_Stream_REST() )->stream_video( $request );
				$this->assertSame( 'invalid_range', $response->get_error_code() );
				$this->assertSame( array( 'status' => 416 ), $response->get_error_data() );
			}
		} finally {
			if ( null === $original ) {
				unset( $_SERVER['HTTP_RANGE'] );
			} else {
				$_SERVER['HTTP_RANGE'] = $original;
			}
		}
	}

	/**
	 * Spoofed and unsupported URLs must not reach the HTTP client.
	 *
	 * @return array<array{0: string}>
	 */
	public static function untrusted_urls() {
		return array(
			array( 'https://evil.example/?source=drive.google.com' ),
			array( 'https://evil.example/googleusercontent.com/image.jpg' ),
			array( 'https://lh3.googleusercontent.com.evil.example/image.jpg' ),
			array( 'https://drive.google.com@evil.example/image.jpg' ),
			array( 'https://notgoogleusercontent.com/image.jpg' ),
			array( 'file://googleusercontent.com/etc/passwd' ),
			array( 'http://lh3.googleusercontent.com/image.jpg' ),
			array( 'https://lh3.googleusercontent.com:8443/image.jpg' ),
		);
	}

	/**
	 * Representative browser range requests against a 100-byte video.
	 *
	 * @return array<array{0: string, 1: array{0: int, 1: int}}>
	 */
	public static function valid_ranges() {
		return array(
			array( '', array( 0, 99 ) ),
			array( 'bytes=3-8', array( 3, 8 ) ),
			array( 'bytes=90-', array( 90, 99 ) ),
			array( 'bytes=-10', array( 90, 99 ) ),
			array( 'bytes=-999', array( 0, 99 ) ),
			array( 'bytes=90-999', array( 90, 99 ) ),
		);
	}

	/**
	 * A request readable by both the query and JSON media endpoints.
	 *
	 * @param string $url Media URL.
	 * @return WP_REST_Request
	 */
	private static function request( $url ) {
		$request = new WP_REST_Request( 'POST' );
		$request->set_query_params( array( 'url' => $url ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'url' => $url ) ) );

		return $request;
	}
}
