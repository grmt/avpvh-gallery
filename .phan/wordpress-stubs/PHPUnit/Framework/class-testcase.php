<?php
/**
 * @package wordpress-stubs
 */

declare(strict_types = 1);

namespace PHPUnit\Framework;

class TestCase extends Assert {
	/** @param class-string<\Throwable> $exception @return void */
	public function expectException( $exception ) {}
	/** @param string $message @return void */
	public function expectExceptionMessage( $message ) {}

}
