<?php
/**
 * Contains the Members_API class for exposing member list via REST API.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

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
				'methods' => 'GET',
				'callback' => array( $this, 'get_members_for_tagging' ),
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
	 * @return \WP_REST_Response
	 */
	public function get_members_for_tagging() {
		// Check if avpvh-members plugin is active
		if ( ! class_exists( '\\AVPVH\\AVPVH_DB' ) ) {
			return new \WP_REST_Response(
				array(
					'data' => array(),
					'message' => esc_html__( 'avpvh-members plugin not found', 'avpvh-gallery' ),
				),
				503
			);
		}

		try {
			// Use the AVPVH_DB from avpvh-members plugin
			$members = call_user_func( array( '\\AVPVH\\AVPVH_DB', 'get_members' ), array(
				'orderby' => 'last_name',
				'order' => 'ASC',
				'per_page' => 500,
			) );

			$result = array_map(
				function( $m ) {
					return array(
						'id' => intval( $m->id ),
						'name' => $m->first_name . ' ' . $m->last_name,
						'status' => $m->status,
					);
				},
				$members
			);

			return new \WP_REST_Response(
				array( 'data' => $result ),
				200
			);
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array( 'message' => esc_html( $e->getMessage() ) ),
				500
			);
		}
	}
}
