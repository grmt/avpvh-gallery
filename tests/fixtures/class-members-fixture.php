<?php
/**
 * An in-memory contract fixture for the optional members plugin.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Tests;

use stdClass;

/** Loaded under the members plugin's names only in an isolated test process. */
final class Members_Fixture {

	/**
	 * Returns the test's member linked to this WordPress user, if any.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return stdClass|null
	 */
	public static function get_member_by_wp_user( $user_id ) {
		$member = get_user_meta( $user_id, 'fixture_member', true );

		return $member instanceof stdClass ? $member : null;
	}

	/**
	 * Returns the supplied household, including malformed entries to exercise filtering.
	 *
	 * @param int $member_id Club member ID.
	 * @return array<mixed>
	 */
	public static function get_extended_household( $member_id ) {
		$households = get_option( 'fixture_households', array() );

		return $households[ $member_id ] ?? array();
	}

	/**
	 * The IT-administrator flag is independent of WordPress administrator permissions.
	 *
	 * @return bool
	 */
	public static function current_user_is_it_admin() {
		return true === get_option( 'fixture_it_admin', false );
	}
}
