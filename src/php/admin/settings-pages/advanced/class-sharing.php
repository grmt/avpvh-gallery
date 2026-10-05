<?php
/**
 * Contains the Sharing class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages\Advanced;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Options;

/**
 * Registers and renders the settings for sharing photo selections via
 * Google Drive (see Photo_Shares and Share_Drive).
 *
 * @phan-constructor-used-for-side-effects
 */
final class Sharing {

	/**
	 * Register all the hooks for the section.
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
			'avpvh_sharing',
			esc_html__( 'Sharing selections via Google Drive', 'avpvh-gallery' ),
			array( self::class, 'html' ),
			'avpvh_advanced'
		);
		Options::$share_service_account->add_field();
		Options::$share_folder->add_field();
	}

	/**
	 * Renders the header for the section.
	 *
	 * @return void
	 */
	public static function html() {
		$text = array(
			__( 'Members can copy the photos a filter finds into a temporary folder.', 'avpvh-gallery' ),
			__( 'Only their own Google account may open it, and it is removed after a week.', 'avpvh-gallery' ),
			__( 'A service account makes them; it must be able to read the gallery\'s photos', 'avpvh-gallery' ),
			__( 'and add files to the folder below (e.g. as content manager).', 'avpvh-gallery' ),
		);

		echo '<p>' . esc_html( implode( ' ', $text ) ) . '</p>';
	}
}
