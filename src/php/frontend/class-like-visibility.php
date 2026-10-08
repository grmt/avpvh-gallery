<?php
/**
 * Contains the Like_Visibility class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Who may see whose likes: every logged-in member sees everyone's, both in
 * the lightbox's "liked by" names and in the filter ("Geliket door"), just
 * as everyone sees the stars. Visitors who aren't logged in see none.
 *
 * (Likes used to be visible only within one's own household; that was
 * dropped so members can find the photos others liked.)
 */
final class Like_Visibility {

	/**
	 * Whether the current user may see a given user's likes.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return bool
	 */
	public static function can_see( $user_id ) {
		return is_user_logged_in() && 0 < (int) $user_id;
	}
}
