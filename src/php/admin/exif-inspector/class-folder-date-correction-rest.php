<?php
/**
 * Contains the Folder_Date_Correction_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Frontend\Folder_Date_Correction;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API controller for a folder's EXIF date correction (see
 * Folder_Date_Correction), set in the EXIF Inspector.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Folder_Date_Correction_REST {

	/**
	 * Registers the REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/folder-date-correction',
			array(
				array(
					'callback'            => array( self::class, 'get_folder_date_correction' ),
					'methods'             => 'GET',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
				array(
					'callback'            => array( self::class, 'save_folder_date_correction' ),
					'methods'             => 'POST',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
			)
		);
	}

	/**
	 * Gets a folder's EXIF date correction: {mode: ''|'ignore'|'shift', offset_seconds}.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_folder_date_correction( $request ) {
		$folder_id = sanitize_text_field( (string) $request->get_param( 'folder_id' ) );

		if ( '' === $folder_id ) {
			return new WP_Error( 'invalid_folder', 'folder_id is required', array( 'status' => 400 ) );
		}

		$correction = Folder_Date_Correction::for_folder( $folder_id );

		return new WP_REST_Response(
			array(
				'mode'           => null === $correction ? '' : $correction['mode'],
				'offset_seconds' => null === $correction ? 0 : $correction['offset'],
			),
			200
		);
	}

	/**
	 * Sets a folder's EXIF date correction: mode 'ignore', 'shift' (with
	 * offset_seconds) or '' to use the EXIF date as it is.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_folder_date_correction( $request ) {
		$params    = $request->get_json_params();
		$folder_id = isset( $params['folder_id'] ) ? sanitize_text_field( $params['folder_id'] ) : '';
		$mode      = isset( $params['mode'] ) ? sanitize_key( $params['mode'] ) : '';
		// At most 50 years either way, which also keeps it within the INT column.
		$offset = max(
			-50 * YEAR_IN_SECONDS,
			min( 50 * YEAR_IN_SECONDS, isset( $params['offset_seconds'] ) ? intval( $params['offset_seconds'] ) : 0 )
		);

		if ( '' === $folder_id ) {
			return new WP_Error( 'invalid_folder', 'folder_id is required', array( 'status' => 400 ) );
		}

		if ( ! in_array( $mode, array( '', Folder_Date_Correction::IGNORE, Folder_Date_Correction::SHIFT ), true ) ) {
			return new WP_Error( 'invalid_mode', 'mode must be "", "ignore" or "shift"', array( 'status' => 400 ) );
		}

		if ( Folder_Date_Correction::SHIFT === $mode && 0 === $offset ) {
			$mode = '';
		}

		Folder_Date_Correction::save( $folder_id, $mode, $offset );

		return new WP_REST_Response(
			array(
				'mode'           => $mode,
				'offset_seconds' => Folder_Date_Correction::SHIFT === $mode ? $offset : 0,
			),
			200
		);
	}
}
