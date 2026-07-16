<?php
/**
 * @package wordpress-stubs
 */

declare(strict_types = 1);

class WP_REST_Request implements ArrayAccess {

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_json_params() {
	}

	/**
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function get_param( $key ) {
	}

	/**
	 * @param string $offset
	 */
	public function offsetExists( $offset ): bool {
	}

	/**
	 * @param string $offset
	 *
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
	}

	/**
	 * @param string $offset
	 * @param mixed  $value
	 */
	public function offsetSet( $offset, $value ): void {
	}

	/**
	 * @param string $offset
	 */
	public function offsetUnset( $offset ): void {
	}
}
