<?php
/**
 * Contains the API_Rate_Limit_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Exceptions\Exception as Avpvh_Exception;
use Avpvh\Vendor\Google\Service\Exception as Google_Service_Exception;

/**
 * A wrapper for a rate limit exception with the API
 */
final class API_Rate_Limit_Exception extends Avpvh_Exception {

	/**
	 * API_Rate_Limit_Exception class constructor
	 *
	 * @param Google_Service_Exception $api_exception The original API exception.
	 */
	public function __construct( $api_exception ) {
		parent::__construct(
			esc_html__(
				'The maximum number of requests has been exceeded. Please try again in a minute.',
				'avpvh-gallery'
			),
			$api_exception->getCode(),
			$api_exception
		);
	}
}
