<?php
/**
 * @package wordpress-stubs
 */

declare(strict_types = 1);

class WP_Error {

	/**
	 * @var array<string|int, array<int, string>>
	 */
	public $errors = array();

	/**
	 * @var array<string|int, mixed>
	 */
	public $error_data = array();

	/**
	 * @param string|int $code
	 * @param string     $message
	 * @param mixed      $data
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
	}

	/**
	 * @param string|int $code
	 *
	 * @return string
	 */
	public function get_error_message( $code = '' ) {
	}

	/**
	 * @return string|int
	 */
	public function get_error_code() {
	}

	/**
	 * @param string|int $code
	 *
	 * @return mixed
	 */
	public function get_error_data( $code = '' ) {
	}
}
