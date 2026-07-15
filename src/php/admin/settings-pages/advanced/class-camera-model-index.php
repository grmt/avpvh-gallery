<?php
/**
 * Camera model index settings section.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages\Advanced;

/**
 * Renders and wires the persistent Drive camera-model index controls.
 */
final class Camera_Model_Index {

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
			'avpvh_camera_model_index',
			esc_html__( 'Camera model index', 'avpvh-gallery' ),
			array( self::class, 'html' ),
			'avpvh_advanced'
		);
	}

	/**
	 * Loads the small scan controller only on the advanced settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_script( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'avpvh_advanced' ) ) {
			return;
		}

		$path = plugin_dir_path( __FILE__ ) . '../../../admin/js/camera-model-index.min.js';
		wp_enqueue_script(
			'avpvh-camera-model-index',
			plugin_dir_url( __FILE__ ) . '../../../admin/js/camera-model-index.min.js',
			array(),
			(int) filemtime( $path ),
			true
		);
		wp_localize_script(
			'avpvh-camera-model-index',
			'avpvhCameraModelIndex',
			array(
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'rest_url' => rest_url( 'avpvh-gallery/v1/exif-inspector/model-index/' ),
			)
		);
	}

	/**
	 * Renders scan status and controls.
	 *
	 * @return void
	 */
	public static function html() {
		echo '<p>' . esc_html__(
			// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
			'Build a reusable camera-model index for the gallery root and all subfolders. The EXIF Inspector uses it without searching Google Drive again.',
			'avpvh-gallery'
		) . '</p>';
		echo '<div id="avpvh-camera-model-index">';
		echo '<p id="avpvh-camera-model-status">' .
			esc_html__( 'Loading index status…', 'avpvh-gallery' ) .
			'</p>';
		echo '<button id="avpvh-camera-model-start" class="button button-secondary" type="button" disabled>' .
			esc_html__(
				'Update camera model index',
				'avpvh-gallery'
			) . '</button>';
		echo '</div>';
	}
}
