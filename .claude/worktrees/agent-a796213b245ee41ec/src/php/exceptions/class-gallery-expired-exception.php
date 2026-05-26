<?php
/**
 * Contains the Gallery_Expired_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

use Avpvh\Exceptions\Exception as Avpvh_Exception;

/**
 * The requested path doesn't exist in this gallery.
 */
final class Gallery_Expired_Exception extends Avpvh_Exception {

	/**
	 * Gallery_Expired_Exception class constructor
	 */
	public function __construct() {
		parent::__construct( esc_html__( 'The gallery has expired.', 'avpvh-gallery' ) );
	}
}
