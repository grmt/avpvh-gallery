<?php
/**
 * Contains the Exif_Inspector_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin;

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Exceptions\API_Exception;
use Avpvh\Exceptions\Directory_Not_Found_Exception;
use Avpvh\Exceptions\Not_Found_Exception;
use Avpvh\Exceptions\Plugin_Not_Authorized_Exception;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Single_Page_Pagination_Helper;
use Avpvh\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API controller for the EXIF inspector.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Exif_Inspector_REST {

	/**
	 * Registers the REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/list-folders',
			array(
				'callback'            => array( $this, 'list_folders' ),
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/list-files',
			array(
				'callback'            => array( $this, 'list_files' ),
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/file-data',
			array(
				'callback'            => array( $this, 'file_data' ),
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Gets a folder ID by parent and folder name.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_folders( $request ) {
		try {
			$params      = $request->get_json_params();
			$parent_id   = isset( $params['parent_id'] ) ? $params['parent_id'] : '';
			$folder_name = isset( $params['folder_name'] ) ? $params['folder_name'] : '';

			error_log( '[EXIF Inspector] list_folders called: parent_id=' . $parent_id . ', folder_name=' . $folder_name );

			if ( ! isset( $parent_id ) || '' === $parent_id ) {
				$parent_id = end( Options::$root_path->get() );
				error_log( '[EXIF Inspector] Using root path: ' . $parent_id );
			}

			if ( ! isset( $folder_name ) || '' === $folder_name ) {
				error_log( '[EXIF Inspector] Folder name is required' );
				return new WP_Error( 'invalid_folder_name', 'Folder name is required', array( 'status' => 400 ) );
			}

			error_log( '[EXIF Inspector] Searching for folder "' . $folder_name . '" in parent "' . $parent_id . '"' );

			$folder_id = API_Client::execute(
				array( API_Facade::get_directory_id( $parent_id, $folder_name ) )
			)[0];

			error_log( '[EXIF Inspector] Found folder ID: ' . $folder_id );

			return new WP_REST_Response( array( 'folder_id' => $folder_id ), 200 );
		} catch ( Directory_Not_Found_Exception $e ) {
			error_log( '[EXIF Inspector] Directory not found exception: ' . $e->getMessage() );
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'folder_not_found', 'Folder not found: ' . $e->getMessage(), array( 'status' => 404 ) );
		} catch ( Not_Found_Exception $e ) {
			error_log( '[EXIF Inspector] Not found exception: ' . $e->getMessage() );
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'folder_not_found', 'Folder not found: ' . $e->getMessage(), array( 'status' => 404 ) );
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			error_log( '[EXIF Inspector] Not authorized' );
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( API_Exception $e ) {
			error_log( '[EXIF Inspector] API exception: ' . $e->getMessage() );
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Lists files in a directory.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( $request ) {
		try {
			$params    = $request->get_json_params();
			$parent_id = isset( $params['parent_id'] ) ? $params['parent_id'] : '';

			if ( ! isset( $parent_id ) || '' === $parent_id ) {
				return new WP_Error( 'invalid_parent', 'Parent ID is required', array( 'status' => 400 ) );
			}

			$pagination_helper = new Single_Page_Pagination_Helper();
			$fields            = new API_Fields(
				array(
					'createdTime',
					'description',
					'id',
					'imageMediaMetadata' => array(
						'aperture',
						'cameraMake',
						'cameraModel',
						'exposureTime',
						'focalLength',
						'height',
						'isoSpeed',
						'rotation',
						'width',
					),
					'name',
					'thumbnailLink',
				)
			);

			$files = API_Client::execute(
				API_Facade::list_images( $parent_id, $fields, $pagination_helper, 'name' )
			);

			return new WP_REST_Response( array( 'files' => $files ), 200 );
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( API_Exception $e ) {
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Gets file data and EXIF information.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function file_data( $request ) {
		try {
			$params  = $request->get_json_params();
			$file_id = isset( $params['file_id'] ) ? $params['file_id'] : '';

			if ( ! isset( $file_id ) || '' === $file_id ) {
				return new WP_Error( 'invalid_file', 'File ID is required', array( 'status' => 400 ) );
			}

			$fields = new API_Fields(
				array(
					'createdTime',
					'description',
					'id',
					'imageMediaMetadata' => array(
						'aperture',
						'cameraMake',
						'cameraModel',
						'exposureTime',
						'focalLength',
						'height',
						'isoSpeed',
						'rotation',
						'width',
					),
					'name',
					'thumbnailLink',
				)
			);

			$file = API_Client::execute(
				API_Facade::get_file( $file_id, $fields )
			);

			return new WP_REST_Response( array( 'file' => $file ), 200 );
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( Not_Found_Exception $e ) {
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'not_found', 'File not found', array( 'status' => 404 ) );
		} catch ( API_Exception $e ) {
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Checks if the current user has admin permission.
	 *
	 * @return bool
	 */
	public static function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}
}
