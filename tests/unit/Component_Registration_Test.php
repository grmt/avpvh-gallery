<?php
/**
 * Every component hooks itself in through register().
 *
 * @package avpvh-gallery
 */

use Avpvh\Admin\Settings_Pages;
use Avpvh\Main;

/**
 * Runs the plugin's register() chain (normally done once while WordPress
 * loads, before any test) and checks a hook from each layer.
 */
final class Component_Registration_Test extends WP_UnitTestCase {

	/**
	 * The front-end chain: Main registers the shortcode, proxies, tagging,
	 * filtering (and the sharing it owns) and the REST controllers.
	 *
	 * @return void
	 */
	public function test_main_registers_front_end_components() {
		( new Main() )->register();

		$this->assert_hooked( 'wp_ajax_gallery_marks' );
		$this->assert_hooked( 'wp_ajax_gallery_filter' );
		$this->assert_hooked( 'rest_api_init' );
		$this->assertSame( true, shortcode_exists( 'avpvh' ) );
	}

	/**
	 * In wp-admin the settings pages also register their own pages.
	 *
	 * @return void
	 */
	public function test_settings_pages_register_admin_pages() {
		set_current_screen( 'dashboard' );

		try {
			( new Settings_Pages() )->register();
		} finally {
			// Back to the front end, so later tests don't run "in wp-admin".
			set_current_screen( 'front' );
		}

		$this->assert_hooked( 'admin_menu' );
		$this->assert_hooked( 'admin_init' );
	}

	/**
	 * Fails unless something is hooked to the action.
	 *
	 * @param string $hook_name The action.
	 * @return void
	 */
	private function assert_hooked( $hook_name ) {
		$this->assertSame( true, false !== has_action( $hook_name ), $hook_name . ' has no callback' );
	}
}
