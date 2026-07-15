<?php
/**
 * Contains database migration functions for the folder-authorship table.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

/**
 * Folder Authors Database Migration
 */
final class Folder_Authors_DB {

	private const SCHEMA_VERSION = 1;

	/**
	 * Runs schema migration if needed; hooked to init.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( 'avpvh_folder_authors_schema', 0 ) < self::SCHEMA_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Creates the folder-authors table.
	 *
	 * A folder with no rows here inherits its effective author(s) from the nearest ancestor
	 * that does have rows (walking up; defaults to AVPvH if no ancestor has any). A folder
	 * with rows has those rows as its own explicit authors, which also become the default
	 * for its descendants (unless a descendant has its own rows).
	 *
	 * member_id = 0 is a reserved sentinel meaning "explicitly AVPvH, stop inheriting" (a
	 * folder can have that single sentinel row instead of real member rows). There is no
	 * foreign key to the members plugin's table: member IDs are only meaningful if that
	 * plugin is active, and this table must still work when it isn't.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'agallery_folder_authors';

		$sql = "CREATE TABLE {$table} (
  folder_id VARCHAR(255) NOT NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (folder_id, member_id),
  KEY member_id (member_id)
) {$charset_collate};";
		dbDelta( $sql );

		update_option( 'avpvh_folder_authors_schema', self::SCHEMA_VERSION );
	}

	/**
	 * Drop the folder-authors table on plugin uninstall.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_folder_authors';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; uninstall-time schema drop of a custom plugin table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( 'avpvh_folder_authors_schema' );
	}
}
