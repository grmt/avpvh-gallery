<?php
/**
 * WP-CLI's output helper, used by the plugin's WP-CLI commands. Like
 * wp-cli.php, only scanned for static analysis.
 *
 * @package avpvh-gallery
 *
 * @phan-file-suppress PhanUnusedGlobalFunctionParameter
 */

// phpcs:ignoreFile -- mirrors WP-CLI's own function signature for static analysis only.

namespace WP_CLI\Utils;

/**
 * Prints rows as a table, CSV, JSON etc.
 *
 * @param string                     $format Output format.
 * @param array<int|string, mixed>   $items  Rows (arrays or objects).
 * @param array<int, string>|string  $fields Columns.
 *
 * @return void
 */
function format_items( $format, $items, $fields ) {
}
