<?php
/**
 * Contains the Photo_Shares_DB class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use stdClass;

/**
 * Storage of shared photo selections (agallery_photo_shares, see
 * Photo_Shares).
 */
final class Photo_Shares_DB {

	/**
	 * One share.
	 *
	 * @param int $share_id Share ID.
	 *
	 * @return stdClass|null
	 */
	public static function get( $share_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agallery_photo_shares WHERE id = %d", $share_id )
		);

		return $row instanceof stdClass ? $row : null;
	}

	/**
	 * A user's shares, newest first.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array<stdClass>
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agallery_photo_shares
				 WHERE user_id = %d AND status <> 'hidden' ORDER BY created_at DESC LIMIT 20",
				$user_id
			)
		);

		return self::rows( $rows );
	}

	/**
	 * The shares that are ready but past their date.
	 *
	 * @return array<stdClass>
	 */
	public static function expired() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agallery_photo_shares WHERE status = 'ready' AND expires_at < %s",
				current_time( 'mysql' )
			)
		);

		return self::rows( $rows );
	}

	/**
	 * The user's oldest share that is still open (ready before pending, so
	 * one being made now is left alone if possible), or null.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return stdClass|null
	 */
	public static function oldest_open( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agallery_photo_shares
				 WHERE user_id = %d AND status IN ('pending', 'ready')
				 ORDER BY status = 'pending', created_at LIMIT 1",
				$user_id
			)
		);

		return self::rows( $rows )[0] ?? null;
	}

	/**
	 * Takes a user's ended shares (expired, removed, failed) off their
	 * profile list ("Opruimen"). Returns how many.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return int
	 */
	public static function hide_ended( $user_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agallery_photo_shares SET status = 'hidden'
				 WHERE user_id = %d AND status IN ('expired', 'removed', 'failed')",
				$user_id
			)
		);
	}

	/**
	 * How many of a user's shares are being made or still open.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return int
	 */
	public static function open_count( $user_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agallery_photo_shares
				 WHERE user_id = %d AND status IN ('pending', 'ready')",
				$user_id
			)
		);
	}

	/**
	 * Stores a new pending share for the current user.
	 *
	 * @param array{conditions: string, description: string, folder_id: string} $fields The filter.
	 *
	 * @return int The new share's ID.
	 */
	public static function insert( array $fields ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table.
		$wpdb->insert(
			$wpdb->prefix . 'agallery_photo_shares',
			array_merge(
				$fields,
				array(
					'created_at' => current_time( 'mysql' ),
					'recipient'  => Share_Recipient::for_user( get_current_user_id() ),
					'status'     => 'pending',
					'user_id'    => get_current_user_id(),
				)
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Changes a share.
	 *
	 * @param int                  $share_id Share ID.
	 * @param array<string, mixed> $fields   Columns to change.
	 *
	 * @return void
	 */
	public static function update( $share_id, array $fields ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update( $wpdb->prefix . 'agallery_photo_shares', $fields, array( 'id' => $share_id ) );
	}

	/**
	 * The rows of a query result.
	 *
	 * @param mixed $rows What $wpdb->get_results() returned.
	 *
	 * @return array<stdClass>
	 */
	private static function rows( $rows ) {
		$objects = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $row instanceof stdClass ) {
				$objects[] = $row;
			}
		}

		return $objects;
	}
}
