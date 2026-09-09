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

use Avpvh\Frontend\Subject_Tags;

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
	 * Creates (or migrates) the photo tagging tables.
	 *
	 * Schema v1: three tables — agallery_photo_tags (person tags, keyed by
	 * member_id, with a region on the image), agallery_tag_comments and
	 * agallery_reactions (both keyed by tag_id).
	 * Schema v2: unifies person tags with the separate subject/category tags
	 * (previously agallery_photo_subject_tags — see Photo_Corrections_DB) into
	 * one table via a `category` + `tag_key` pair, and decouples comments and
	 * reactions from individual tags to the photo itself (agallery_photo_comments,
	 * agallery_photo_reactions, keyed by image_id) — comments/reactions are a
	 * property of the photo, not of any one tag on it.
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

		self::maybe_add_category_columns( $table_tags );

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

		self::maybe_backfill_photo_level(
			$table_comments,
			$wpdb->prefix . 'agallery_tag_comments',
			$table_tags,
			'comment_text'
		);
		self::maybe_backfill_photo_level(
			$table_reactions,
			$wpdb->prefix . 'agallery_reactions',
			$table_tags,
			'emoji'
		);
		self::maybe_migrate_subject_tags( $table_tags );

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
			$wpdb->prefix . 'agallery_reactions',
			$wpdb->prefix . 'agallery_tag_comments',
			$wpdb->prefix . 'agallery_photo_subject_tags',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; uninstall-time schema drop of a custom plugin table.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( 'avpvh_photo_tags_schema' );
	}

	/**
	 * Adds the category/tag_key columns (and backfills them) to a v1
	 * agallery_photo_tags table that predates the unified schema.
	 *
	 * @param string $table The agallery_photo_tags table name.
	 *
	 * @return void
	 */
	private static function maybe_add_category_columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time schema check.
		$has_category = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'category'" );

		if ( $has_category ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time schema migration.
		$wpdb->query(
			"ALTER TABLE `{$table}`
				ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'personen' AFTER image_id,
				ADD COLUMN tag_key VARCHAR(255) NOT NULL DEFAULT '' AFTER category"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time schema migration.
		$wpdb->query( "UPDATE `{$table}` SET tag_key = member_id WHERE category = 'personen'" );
		// A unique key can't be added until every existing row has a real
		// tag_key value, which the UPDATE above just ensured.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time schema migration.
		$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY image_category_key (image_id, category, tag_key)" );
	}

	/**
	 * One-time backfill of a photo-level table (comments or reactions) from
	 * its old tag-level counterpart, joining back to the tag's image_id.
	 * Only runs while the new table is still empty, so it's safe to call on
	 * every request without re-inserting duplicates.
	 *
	 * @param string $new_table   The new image_id-keyed table.
	 * @param string $old_table   The old tag_id-keyed table.
	 * @param string $tags_table  agallery_photo_tags, to resolve tag_id -> image_id.
	 * @param string $value_column The old table's payload column (comment_text or emoji).
	 *
	 * @return void
	 */
	private static function maybe_backfill_photo_level( $new_table, $old_table, $tags_table, $value_column ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time migration check.
		$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );

		if ( ! $old_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time migration check.
		$already_migrated = $wpdb->get_var( "SELECT COUNT(*) FROM `{$new_table}`" );

		if ( $already_migrated ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be placeholders; one-time data migration, no user input involved.
		$wpdb->query(
			"INSERT INTO `{$new_table}` (image_id, user_id, {$value_column}, created_at)
				SELECT t.image_id, o.user_id, o.{$value_column}, o.created_at
				FROM `{$old_table}` o
				INNER JOIN `{$tags_table}` t ON t.id = o.tag_id"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One-time migration of the old agallery_photo_subject_tags rows into
	 * the unified agallery_photo_tags table. INSERT IGNORE makes this safe
	 * to re-run: a row already migrated collides with the unique key and is
	 * silently skipped.
	 *
	 * @param string $tags_table agallery_photo_tags.
	 *
	 * @return void
	 */
	private static function maybe_migrate_subject_tags( $tags_table ) {
		global $wpdb;
		$old_table = $wpdb->prefix . 'agallery_photo_subject_tags';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time migration check.
		$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );

		if ( ! $old_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time data migration.
		$rows = $wpdb->get_results( "SELECT image_id, tag_slug, created_by, created_at FROM `{$old_table}`" );

		foreach ( $rows as $row ) {
			$category = Subject_Tags::category_for_slug( $row->tag_slug );

			if ( null === $category ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $tags_table is concatenated (not user-supplied); placeholders below are filled via $wpdb->prepare().
					"INSERT IGNORE INTO `{$tags_table}` (image_id, category, tag_key, created_by, created_at)
						VALUES (%s, %s, %s, %d, %s)",
					$row->image_id,
					$category,
					$row->tag_slug,
					$row->created_by,
					$row->created_at
				)
			);
		}
	}
}
