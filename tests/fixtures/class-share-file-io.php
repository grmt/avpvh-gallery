<?php
/**
 * Injects a local open failure after tempnam has created the PDF fixture.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Tests;

/** Enables a deterministic filesystem failure without changing directory permissions. */
final class Share_File_IO {

	/**
	 * Path captured when simulating the failure; null disables it.
	 *
	 * @var string|null
	 */
	private static $path = null;

	/**
	 * Enables or disables the failing open.
	 *
	 * @param bool $enabled Whether to fail PDF opens.
	 * @return void
	 */
	public static function enable( $enabled ) {
		self::$path = $enabled ? '' : null;
	}

	/**
	 * Whether to fail this open, remembering the temporary path for cleanup assertions.
	 *
	 * @param string $path File being opened.
	 * @return bool
	 */
	public static function fail( $path ) {
		if ( null === self::$path ) {
			return false;
		}

		self::$path = $path;

		return true;
	}

	/**
	 * Returns the path created before opening failed.
	 *
	 * @return string|null
	 */
	public static function path() {
		return self::$path;
	}
}
