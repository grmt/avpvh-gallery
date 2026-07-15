<?php
/**
 * Folder authorship settings section.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages\Advanced;

/**
 * Renders and wires the folder → author(s) attribution table.
 */
final class Folder_Authors {

	/**
	 * Registers settings and script hooks.
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', array( self::class, 'add_section' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_script' ) );
	}

	/**
	 * Adds the settings section.
	 *
	 * @return void
	 */
	public static function add_section() {
		add_settings_section(
			'avpvh_folder_authors',
			esc_html__( 'Folder authors', 'avpvh-gallery' ),
			array( self::class, 'html' ),
			'avpvh_advanced'
		);
	}

	/**
	 * Loads the table controller only on the advanced settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_script( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'avpvh_advanced' ) ) {
			return;
		}

		$path = plugin_dir_path( __FILE__ ) . '../../../admin/js/folder-authors.min.js';
		wp_enqueue_style(
			'avpvh-folder-authors',
			plugin_dir_url( __FILE__ ) . '../../../admin/css/folder-authors.min.css',
			array(),
			(int) filemtime( plugin_dir_path( __FILE__ ) . '../../../admin/css/folder-authors.min.css' )
		);
		wp_enqueue_script(
			'avpvh-folder-authors',
			plugin_dir_url( __FILE__ ) . '../../../admin/js/folder-authors.min.js',
			array(),
			(int) filemtime( $path ),
			true
		);
		wp_localize_script(
			'avpvh-folder-authors',
			'avpvhFolderAuthors',
			array(
				'folder_authors_url' => rest_url( 'avpvh-gallery/v1/exif-inspector/folder-authors' ),
				'members_url'        => rest_url( 'avpvh/v1/members/for-tagging' ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Renders the table container.
	 *
	 * @return void
	 */
	public static function html() {
		echo '<p>' . esc_html__(
			// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
			'Assign one or more members as the author(s) of a folder. A folder with no explicit author is attributed to AVPvH by default, and inherits its parent folder\'s assignment once one is set.',
			'avpvh-gallery'
		) . '</p>';
		echo '<div id="avpvh-folder-authors">';
		echo '<p id="avpvh-folder-authors-status">' .
			esc_html__( 'Loading folder authors…', 'avpvh-gallery' ) .
			'</p>';
		echo '</div>';
	}
}
