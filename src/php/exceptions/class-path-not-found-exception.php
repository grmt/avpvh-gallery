<?php
/**
 * Contains the Path_Not_Found_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Exceptions\Exception as Avpvh_Exception;

/**
 * The requested path doesn't exist in this gallery.
 */
final class Path_Not_Found_Exception extends Avpvh_Exception {

	/**
	 * Path_Not_Found_Exception class constructor
	 */
	public function __construct() {
		parent::__construct(
			esc_html__(
				'No such directory found in this gallery - it may have been deleted or renamed.',
				'avpvh-gallery'
			)
		);
	}
}
