<?php
/**
 * Contains the Activity_Participants class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Resolves a gallery folder to the sibling avpvh-members plugin's matching
 * activity, so photo-tagging can suggest that activity's known participants
 * instead of the entire membership.
 *
 * Gallery folders are named "{year} {activity name}" (e.g. "2014 Mageroy
 * III"), matching avpvh-members' avm_activities.name + .year exactly. An
 * activity only participates in this matching once an admin has explicitly
 * marked it gallery_taggable in avpvh-members — this keeps non-camp
 * activities (Contributie, t-shirt, ...) from ever matching by accident.
 *
 * Avpvh-members is an optional dependency: if its tables aren't present,
 * this simply returns no participants and callers fall back to the full
 * membership list.
 */
final class Activity_Participants {

	/**
	 * Returns the known participants of the activity matching a gallery
	 * folder name, or an empty array if there's no matching taggable
	 * activity (or avpvh-members isn't active).
	 *
	 * @param string $folder_name The Drive folder name, e.g. "2014 Mageroy III".
	 *
	 * @return array<array{id: int, name: string}>
	 */
	public static function for_folder_name( $folder_name ) {
		$cache_key = 'avpvh_activity_participants_' . md5( $folder_name );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$participants = self::look_up( $folder_name );
		set_transient( $cache_key, $participants, 2 * MINUTE_IN_SECONDS );

		return $participants;
	}

	/**
	 * Parses a leading "YYYY " off the folder name and looks up the matching
	 * taggable activity and its participants.
	 *
	 * @param string $folder_name The Drive folder name.
	 *
	 * @return array<array{id: int, name: string}>
	 */
	private static function look_up( $folder_name ) {
		if ( 1 !== preg_match( '/^(\d{4})\s+(.+)$/', trim( $folder_name ), $matches ) ) {
			return array();
		}

		global $wpdb;
		$year             = $matches[1];
		$name             = $matches[2];
		$activities_table = $wpdb->prefix . 'avm_activities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- avpvh-members table; caller caches via transient.
		$activity_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $activities_table is concatenated (not user-supplied); placeholders below are filled via $wpdb->prepare().
				"SELECT id FROM {$activities_table} WHERE name = %s AND year = %d AND gallery_taggable = 1",
				$name,
				$year
			)
		);

		if ( ! $activity_id ) {
			return array();
		}

		return self::participants_for_activity( (int) $activity_id );
	}

	/**
	 * Returns the members participating in a given activity.
	 *
	 * @param int $activity_id The avm_activities row ID.
	 *
	 * @return array<array{id: int, name: string}>
	 */
	private static function participants_for_activity( $activity_id ) {
		global $wpdb;
		$participation_table = $wpdb->prefix . 'avm_activity_participation';
		$members_table       = $wpdb->prefix . 'avm_members';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are concatenated (not user-supplied); the %d placeholder below is filled via $wpdb->prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- avpvh-members tables; caller caches via transient.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.first_name, m.last_name
				 FROM {$participation_table} p
				 INNER JOIN {$members_table} m ON m.id = p.member_id
				 WHERE p.activity_id = %d
				 ORDER BY m.first_name, m.last_name",
				$activity_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( $row ) {
				return array(
					'id'   => (int) $row->id,
					'name' => trim( $row->first_name . ' ' . $row->last_name ),
				);
			},
			$rows
		);
	}
}
