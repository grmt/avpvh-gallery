<?php
/**
 * @package wordpress-stubs
 */

declare(strict_types = 1);

class WP_REST_Request implements ArrayAccess {

	/**
	 * @param string $method
	 * @param string $route
	 * @param array<string, mixed> $attributes
	 */
	public function __construct( $method = 'GET', $route = '', $attributes = array() ) {}

	/** @param array<string, mixed> $params */
	public function set_query_params( $params ) {}

	/** @param string $key @param mixed $value @return void */
	public function set_param( $key, $value ) {}

	/** @param string $body */
	public function set_body( $body ) {}

	/**
	 * @param string $key
	 * @param string $value
	 */
	public function set_header( $key, $value ) {}

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
