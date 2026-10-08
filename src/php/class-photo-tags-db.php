<?php
/**
 * Contains database migration functions for photo tagging tables.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Frontend\Subject_Tag_Seed;

/**
 * Photo Tags Database Migration
 */
final class Photo_Tags_DB {

	/**
	 * Schema version stored in wp_options.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility -- matches the no-modifier convention used elsewhere (see Photo_Corrections_DB::SCHEMA_VERSION).
	const SCHEMA_VERSION = 11;

	/**
	 * Runs schema migration if needed; hooked to init.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( 'avpvh_photo_tags_schema', 0 ) < self::SCHEMA_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Creates the photo tagging tables.
	 *
	 * Agallery_photo_tags holds every kind of tag on a photo — who's in it
	 * (category 'personen', tag_key = member_id, with a region on the
	 * image) and the fixed subject checklist (category = the tag's group, e.g.
	 * 'weer'; tag_key = the slug — see Subject_Tag_Groups). Comments
	 * and reactions belong to the photo itself (agallery_photo_comments,
	 * agallery_photo_reactions, both keyed by image_id), not to any one tag
	 * on it.
	 *
	 * An earlier version of this schema split person tags and subject tags
	 * into two tables, and keyed comments/reactions by tag_id instead of
	 * image_id — but a type-mismatched foreign key on member_id meant that
	 * version of agallery_photo_tags (and everything that referenced it)
	 * could never actually be created, so there was never any data in that
	 * shape to migrate from.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$table_tags = $wpdb->prefix . 'agallery_photo_tags';
		$sql_tags   = "CREATE TABLE {$table_tags} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			image_id VARCHAR(255) NOT NULL,
			category VARCHAR(20) NOT NULL DEFAULT 'personen',
			tag_key VARCHAR(255) NOT NULL DEFAULT '',
			member_id BIGINT UNSIGNED,
			member_name VARCHAR(255),
			region_data JSON,
			created_by BIGINT UNSIGNED,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			UNIQUE KEY image_category_key_user (image_id, category, tag_key, created_by),
			INDEX idx_image_id (image_id),
			INDEX idx_member_id (member_id)
		) {$charset_collate};";
		self::drop_one_tag_per_photo_key( $table_tags );
		dbDelta( $sql_tags );

		$table_comments = $wpdb->prefix . 'agallery_photo_comments';
		$sql_comments   = "CREATE TABLE {$table_comments} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			image_id VARCHAR(255) NOT NULL,
			user_id BIGINT UNSIGNED,
			comment_text LONGTEXT,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_image_id (image_id)
		) {$charset_collate};";
		dbDelta( $sql_comments );

		$table_reactions = $wpdb->prefix . 'agallery_photo_reactions';
		$sql_reactions   = "CREATE TABLE {$table_reactions} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			image_id VARCHAR(255) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			emoji VARCHAR(10),
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY unique_reaction (image_id, user_id, emoji),
			INDEX idx_image_id (image_id)
		) {$charset_collate};";
		dbDelta( $sql_reactions );

		// Who added or removed which tag, and when — kept even after a tag is
		// removed (see Tag_Log). tag_label is the name/label at the time.
		$table_log = $wpdb->prefix . 'agallery_tag_log';
		$sql_log   = "CREATE TABLE {$table_log} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			image_id VARCHAR(255) NOT NULL,
			category VARCHAR(20) NOT NULL,
			tag_key VARCHAR(255) NOT NULL,
			tag_label VARCHAR(255) NOT NULL DEFAULT '',
			action VARCHAR(10) NOT NULL,
			user_id BIGINT UNSIGNED,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_image_id (image_id),
			INDEX idx_user_id (user_id)
		) {$charset_collate};";
		dbDelta( $sql_log );

		// Where a photo was taken, when that differs from the place in its
		// folder name (an excursion during a dig) — see Photo_Places.
		$table_places = $wpdb->prefix . 'agallery_photo_places';
		$sql_places   = "CREATE TABLE {$table_places} (
			image_id VARCHAR(255) NOT NULL PRIMARY KEY,
			place VARCHAR(255) NOT NULL,
			created_by BIGINT UNSIGNED,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_place (place(64))
		) {$charset_collate};";
		dbDelta( $sql_places );

		self::create_tag_tree_table( $charset_collate );
		// Marks: star votes, one row per photo and voter (owner), level =
		// that voter's stars. circle is always 'votes' since v9; before,
		// marks were kept per family or LDAP group (see marks_to_votes()).
		// See Photo_Marks.
		$table_marks = $wpdb->prefix . 'agallery_photo_marks';
		$sql_marks   = "CREATE TABLE {$table_marks} (
			image_id VARCHAR(255) NOT NULL,
			circle VARCHAR(80) NOT NULL,
			owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
			level TINYINT UNSIGNED NOT NULL,
			updated_by BIGINT UNSIGNED,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (image_id, circle, owner),
			INDEX idx_circle_owner (circle, owner)
		) {$charset_collate};";
		dbDelta( $sql_marks );
		self::marks_to_votes( $table_marks );

		// Shares: a filter's photos copied into a Drive folder shared with
		// the user's Google address for a while (see Photo_Shares). The
		// filter is kept, so an expired share can be made again.
		$table_shares = $wpdb->prefix . 'agallery_photo_shares';
		$sql_shares   = "CREATE TABLE {$table_shares} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL,
			description VARCHAR(500) NOT NULL DEFAULT '',
			conditions TEXT NOT NULL,
			folder_id TEXT NOT NULL,
			recipient VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(10) NOT NULL,
			photo_count INT UNSIGNED NOT NULL DEFAULT 0,
			drive_folder_id VARCHAR(255) NOT NULL DEFAULT '',
			error VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			INDEX idx_user (user_id),
			INDEX idx_status_expires (status, expires_at)
		) {$charset_collate};";
		dbDelta( $sql_shares );

		update_option( 'avpvh_photo_tags_schema', self::SCHEMA_VERSION );
	}

	/**
	 * Drop tables on plugin uninstall
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'agallery_tag_nodes',
			$wpdb->prefix . 'agallery_photo_shares',
			$wpdb->prefix . 'agallery_photo_marks',
			$wpdb->prefix . 'agallery_photo_places',
			$wpdb->prefix . 'agallery_tag_log',
			$wpdb->prefix . 'agallery_photo_reactions',
			$wpdb->prefix . 'agallery_photo_comments',
			$wpdb->prefix . 'agallery_photo_tags',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; uninstall-time schema drop of a custom plugin table.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( 'avpvh_photo_tags_schema' );
	}

	/**
	 * Schema v5 made a tag a vote: each user can add the same tag to a photo
	 * once, so tags are unique per user (image_category_key_user) instead of
	 * per photo. dbDelta doesn't drop indexes, so the old one goes here.
	 *
	 * @param string $table The tags table.
	 *
	 * @return void
	 */
	private static function drop_one_tag_per_photo_key( $table ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off schema migration of a custom plugin table.

		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}

