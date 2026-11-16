<?php
/**
 * @package wordpress-stubs
 */

declare(strict_types = 1);

class WP_REST_Response {

	/** @return int */
	public function get_status() {}

	/** @return mixed */
	public function get_data() {}

	/**
	 * @param mixed $data
	 * @param int   $status
	 */
	public function __construct( $data = null, $status = 200 ) {
	}
}
