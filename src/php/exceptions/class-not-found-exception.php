<?php
/**
 * Contains the Not_Found_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Exceptions\Exception as Avpvh_Exception;

/**
 * The requested resource wasn't found.
 */
final class Not_Found_Exception extends Avpvh_Exception {

	/**
	 * Not_Found_Exception class constructor
	 */
	public function __construct() {
		parent::__construct( esc_html__( "The requested resource couldn't be found.", 'avpvh-gallery' ) );
	}
}
