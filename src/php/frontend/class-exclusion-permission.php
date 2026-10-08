<?php
/**
 * Contains the Exclusion_Permission class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use AVPVH_Directory;

/**
 * Shared permission check for excluding a photo/video from the gallery:
 * either a WordPress admin, or a logged-in member of the "boek" directory group
 * (the group that gates the separate avpvh-members "Zoeken in documenten"
 * feature — see that plugin's class-nav-auth.php for the canonical check
 * this mirrors). Used both as a REST permission_callback and directly from
 * PHP to decide whether to show the exclude control in the lightbox at all,
 * so both places agree on exactly who is allowed to exclude a photo.
 *
 * Avpvh-members is an optional dependency: if it isn't active, only
 * WordPress admins can exclude.
 */
final class Exclusion_Permission {

	/**
	 * Checks if the current user may exclude photos/videos from the gallery.
	 *
	 * @return bool
	 */
	public static function check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$member = self::current_member();

		if ( null === $member ) {
			return false;
		}

		foreach ( self::cached_group_names( $member ) as $group_name ) {
			if ( 'boek' === $group_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves the logged-in user to an avpvh-members member record, if that
	 * (optional) plugin is active and the user is one.
	 *
	 * @return object|null
	 */
	private static function current_member() {
		if (
			! is_user_logged_in() ||
			! function_exists( 'avpvh_get_member_by_wp_user' ) ||
			! class_exists( AVPVH_Directory::class )
		) {
			return null;
		}

		$member = avpvh_get_member_by_wp_user( get_current_user_id() );

		if ( ! is_object( $member ) ) {
			return null;
		}

		return isset( $member->user_id ) && '' !== $member->user_id ? $member : null;
	}

	/**
	 * The member's directory group names, lower-cased. avpvh-members caches
	 * these (15 minutes) and reads them from LLDAP or OpenLDAP, whichever
	 * backend it is configured for.
	 *
	 * @param object $member A member record with a `user_id` property (their directory uid).
	 *
	 * @return array<string>
	 */
	private static function cached_group_names( $member ) {
		return array_map( 'strtolower', AVPVH_Directory::cached_user_groups( (string) $member->user_id ) );
	}
}
