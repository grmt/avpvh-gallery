<?php
/**
 * Contains the Gallery_Access class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use AVPVH_Archive_Access;

/**
 * Who may use the gallery's front end: administrators, and whoever
 * avpvh-members lets see every blog post (active members and editors,
 * AVPVH_Archive_Access::ALL). Visitor accounts, logins without a member
 * record and ex-members do not -- not even to a folder of their own years
 * (that needs a per-folder check this gate does not do yet).
 *
 * The AJAX gate runs before every front-end AJAX action of the gallery, so
 * a new action cannot forget the check; the front-end REST routes use
 * check() as their permission_callback. Without avpvh-members any
 * logged-in user may, as before.
 */
final class Gallery_Access {

	/**
	 * Hooks the AJAX gate in: admin-ajax.php fires admin_init before the
	 * action's handler.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( self::class, 'gate_ajax' ), 0 );
	}

	/**
	 * Whether the current user may use the gallery.
	 *
	 * @return bool
	 */
	public static function check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! class_exists( AVPVH_Archive_Access::class ) ) {
			return is_user_logged_in();
		}

		return is_user_logged_in() && AVPVH_Archive_Access::ALL === AVPVH_Archive_Access::current_scope();
	}

	/**
	 * The status for a refusal: 401 asks to log in, 403 does not help.
	 *
	 * @return int
	 */
	public static function refusal_status() {
		return is_user_logged_in() ? 403 : 401;
	}

	/**
	 * The message for a refusal.
	 *
	 * @return string
	 */
	public static function refusal_message() {
		return is_user_logged_in()
			? __( "De foto's zijn alleen voor leden.", 'avpvh-gallery' )
			: __( "Log in om de foto's te bekijken.", 'avpvh-gallery' );
	}

	/**
	 * Refuses a gallery AJAX action before its handler runs.
	 *
	 * @return void
	 */
	public static function gate_ajax() {
		if ( ! defined( 'DOING_AJAX' ) || true !== constant( 'DOING_AJAX' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only compared against a fixed list
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, self::ajax_actions(), true ) || self::check() ) {
			return;
		}

		wp_send_json( array( 'error' => self::refusal_message() ), self::refusal_status() );
	}

	/**
	 * The gallery's front-end AJAX actions. Admin-only ones (list_gallery_dir,
	 * list_gdrive_dir) check their own capability.
	 *
	 * @return array<string>
	 */
	private static function ajax_actions() {
		return array(
			'gallery',
			'gallery_comment_add',
			'gallery_comment_list',
			'gallery_dig_captions',
			'gallery_filter',
			'gallery_filter_options',
			'gallery_filter_preset_delete',
			'gallery_filter_preset_save',
			'gallery_filter_recipients',
			'gallery_filter_save',
			'gallery_filter_share',
			'gallery_mark',
			'gallery_marks',
			'gallery_reaction_add',
			'gallery_reaction_list',
			'gallery_share_create',
			'gallery_tag_add',
			'gallery_tag_candidates',
			'gallery_tag_delete',
			'gallery_tag_list',
			'nieuwsbrief_image',
			'nieuwsbrief_pdf',
			'page',
			'video_proxy',
		);
	}
}
