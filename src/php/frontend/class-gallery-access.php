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

use WP_Error;

/**
 * Who may use the gallery's front end: administrators, and whoever
 * avpvh-members lets see every blog post (active members and editors,
 * AVPVH_Archive_Access::ALL). Visitor accounts, logins without a member
 * record and ex-members do not -- not even to a folder of their own years
 * (that needs a per-folder check this gate does not do yet).
 *
 * One gate in front of every front-end AJAX action and REST route of the
 * gallery, so a new endpoint cannot forget the check. Without avpvh-members
 * any logged-in user may, as before.
 */
final class Gallery_Access {

	/**
	 * The gallery's front-end AJAX actions. Admin-only ones (list_gallery_dir,
	 * list_gdrive_dir) check their own capability.
	 */
	const AJAX_ACTIONS = array(
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

	/** The gallery's front-end REST routes. */
	const REST_ROUTES = '#^/(?:avpvh-gallery/v1/(?:subject-tags|photo-place|exif-date)|avpvh/v1/members/for-tagging)(?:/|$)#';

	/**
	 * Hooks the gate in: before any handler runs.
	 */
	public function __construct() {
		add_action( 'admin_init', array( self::class, 'gate_ajax' ), 0 );
		add_filter( 'rest_pre_dispatch', array( self::class, 'gate_rest' ), 10, 3 );
	}

	/**
	 * Whether the current user may use the gallery.
	 *
	 * @return bool
	 */
	public static function check() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( class_exists( '\\AVPVH_Archive_Access' ) ) {
			return \AVPVH_Archive_Access::ALL === \AVPVH_Archive_Access::current_scope();
		}
		if ( function_exists( 'avpvh_get_member_by_wp_user' ) ) {
			// avpvh-members without AVPVH_Archive_Access (older): active members.
			$member = avpvh_get_member_by_wp_user( get_current_user_id() );
			return current_user_can( 'edit_pages' ) || ( is_object( $member ) && 'active' === $member->status );
		}
		return true;
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
	 * Refuses a gallery AJAX action before its handler runs (admin-ajax.php
	 * fires admin_init first).
	 *
	 * @return void
	 */
	public static function gate_ajax() {
		if ( ! wp_doing_ajax() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared against a fixed list
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! in_array( $action, self::AJAX_ACTIONS, true ) || self::check() ) {
			return;
		}
		wp_send_json( array( 'error' => self::refusal_message() ), self::refusal_status() );
	}

	/**
	 * Refuses a gallery REST route before its permission callback.
	 *
	 * @param mixed            $result  The response so far (null to go on).
	 * @param \WP_REST_Server  $server  The server.
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return mixed
	 */
	public static function gate_rest( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || 1 !== preg_match( self::REST_ROUTES, $request->get_route() ) || self::check() ) {
			return $result;
		}
		return new WP_Error( 'avpvh_gallery_members_only', self::refusal_message(), array( 'status' => self::refusal_status() ) );
	}
}
