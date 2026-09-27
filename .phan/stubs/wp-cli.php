<?php
/**
 * Minimal WP-CLI signatures used by Admin\Exif_Dates_CLI. WP-CLI isn't a
 * dependency of the plugin (it only exists when the site is driven from the
 * command line), so neither wordpress-stubs package covers it. This file is
 * only scanned for static analysis; it's never required by the plugin itself.
 *
 * @package avpvh-gallery
 *
 * @phan-file-suppress PhanUnusedPublicNoOverrideMethodParameter
 */

// phpcs:ignoreFile -- mirrors WP-CLI's own (unprefixed, non-final, empty-bodied) API for static analysis only; see file docblock.

define( 'WP_CLI', true );

/**
 * WP-CLI's static facade.
 */
class WP_CLI {

	/**
	 * Registers a command.
	 *
	 * @param string                $name     Command name, with spaces for subcommands.
	 * @param callable|class-string $callable Command implementation.
	 * @param array<string, mixed>  $args     Registration options.
	 *
	 * @return bool
	 */
	public static function add_command( $name, $callable, $args = array() ) {
		return true;
	}

	/**
	 * Logs an informational message.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public static function log( $message ) {
	}

	/**
	 * Logs a success message.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public static function success( $message ) {
	}

	/**
	 * Logs a warning.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public static function warning( $message ) {
	}

	/**
	 * Logs an error and exits.
	 *
	 * @param string $message Message.
	 *
	 * @return never
	 */
	public static function error( $message ) {
		exit( 1 );
	}
}