		$old_key = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'image_category_key'" );

		if ( null !== $old_key ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX image_category_key" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Schema v9 turned marks per circle (a family, or an LDAP group sharing
	 * one row with owner 0) into one pool of votes, one row per voter. Each
	 * voter keeps their highest mark (a group's shared row goes to whoever
	 * last changed it), up to the stars one person may give.
	 *
	 * @param string $table The marks table.
	 *
	 * @return void
	 */
	private static function marks_to_votes( $table ) {
		global $wpdb;
		$per_person = max( 1, (int) Options::$mark_votes_per_person->get() );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off data migration of a custom plugin table.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (image_id, circle, owner, level, updated_by)
				 SELECT image_id, 'votes', voter, LEAST(MAX(level), %d), voter
				 FROM ( SELECT image_id, level, IF(owner = 0, updated_by, owner) AS voter
				        FROM {$table} WHERE circle <> 'votes' ) old
				 WHERE voter > 0
				 GROUP BY image_id, voter
				 ON DUPLICATE KEY UPDATE level = GREATEST(level, VALUES(level))",
				$per_person
			)
		);
		$wpdb->query( "DELETE FROM {$table} WHERE circle <> 'votes'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Creates the subject-tag tree table and fills it once (see
	 * Subject_Tag_Tree): sections (Wie, Wat …) hold sections and groups,
	 * groups hold tags. Photos refer to a tag by its id, so it can be renamed
	 * or moved freely. legacy_key is the slug a node had when the vocabulary
	 * still lived in code (Subject_Tag_Groups), used once to convert tags.
	 *
	 * @param string $charset_collate The table charset/collation clause.
	 *
	 * @return void
	 */
	private static function create_tag_tree_table( $charset_collate ) {
		global $wpdb;
		$table_nodes = $wpdb->prefix . 'agallery_tag_nodes';
		$sql_nodes   = "CREATE TABLE {$table_nodes} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			parent_id BIGINT UNSIGNED NULL,
			type VARCHAR(10) NOT NULL,
			label VARCHAR(100) NOT NULL,
			single TINYINT(1) NOT NULL DEFAULT 0,
			taggable TINYINT(1) NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			legacy_key VARCHAR(64) NULL,
			INDEX idx_parent (parent_id),
			INDEX idx_legacy (legacy_key)
		) {$charset_collate};";
		dbDelta( $sql_nodes );
		Subject_Tag_Seed::seed_from_code();
	}
}
