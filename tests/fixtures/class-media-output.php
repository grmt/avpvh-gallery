<?php
/**
 * Captures terminal HTTP output without terminating PHPUnit.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Tests;

/** Only enabled around media-controller assertions; all other calls use PHP's functions. */
final class Media_Output {

	// phpcs:disable SlevomatCodingStandard.Classes.ForbiddenPublicProperty -- shared capture state for namespace-local I/O doubles, used only by tests.

	/** The exception marks the output boundary immediately before exit. */
	public const COMPLETE = 'Fixture captured terminal media output';

	/**
	 * Whether to capture output.
	 *
	 * @var bool
	 */
	public static $active = false;

	/**
	 * Stop after the proxy's last header, before echo and exit.
	 *
	 * @var bool
	 */
	public static $proxy = false;

	/**
	 * Headers in emission order.
	 *
	 * @var array<string>
	 */
	public static $headers = array();

	/**
	 * Bytes consumed from the detached response stream.
	 *
	 * @var string
	 */
	public static $body = '';

	/**
	 * Captured response status.
	 *
	 * @var int
	 */
	public static $status = 200;

	/**
	 * Resets captured output for a single controller call.
	 *
	 * @param bool $proxy Whether the endpoint echoes rather than streaming a resource.
	 * @return void
	 */
	public static function begin( $proxy = false ) {
		self::$active  = true;
		self::$proxy   = $proxy;
		self::$headers = array();
		self::$body    = '';
		self::$status  = 200;
	}
}
