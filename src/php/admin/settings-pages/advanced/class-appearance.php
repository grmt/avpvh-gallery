<?php
/**
 * Contains the Appearance settings section.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages\Advanced;

use Avpvh\Options;

/**
 * Registers icon and branding settings.
 */
final class Appearance {

	/**
	 * Registers the section hook.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_init', array( self::class, 'add_section' ) );
		}
	}

	/**
	 * Adds the section and its fields.
	 *
	 * @return void
	 */
	public static function add_section() {
		add_settings_section(
			'avpvh_appearance',
			esc_html__( 'Icons', 'avpvh-gallery' ),
			array( self::class, 'html' ),
			'avpvh_advanced'
		);
		Options::$asset_set->add_field();
	}

	/**
	 * Renders the section description.
	 *
	 * @return void
	 */
	public static function html() {
		echo '<p>' . esc_html__(
			'Choose the navigation, loading and site-branding icons used by the plugin.',
			'avpvh-gallery'
		) . '</p>';
	}
}
