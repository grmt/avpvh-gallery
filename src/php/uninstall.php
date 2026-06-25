<?php
/**
 * Plugin uninstallation file.
 *
 * Deletes all the plugin options so that the database is clean after uninstall.
 *
 * @package avpvh-gallery
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die( 'Die, die, die!' );
}

require_once __DIR__ . '/class-photo-corrections-db.php';
\Avpvh\Photo_Corrections_DB::drop_tables();

delete_option( 'avpvh_client_id' );
delete_option( 'avpvh_client_secret' );
delete_option( 'avpvh_access_token' );

delete_option( 'avpvh_root_path' );

delete_option( 'avpvh_grid_height' );
delete_option( 'avpvh_grid_spacing' );
delete_option( 'avpvh_dir_title_size' );
delete_option( 'avpvh_dir_counts' );
delete_option( 'avpvh_page_size' );
delete_option( 'avpvh_page_autoload' );
delete_option( 'avpvh_image_ordering_order' );
delete_option( 'avpvh_image_ordering_by' );
delete_option( 'avpvh_dir_ordering_order' );
delete_option( 'avpvh_dir_ordering_by' );
delete_option( 'avpvh_dir_prefix' );

delete_option( 'avpvh_preview_size' );
delete_option( 'avpvh_preview_speed' );
delete_option( 'avpvh_preview_arrows' );
delete_option( 'avpvh_preview_closebutton' );
delete_option( 'avpvh_preview_loop' );
delete_option( 'avpvh_preview_activity' );
delete_option( 'avpvh_preview_captions' );

delete_option( 'avpvh_exif_inspector_last_path' );

// Deprecated.
delete_option( 'avpvh_image_ordering' );
delete_option( 'avpvh_thumbnail_size' );
delete_option( 'avpvh_thumbnail_size_value' );
delete_option( 'avpvh_thumbnail_size_unit' );
delete_option( 'avpvh_thumbnail_spacing' );
delete_option( 'avpvh_date_ordering_order' );
delete_option( 'avpvh_date_ordering_by' );
delete_option( 'avpvh_grid_mode' );
delete_option( 'avpvh_grid_width' );
delete_option( 'avpvh_grid_columns' );
delete_option( 'avpvh_grid_min_width' );
