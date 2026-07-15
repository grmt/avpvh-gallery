<?php
/**
 * Contains the Exif_Inspector_Permission class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

/**
 * Shared REST permission check for all EXIF Inspector routes.
 */
final class Exif_Inspector_Permission {

	/**
	 * Checks if the current user has admin permission.
	 *
	 * @return bool
	 */
	public static function check() {
		return current_user_can( 'manage_options' );
	}
}
