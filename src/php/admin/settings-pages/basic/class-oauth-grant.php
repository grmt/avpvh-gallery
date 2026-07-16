<?php
/**
 * Contains the OAuth_Grant class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages\Basic;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Options;

/**
 * Registers and renders the OAuth granting settings section.
 *
 * @phan-constructor-used-for-side-effects
 */
final class OAuth_Grant {

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
			'avpvh_auth',
			esc_html__( 'Step 1: Authorization', 'avpvh-gallery' ),
			array( self::class, 'html' ),
			'avpvh_basic'
		);
		Options::$authorized_domain->add_field();
		Options::$authorized_origin->add_field();
		Options::$redirect_uri->add_field();
		Options::$client_id->add_field();
		Options::$client_secret->add_field();
	}

	/**
	 * Renders the header for the section.
	 *
	 * @return void
	 */
	public static function html() {
		echo '<p>' .
			esc_html__( 'Create a Google app and provide the following details:', 'avpvh-gallery' ) .
			'</p>';
		echo '<a class="button button-primary" href="' .
			esc_url_raw( wp_nonce_url( admin_url( 'admin.php?page=avpvh_basic&action=oauth_grant' ), 'oauth_grant' ) ) .
			'">' .
			esc_html__( 'Grant Permission', 'avpvh-gallery' ) .
			'</a>';
	}
}
