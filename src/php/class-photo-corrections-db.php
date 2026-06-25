<?php
/**
 * Contains database migration functions for photo corrections table.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

/**
 * Photo Corrections Database Migration
 */
final class Photo_Corrections_DB {

	/**
	 * Create the photo corrections table
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'agallery_photo_corrections';

		$sql = "CREATE TABLE {$table} (
			image_id VARCHAR(255) NOT NULL,
			thumb_rotation TINYINT UNSIGNED NOT NULL DEFAULT 0,
			light_rotation TINYINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (image_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Drop the photo corrections table on plugin uninstall
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_corrections';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
