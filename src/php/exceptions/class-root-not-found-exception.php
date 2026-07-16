<?php
/**
 * Contains the Root_Not_Found_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Exceptions\Exception as Avpvh_Exception;

/**
 * The root directory of the gallery doesn't exist.
 */
final class Root_Not_Found_Exception extends Avpvh_Exception {

	/**
	 * Root_Not_Found_Exception class constructor
	 */
	public function __construct() {
		parent::__construct(
			esc_html__(
				"The root directory of the gallery couldn't be found - it may have been deleted or renamed.",
				'avpvh-gallery'
			)
		);
	}
}
