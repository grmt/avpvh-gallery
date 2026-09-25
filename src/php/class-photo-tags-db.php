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

/**
 * Photo Tags Database Migration
 */
final class Photo_Tags_DB {

	/**
	 * Schema version stored in wp_options.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility -- matches the no-modifier convention used elsewhere (see Photo_Corrections_DB::SCHEMA_VERSION).
	const SCHEMA_VERSION = 2;

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
	 * image) and the fixed subject/category checklist (category 'graven' or
	 * 'kamp', tag_key = the slug — see Subject_Tags::CATEGORIES). Comments
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
			UNIQUE KEY image_category_key (image_id, category, tag_key),
			INDEX idx_image_id (image_id),
			INDEX idx_member_id (member_id)
		) {$charset_collate};";
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
}
