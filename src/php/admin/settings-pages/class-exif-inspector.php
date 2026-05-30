<?php
/**
 * Contains the Exif_Inspector class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages;

use Avpvh\Options;

/**
 * EXIF inspector admin page.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Exif_Inspector {

	/**
	 * Registers the EXIF inspector page.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Registers the admin page.
	 *
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			'avpvh_basic',
			esc_html__( 'EXIF Inspector', 'avpvh-gallery' ),
			esc_html__( 'EXIF Inspector', 'avpvh-gallery' ),
			'manage_options',
			'avpvh_exif_inspector',
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues scripts and styles for the EXIF inspector page.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_script(
			'avpvh-exif-inspector',
			plugin_dir_url( __FILE__ ) . '../../admin/js/exif-inspector.min.js',
			array(),
			(int) filemtime(
				plugin_dir_path( __FILE__ ) . '../../admin/js/exif-inspector.min.js'
			),
			true
		);

		wp_localize_script(
			'avpvh-exif-inspector',
			'avpvhExifInspector',
			array(
				'rest_url' => rest_url( 'avpvh-gallery/v1/exif-inspector/' ),
				'root_id'  => Options::$root_path->get()[0],
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Renders the EXIF inspector page.
	 *
	 * @return void
	 */
	public static function render() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EXIF Inspector', 'avpvh-gallery' ); ?></h1>
			<div id="avpvh-exif-inspector-root"></div>
		</div>
		<?php
	}
}
