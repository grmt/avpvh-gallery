<?php
/**
 * Contains the Tag_Log class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Keeps track of who tagged what: every tag added to or removed from a
 * photo (persons and subject tags alike) gets a row in agallery_tag_log,
 * which — unlike the tag rows themselves — survives the tag's removal.
 */
final class Tag_Log {

	/**
	 * Records a tag being added or removed by the current user.
	 *
	 * @param string $image_id  Drive file ID of the photo.
	 * @param string $category  The tag's category ('personen' or a subject tag group).
	 * @param string $tag_key   The tag's key (member ID, 'name:…', or subject tag slug).
	 * @param string $tag_label The person's name or the tag's label, as it was then.
	 * @param string $action    'add' or 'remove'.
	 *
	 * @return void
	 */
	public static function record( $image_id, $category, $tag_key, $tag_label, $action ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table.
		$wpdb->insert(
			$wpdb->prefix . 'agallery_tag_log',
			array(
				'action'     => $action,
				'category'   => $category,
				'created_at' => current_time( 'mysql' ),
				'image_id'   => $image_id,
				'tag_key'    => $tag_key,
				'tag_label'  => $tag_label,
				'user_id'    => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Display names of the users who tagged, for "tagged by" hints.
	 *
	 * @param array<int> $user_ids WordPress user IDs.
	 *
	 * @return array<int, string> User ID => display name.
	 */
	public static function user_names( array $user_ids ) {
		$names = array();

		foreach ( array_unique( array_filter( $user_ids ) ) as $user_id ) {
			$user              = get_userdata( (int) $user_id );
			$names[ $user_id ] = false !== $user ? (string) $user->display_name : '';
		}

		return $names;
	}
}
