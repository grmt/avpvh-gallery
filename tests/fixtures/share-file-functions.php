<?php
/**
 * Namespace-local filesystem double, inactive outside the failure test.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

use Avpvh\Tests\Share_File_IO;
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- this test double must delegate to the native stream opener used by the PDF writer.
use function fopen as php_fopen;

/**
 * Simulates fopen failing after a temporary file was created successfully.
 *
 * @param string $path Local file.
 * @param string $mode Open mode.
 * @return resource|false
 */
function fopen( $path, $mode ) {
	return Share_File_IO::fail( $path ) ? false : php_fopen( $path, $mode );
}
