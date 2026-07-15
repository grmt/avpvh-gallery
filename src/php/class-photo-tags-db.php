<?php
/**
 * Contains database migration functions for photo tagging tables.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

/**
 * Photo Tags Database Migration
 */
final class Photo_Tags_DB {

	/**
	 * Create tables for photo tagging feature
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Table 1: Photo tags (annotations)
		$table_tags = $wpdb->prefix . 'agallery_photo_tags';
		$sql_tags = "CREATE TABLE {$table_tags} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			image_id VARCHAR(255) NOT NULL,
			member_id BIGINT UNSIGNED,
			member_name VARCHAR(255),
			region_data JSON,
			created_by BIGINT UNSIGNED,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_image_id (image_id),
			INDEX idx_member_id (member_id),
			FOREIGN KEY (member_id) REFERENCES {$wpdb->prefix}avm_members (id) ON DELETE CASCADE
		) {$charset_collate};";

		// Table 2: Tag comments
		$table_comments = $wpdb->prefix . 'agallery_tag_comments';
		$sql_comments = "CREATE TABLE {$table_comments} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			tag_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED,
			comment_text LONGTEXT,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_tag_id (tag_id),
			FOREIGN KEY (tag_id) REFERENCES {$table_tags} (id) ON DELETE CASCADE
		) {$charset_collate};";

		// Table 3: Emoji reactions
		$table_reactions = $wpdb->prefix . 'agallery_reactions';
		$sql_reactions = "CREATE TABLE {$table_reactions} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			tag_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			emoji VARCHAR(10),
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY unique_reaction (tag_id, user_id, emoji),
			INDEX idx_tag_id (tag_id),
			FOREIGN KEY (tag_id) REFERENCES {$table_tags} (id) ON DELETE CASCADE
		) {$charset_collate};";

		dbDelta( $sql_tags );
		dbDelta( $sql_comments );
		dbDelta( $sql_reactions );
	}

	/**
	 * Drop tables on plugin uninstall
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'agallery_reactions',
			$wpdb->prefix . 'agallery_tag_comments',
			$wpdb->prefix . 'agallery_photo_tags',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
