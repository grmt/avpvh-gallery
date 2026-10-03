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
 * Who may see whose likes. Liking a photo is personal, so a logged-in user
 * only sees the likes of their own household — themselves, the family and
 * housemates avpvh-members knows about, and their partners — both in the
 * lightbox's "liked by" names and in the filter. Only the IT administrator
 * (avpvh-members' "it_beheerder" role; WordPress administrators while that
 * role isn't available) sees everyone's.
 *
 * Without avpvh-members, a user only sees their own likes.
 */
final class Like_Visibility {

	/**
	 * Whether the current user may see everyone's likes.
	 *
	 * @return bool
	 */
	public static function sees_all() {
		if ( class_exists( '\\AVPVH_Roles' ) && method_exists( '\\AVPVH_Roles', 'current_user_is_it_admin' ) ) {
			return (bool) call_user_func( array( '\\AVPVH_Roles', 'current_user_is_it_admin' ) );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * The WordPress user IDs whose likes the current user may see, or null
	 * for everyone's.
	 *
	 * @return array<int>|null
	 */
	public static function visible_user_ids() {
		if ( self::sees_all() ) {
			return null;
		}

		$user_id   = get_current_user_id();
		$cache_key = 'avpvh_like_visible_' . $user_id;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$ids = array_values( array_unique( array_merge( array( $user_id ), self::household_user_ids( $user_id ) ) ) );
		set_transient( $cache_key, $ids, 10 * MINUTE_IN_SECONDS );

		return $ids;
	}

	/**
	 * The current user's household as WordPress user IDs, including
	 * themselves — regardless of the IT administrator's wider view. Used
	 * for family marks (Mark_Circles).
	 *
	 * @return array<int>
	 */
	public static function household_of_current_user() {
		$user_id = get_current_user_id();

		return array_values( array_unique( array_merge( array( $user_id ), self::household_user_ids( $user_id ) ) ) );
	}

	/**
	 * Whether the current user may see a given user's likes.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return bool
	 */
	public static function can_see( $user_id ) {
		$visible = self::visible_user_ids();

		return null === $visible || in_array( (int) $user_id, $visible, true );
	}

	/**
	 * WordPress user IDs of the household avpvh-members knows for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array<int>
	 */
	private static function household_user_ids( $user_id ) {
		if ( ! class_exists( '\\AVPVH_DB' ) ) {
			return array();
		}

		$member = call_user_func( array( '\\AVPVH_DB', 'get_member_by_wp_user' ), $user_id );

		if ( ! is_object( $member ) ) {
			return array();
		}

		$lookup    = method_exists( '\\AVPVH_DB', 'get_extended_household' )
			? 'get_extended_household'
			: 'get_manageable_members';
		$household = call_user_func( array( '\\AVPVH_DB', $lookup ), (int) $member->id );
		$ids       = array();

		foreach ( is_array( $household ) ? $household : array() as $relative ) {
			if ( is_object( $relative ) && isset( $relative->wp_user_id ) && 0 < (int) $relative->wp_user_id ) {
				$ids[] = (int) $relative->wp_user_id;
			}
		}

		return $ids;
	}
}
