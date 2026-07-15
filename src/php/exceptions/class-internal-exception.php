<?php
/**
 * Contains the Internal_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

use Avpvh\Exceptions\Exception as Avpvh_Exception;

/**
 * An internal exception
 */
final class Internal_Exception extends Avpvh_Exception {

	/**
	 * Internal_Exception class constructor
	 */
	public function __construct() {
		parent::__construct( esc_html__( 'An internal error happened in the gallery.', 'avpvh-gallery' ) );
	}
}
