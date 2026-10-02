<?php
/**
 * Contains the Members_API class for exposing member list via REST API.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

use Exception;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * REST API endpoint for fetching members for tagging.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Members_API {

	/**
	 * Initializes REST routes
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'avpvh/v1',
			'/members/for-tagging',
			array(
				'callback'            => array( $this, 'get_members_for_tagging' ),
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'check_can_tag' ),
			)
		);
	}

	/**
	 * Permission check: allow logged-in users (both active and ex-members)
	 *
	 * @return bool
	 */
	public function check_can_tag() {
		return is_user_logged_in();
	}

	/**
	 * Get members for tagging (active and inactive)
	 *
	 * @return WP_REST_Response
	 */
	public function get_members_for_tagging() {
		// avpvh-members' AVPVH_DB lives in the global namespace.
		if ( ! class_exists( '\\AVPVH_DB' ) ) {
			return new WP_REST_Response( array( 'data' => array() ), 200 );
		}

		try {
			// Use the AVPVH_DB from avpvh-members plugin.
			$members = call_user_func(
				array( '\\AVPVH_DB', 'get_members' ),
				array(
					'order'    => 'ASC',
					'orderby'  => 'last_name',
					'per_page' => 500,
				)
			);

			$result = array_map(
				static function ( $member ) {
					return array(
						'id'     => intval( $member->id ),
						'name'   => Person_Name::format( $member ),
						'status' => $member->status,
					);
				},
				$members
			);

			return new WP_REST_Response(
				array( 'data' => array_merge( $result, self::free_text_names() ) ),
				200
			);
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				array( 'message' => esc_html( $e->getMessage() ) ),
				500
			);
		}
	}

	/**
	 * Names people have tagged before that aren't members (id 0, status
	 * "guest"), so the same non-member can be found and tagged again
	 * without retyping — and without spelling them differently each time.
	 *
	 * @return array<array{id: int, name: string, status: string}>
	 */
	private static function free_text_names() {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_tags';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table, fixed name, no user input.
		$names = $wpdb->get_col(
			"SELECT DISTINCT member_name FROM {$table}
			 WHERE category = 'personen' AND member_id IS NULL AND member_name <> ''
			 ORDER BY member_name"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( $name ) {
				return array(
					'id'     => 0,
					'name'   => (string) $name,
					'status' => 'guest',
				);
			},
			$names
		);
	}
}
