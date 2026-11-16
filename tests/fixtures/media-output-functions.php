<?php
/**
 * Namespace-local doubles for the controller's terminal HTTP operations.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

use Avpvh\Tests\Media_Output;
use RuntimeException;
use function fpassthru as php_fpassthru;
use function header as php_header;
use function http_response_code as php_http_response_code;
use function ob_end_clean as php_ob_end_clean;
use function ob_get_level as php_ob_get_level;

/**
 * Records headers, stopping echo-based proxies before exit.
 *
 * @param string $header Header line.
 * @return void
 * @throws RuntimeException The proxy's output boundary was reached.
 */
function header( $header ) {
	if ( ! Media_Output::$active ) {
		php_header( $header );

		return;
	}

	Media_Output::$headers[] = $header;

	if ( Media_Output::$proxy && str_starts_with( $header, 'Cache-Control:' ) ) {
		throw new RuntimeException( esc_html( Media_Output::COMPLETE ) );
	}
}

/**
 * Keeps the test runner's output buffers intact while capturing a stream.
 *
 * @return int
 */
function ob_get_level() {
	return Media_Output::$active ? 0 : php_ob_get_level();
}

/**
 * Keeps PHPUnit's buffer intact when the download controller starts output.
 *
 * @return bool
 */
function ob_end_clean() {
	return Media_Output::$active || php_ob_end_clean();
}

/**
 * Captures status changes alongside response headers.
 *
 * @param int $status Response status.
 * @return int|bool
 */
function http_response_code( $status = 0 ) {
	if ( ! Media_Output::$active ) {
		return php_http_response_code( $status );
	}

	$previous             = Media_Output::$status;
	Media_Output::$status = $status;

	return $previous;
}

/**
 * Consumes the real detached resource, then prevents the controller's exit.
 *
 * @param resource $stream Downloaded resource.
 * @return int
 * @throws RuntimeException The stream's output boundary was reached.
 */
function fpassthru( $stream ) {
	if ( ! Media_Output::$active ) {
		return php_fpassthru( $stream );
	}

	Media_Output::$body = (string) stream_get_contents( $stream );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- detached test response resource.
	fclose( $stream );

	throw new RuntimeException( esc_html( Media_Output::COMPLETE ) );
}
