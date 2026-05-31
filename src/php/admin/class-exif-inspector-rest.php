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

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/full-exif',
			array(
				'callback'            => array( $this, 'get_full_exif' ),
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/file-size',
			array(
				'callback'            => array( $this, 'get_file_size' ),
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Gets the file size of a URL by doing a HEAD request server-side.
	 * This bypasses CORS restrictions that block client-side fetch.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_file_size( $request ) {
		$params = $request->get_json_params();
		$url    = isset( $params['url'] ) ? $params['url'] : '';

		if ( '' === $url ) {
			return new WP_Error( 'invalid_url', 'URL is required', array( 'status' => 400 ) );
		}

		// Validate URL is from Google
		if ( false === strpos( $url, 'googleusercontent.com' ) && false === strpos( $url, 'drive.google.com' ) ) {
			return new WP_Error( 'invalid_url', 'Invalid URL', array( 'status' => 400 ) );
		}

		// Try HEAD request first
		$response = wp_remote_head(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Failed to fetch URL: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$content_length = wp_remote_retrieve_header( $response, 'content-length' );
		$status_code    = wp_remote_retrieve_response_code( $response );

		// If HEAD didn't return content-length, try GET
		if ( empty( $content_length ) ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'   => 15,
					'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
					'redirection' => 5,
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'request_failed', 'Failed to fetch URL: ' . $response->get_error_message(), array( 'status' => 500 ) );
			}

			$content_length = wp_remote_retrieve_header( $response, 'content-length' );
			$status_code    = wp_remote_retrieve_response_code( $response );

			// If still no content-length, get body size
			if ( empty( $content_length ) ) {
				$body           = wp_remote_retrieve_body( $response );
				$content_length = strlen( $body );
			}
		}

		return new WP_REST_Response(
			array(
				'size'        => (int) $content_length,
				'status_code' => $status_code,
			),
			200
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

			error_log( '[EXIF Inspector] list_files called: parent_id=' . $parent_id );

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
					'mimeType',
					'name',
					'size',
					'thumbnailLink',
					'webContentLink',
				)
			);

			$files = API_Client::execute(
				array( API_Facade::list_images( $parent_id, $fields, $pagination_helper, 'name' ) )
			)[0];

			error_log( '[EXIF Inspector] Found ' . count( $files ) . ' files in folder ' . $parent_id );

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
						'time',
						'width',
					),
					'mimeType',
					'name',
					'size',
					'thumbnailLink',
					'webContentLink',
				)
			);

			$file = API_Client::execute(
				array( API_Facade::get_file( $file_id, $fields ) )
			)[0];

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
	 * Gets full EXIF data from a downloaded image file.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_full_exif( $request ) {
		try {
			$params           = $request->get_json_params();
			$file_id          = isset( $params['file_id'] ) ? $params['file_id'] : '';
			$download_link    = isset( $params['download_link'] ) ? $params['download_link'] : '';

			if ( ! isset( $file_id ) || '' === $file_id ) {
				return new WP_Error( 'invalid_file', 'File ID is required', array( 'status' => 400 ) );
			}

			if ( ! isset( $download_link ) || '' === $download_link ) {
				return new WP_Error( 'no_download_link', 'Download link is required', array( 'status' => 400 ) );
			}

			// Validate the download link is from Google Drive
			if ( false === strpos( $download_link, 'drive.google.com' ) && false === strpos( $download_link, 'googleusercontent.com' ) ) {
				return new WP_Error( 'invalid_download_link', 'Invalid download link', array( 'status' => 400 ) );
			}

			// Download the file to a temporary location
			$temp_file = wp_tempnam( 'exif-' . sanitize_file_name( $file_id ) . '-' );
			if ( ! $temp_file ) {
				return new WP_Error( 'temp_file_error', 'Unable to create temporary file', array( 'status' => 500 ) );
			}

			error_log( '[EXIF Inspector] Downloading file: ' . $download_link );

			// Download the file
			$response = wp_remote_get(
				$download_link,
				array(
					'timeout'    => 30,
					'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
					'stream'     => true,
					'filename'   => $temp_file,
				)
			);

			if ( is_wp_error( $response ) ) {
				@unlink( $temp_file );
				return new WP_Error( 'download_error', 'Failed to download file: ' . $response->get_error_message(), array( 'status' => 500 ) );
			}

			// Extract EXIF data
			if ( ! function_exists( 'exif_read_data' ) ) {
				@unlink( $temp_file );
				return new WP_Error( 'no_exif_support', 'EXIF support is not available in PHP', array( 'status' => 500 ) );
			}

			$exif_data = @exif_read_data( $temp_file, 0, true );
			@unlink( $temp_file );

			if ( ! $exif_data ) {
				return new WP_REST_Response( array( 'exif' => array() ), 200 );
			}

			// Flatten and sanitize EXIF data
			$flat_exif = array();
			foreach ( $exif_data as $section => $data ) {
				if ( is_array( $data ) ) {
					foreach ( $data as $key => $value ) {
						// Skip binary data
						if ( is_string( $value ) && strlen( $value ) > 500 ) {
							continue;
						}
						// Ensure value is string or number
						if ( is_array( $value ) || is_object( $value ) ) {
							continue;
						}
						$flat_exif[ $section . ':' . $key ] = $value;
					}
				}
			}

			return new WP_REST_Response( array( 'exif' => $flat_exif ), 200 );
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( Not_Found_Exception $e ) {
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'not_found', 'File not found', array( 'status' => 404 ) );
		} catch ( API_Exception $e ) {
			error_log( '[EXIF Inspector] API exception in get_full_exif: ' . $e->getMessage() );
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		} catch ( \Exception $e ) {
			error_log( '[EXIF Inspector] Unexpected exception in get_full_exif: ' . $e->getMessage() );
			return new WP_Error( 'unexpected_error', 'Unexpected error: ' . $e->getMessage(), array( 'status' => 500 ) );
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
