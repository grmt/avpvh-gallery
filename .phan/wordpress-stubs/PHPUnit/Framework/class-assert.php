<?php
/**
 * @package wordpress-stubs
 */

declare(strict_types = 1);

namespace PHPUnit\Framework;

class Assert {

	/**
	 * @param string $expected
	 * @param mixed  $actual
	 * @param string $message
	 * @return void
	 */
	public function assertInstanceOf( $expected, $actual, $message = '' ) {
	}

	/** @param mixed $expected @param mixed $actual @param string $message @return void */
	public static function assertSame( $expected, $actual, $message = '' ) {}
	/** @param mixed $expected @param mixed $actual @param string $message @return void */
	public static function assertLessThanOrEqual( $expected, $actual, $message = '' ) {}
	/** @param mixed $expected @param mixed $actual @param string $message @return void */
	public static function assertGreaterThan( $expected, $actual, $message = '' ) {}
	/** @param string $needle @param string $haystack @param string $message @return void */
	public static function assertStringContainsString( $needle, $haystack, $message = '' ) {}
	/** @param string $suffix @param string $string @param string $message @return void */
	public static function assertStringEndsWith( $suffix, $string, $message = '' ) {}
	/** @param int $expected @param mixed $actual @param string $message @return void */
	public static function assertCount( $expected, $actual, $message = '' ) {}
	/** @param string $filename @param string $message @return void */
	public static function assertFileDoesNotExist( $filename, $message = '' ) {}
	/** @param string $message @return never */
	public static function fail( $message = '' ) { throw new \RuntimeException( $message ); }
}
