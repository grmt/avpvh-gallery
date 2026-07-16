<?php
/**
 * Contains the Lightbox class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages\Advanced;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Options;

/**
 * Registers and renders the lightbox settings section.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Lightbox {

	/**
	 * Register all the hooks for this section.
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', array( self::class, 'add_section' ) );
	}

	/**
	 * Adds the settings section and all the fields in it.
	 *
	 * @return void
	 */
	public static function add_section() {
		add_settings_section(
			'avpvh_lightbox',
			esc_html__( 'Image popup', 'avpvh-gallery' ),
			array( self::class, 'html' ),
			'avpvh_advanced'
		);
		Options::$preview_size->add_field();
		Options::$preview_speed->add_field();
		Options::$preview_arrows->add_field();
		Options::$preview_close_button->add_field();
		Options::$preview_loop->add_field();
		Options::$preview_activity_indicator->add_field();
		Options::$preview_captions->add_field();
	}

	/**
	 * Renders the header for the section.
	 *
	 * @return void
	 */
	public static function html() {
		// No header.
	}
}
