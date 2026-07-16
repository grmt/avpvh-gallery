<?php
/**
 * Contains the Cant_Manage_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Exceptions\Exception as Avpvh_Exception;

/**
 * Can't edit posts and pages
 */
final class Cant_Manage_Exception extends Avpvh_Exception {

	/**
	 * Cant_Manage_Exception class constructor
	 */
	public function __construct() {
		parent::__construct(
			esc_html__(
				'Insufficient role for this action - you have to be able to manage page options.',
				'avpvh-gallery'
			)
		);
	}
}
