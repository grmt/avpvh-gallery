<?php
/**
 * Contains database migration functions for photo corrections table.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Photo Corrections Database Migration
 */
final class Photo_Corrections_DB {

	/**
	 * Schema version stored in wp_options.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility -- visibility modifiers on class constants require PHP 7.1; plugin supports PHP 5.6+.
	const SCHEMA_VERSION = 9;

	/**
	 * Runs schema migration if needed; hooked to admin_init.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( 'avpvh_corrections_schema', 0 ) < self::SCHEMA_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Creates (or migrates) the photo corrections table.
	 *
	 * Schema v1: (image_id PK, thumb_rotation, light_rotation)
	 * Schema v2: (image_id, size_key) composite PK, rotation column only
	 * Schema v3: adds h_flip and v_flip columns (dbDelta adds them non-destructively)
	 * Schema v5: adds inherited folder corrections; identity photo rows are overrides.
	 * Schema v6: rotation becomes SMALLINT so 270 is not truncated to TINYINT's 255.
	 * Schema v7: migrates the configured sNNNN correction key to lightbox.
	 * Schema v8: adds per-photo gallery exclusions with private moderation reasons.
	 * Schema v9: records whether an excluded Drive item is an image or video.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'agallery_photo_corrections';
		$folder_table    = $wpdb->prefix . 'agallery_folder_corrections';
		$exclusion_table = $wpdb->prefix . 'agallery_photo_exclusions';

		self::maybe_migrate_legacy_rotation_columns( $table, $charset_collate );

		$sql = "CREATE TABLE {$table} (
  image_id VARCHAR(255) NOT NULL,
  size_key VARCHAR(50) NOT NULL,
  rotation SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  h_flip TINYINT UNSIGNED NOT NULL DEFAULT 0,
  v_flip TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (image_id, size_key)
) {$charset_collate};";
		dbDelta( $sql );

		$folder_sql = "CREATE TABLE {$folder_table} (
  folder_id VARCHAR(255) NOT NULL,
  size_key VARCHAR(50) NOT NULL,
  rotation SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  h_flip TINYINT UNSIGNED NOT NULL DEFAULT 0,
  v_flip TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (folder_id, size_key)
) {$charset_collate};";
		dbDelta( $folder_sql );

		$exclusion_sql = "CREATE TABLE {$exclusion_table} (
  image_id VARCHAR(255) NOT NULL,
  folder_id VARCHAR(255) NOT NULL DEFAULT '',
  media_type VARCHAR(16) NOT NULL DEFAULT 'image',
  reasons VARCHAR(255) NOT NULL DEFAULT '',
  note TEXT NOT NULL,
  excluded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (image_id),
  KEY folder_id (folder_id)
) {$charset_collate};";
		dbDelta( $exclusion_sql );

		// MySQL clamped previously saved 270-degree values to TINYINT's maximum.
		// Since the API only accepts quarter turns, every stored 255 is a damaged 270.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$wpdb->update( $table, array( 'rotation' => 270 ), array( 'rotation' => 255 ), array( '%d' ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$wpdb->update(
			$folder_table,
			array( 'rotation' => 270 ),
			array( 'rotation' => 255 ),
			array( '%d' ),
			array( '%d' )
		);

		$legacy_lightbox_key = 's' . intval( get_option( 'avpvh_preview_size', 1920 ) );
		$migration_sql       = 'INSERT IGNORE INTO ' . $table . ' (image_id, size_key, rotation, h_flip, v_flip)' .
			' SELECT image_id, %s, rotation, h_flip, v_flip FROM ' . $table . ' WHERE size_key = %s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is concatenated (not user-supplied); %s placeholders above are filled via $wpdb->prepare() just below.
		$wpdb->query( $wpdb->prepare( $migration_sql, 'lightbox', $legacy_lightbox_key ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$wpdb->delete( $table, array( 'size_key' => $legacy_lightbox_key ), array( '%s' ) );
		update_option( 'avpvh_corrections_schema', self::SCHEMA_VERSION );
	}

	/**
	 * Drop the photo corrections table on plugin uninstall
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;
		$table           = $wpdb->prefix . 'agallery_photo_corrections';
		$folder_table    = $wpdb->prefix . 'agallery_folder_corrections';
		$exclusion_table = $wpdb->prefix . 'agallery_photo_exclusions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; uninstall-time schema drop of a custom plugin table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; uninstall-time schema drop of a custom plugin table.
		$wpdb->query( "DROP TABLE IF EXISTS {$folder_table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; uninstall-time schema drop of a custom plugin table.
		$wpdb->query( "DROP TABLE IF EXISTS {$exclusion_table}" );
		delete_option( 'avpvh_corrections_schema' );
	}

	/**
	 * Migrates the v1 schema (single thumb_rotation/light_rotation columns) to v2+
	 * (composite image_id/size_key rows), if the legacy column is still present.
	 *
	 * @param string $table The photo corrections table name.
	 * @param string $charset_collate The charset/collation clause used to (re)create the table.
	 *
	 * @return void
	 */
	private static function maybe_migrate_legacy_rotation_columns( $table, $charset_collate ) {
		if ( (int) get_option( 'avpvh_corrections_schema', 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time legacy schema check.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( ! $table_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time legacy schema check.
		$has_old = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'thumb_rotation'" );

		if ( ! $has_old ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$old_rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time legacy schema migration.
			"SELECT image_id, thumb_rotation, light_rotation FROM `{$table}`",
			ARRAY_A
		);
		$old_rows = is_array( $old_rows ) ? $old_rows : array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; one-time legacy schema migration.
		$wpdb->query( "DROP TABLE `{$table}`" );
		// Create new schema immediately so inserts below work.
		$sql = "CREATE TABLE {$table} (
  image_id VARCHAR(255) NOT NULL,
  size_key VARCHAR(50) NOT NULL,
  rotation SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  h_flip TINYINT UNSIGNED NOT NULL DEFAULT 0,
  v_flip TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (image_id, size_key)
) {$charset_collate};";
		dbDelta( $sql );

		self::migrate_legacy_rotation_rows( $table, $old_rows );
	}

	/**
	 * Inserts v2+ rotation rows derived from legacy v1 thumb_rotation/light_rotation values.
	 *
	 * @param string                      $table The photo corrections table name.
	 * @param array<array<string, mixed>> $old_rows The legacy rows fetched before the table was dropped.
	 *
	 * @return void
	 */
	private static function migrate_legacy_rotation_rows( $table, $old_rows ) {
		global $wpdb;

		foreach ( $old_rows as $row ) {
			if ( 0 !== intval( $row['thumb_rotation'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
				$wpdb->insert(
					$table,
					array(
						'image_id' => $row['image_id'],
						'rotation' => intval( $row['thumb_rotation'] ),
						'size_key' => 'grid',
					)
				);
			}

			if ( 0 === intval( $row['light_rotation'] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->insert(
				$table,
				array(
					'image_id' => $row['image_id'],
					'rotation' => intval( $row['light_rotation'] ),
					'size_key' => 'lightbox',
				)
			);
		}
	}
}
