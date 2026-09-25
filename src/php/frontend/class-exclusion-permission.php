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

use AVPVH_LLDAP;
use WP_Error;

/**
 * Shared permission check for excluding a photo/video from the gallery:
 * either a WordPress admin, or a logged-in member of the "boek" LLDAP group
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
			! class_exists( AVPVH_LLDAP::class )
		) {
			return null;
		}

		$member = avpvh_get_member_by_wp_user( get_current_user_id() );

		return $member && isset( $member->user_id ) && '' !== $member->user_id ? $member : null;
	}

	/**
	 * The member's LLDAP group display names, lower-cased, cached briefly so
	 * this doesn't add an LLDAP round-trip to every request.
	 *
	 * @param object $member A member record with a `user_id` property (their LLDAP UID).
	 *
	 * @return array<string>
	 */
	private static function cached_group_names( $member ) {
		$cache_key = 'avpvh_gallery_lldap_groups_' . $member->user_id;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = AVPVH_LLDAP::get_user_groups( $member->user_id );
		$names  = $result instanceof WP_Error ? array() : self::group_display_names( $result );
		$ttl    = $result instanceof WP_Error ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
		set_transient( $cache_key, $names, $ttl );

		return $names;
	}

	/**
	 * Extracts and lower-cases each group's display name.
	 *
	 * @param array<array<string, mixed>> $groups Raw groups, as returned by AVPVH_LLDAP::get_user_groups().
	 *
	 * @return array<string>
	 */
	private static function group_display_names( array $groups ) {
		return array_map(
			static function ( $group ) {
				return strtolower( isset( $group['displayName'] ) ? (string) $group['displayName'] : '' );
			},
			$groups
		);
	}
}
