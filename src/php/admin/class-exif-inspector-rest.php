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
			'exif-inspector/proxy-image',
			array(
				'callback'            => array( $this, 'proxy_image' ),
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'url' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/download-original',
			array(
				'callback'            => array( $this, 'download_original' ),
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'file_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/corrections',
			array(
				array(
					'callback'            => array( $this, 'get_corrections' ),
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'file_id' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
				array(
					'callback'            => array( $this, 'save_corrections' ),
					'methods'             => 'POST',
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * Streams the original file from Google Drive via the authorized API client.
	 * The public download URL requires Google sign-in; this uses the plugin's
	 * OAuth token to fetch the file content server-side.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_Error|void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public function download_original( $request ) {
		$file_id = $request->get_param( 'file_id' );

		if ( empty( $file_id ) ) {
			return new WP_Error( 'invalid_file_id', 'File ID is required', array( 'status' => 400 ) );
		}

		try {
			$http = API_Client::get_authorized_raw_client()->authorize();

			// Get file metadata for filename, mime type, and size
			$meta_response = $http->request(
				'GET',
				'drive/v3/files/' . $file_id,
				array(
					'query' => array(
						'fields'            => 'name,mimeType,size',
						'supportsAllDrives' => 'true',
					),
				)
			);
			$meta          = json_decode( $meta_response->getBody()->getContents(), true );

			$filename  = isset( $meta['name'] ) ? $meta['name'] : 'download';
			$mime_type = isset( $meta['mimeType'] ) ? $meta['mimeType'] : 'application/octet-stream';
			$size      = isset( $meta['size'] ) ? (int) $meta['size'] : 0;

			// Stream the file content
			$response = $http->request(
				'GET',
				'drive/v3/files/' . $file_id,
				array(
					'query'  => array(
						'alt'               => 'media',
						'supportsAllDrives' => 'true',
					),
					'stream' => true,
				)
			);
			$stream   = $response->getBody()->detach();

			if ( is_null( $stream ) ) {
				return new WP_Error( 'stream_error', 'Failed to open stream', array( 'status' => 500 ) );
			}

			header( 'Content-Type: ' . $mime_type );
			header( 'Content-Disposition: attachment; filename="' . addslashes( $filename ) . '"' );
			if ( $size > 0 ) {
				header( 'Content-Length: ' . $size );
			}

			ob_end_clean();
			fpassthru( $stream );
			exit;
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'download_error', 'Download failed: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Proxies an image from Google Drive through the server.
	 * Returns the actual image bytes with proper Content-Length header,
	 * bypassing CORS restrictions and providing the size in one request.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public function proxy_image( $request ) {
		$url = $request->get_param( 'url' );

		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', 'URL is required', array( 'status' => 400 ) );
		}

		// Validate URL is from Google
		if ( false === strpos( $url, 'googleusercontent.com' ) && false === strpos( $url, 'drive.google.com' ) ) {
			return new WP_Error( 'invalid_url', 'Invalid URL', array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', true ),
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Failed to fetch URL: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code  = wp_remote_retrieve_response_code( $response );
		$body         = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( 200 !== $status_code ) {
			return new WP_Error( 'upstream_error', 'Upstream returned status ' . $status_code, array( 'status' => 502 ) );
		}

		// Stream the image bytes back with proper headers
		header( 'Content-Type: ' . ( $content_type ? $content_type : 'image/jpeg' ) );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'Cache-Control: public, max-age=3600' );
		// Note: same-origin response, so JS can read Content-Length natively
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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
					'iconLink',
					'hasThumbnail',
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
					'iconLink',
					'hasThumbnail',
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
	 * Gets the stored orientation corrections for a file.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_corrections( $request ) {
		global $wpdb;
		$file_id = $request->get_param( 'file_id' );

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'File ID is required', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_corrections';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT thumb_rotation, light_rotation FROM {$table} WHERE image_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$file_id
			),
			ARRAY_A
		);

		return new WP_REST_Response(
			array(
				'thumb_rotation' => $row ? intval( $row['thumb_rotation'] ) : 0,
				'light_rotation' => $row ? intval( $row['light_rotation'] ) : 0,
			),
			200
		);
	}

	/**
	 * Saves orientation corrections for a file.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_corrections( $request ) {
		global $wpdb;
		$params  = $request->get_json_params();
		$file_id = isset( $params['file_id'] ) ? $params['file_id'] : '';

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'File ID is required', array( 'status' => 400 ) );
		}

		$valid_rotations  = array( 0, 90, 180, 270 );
		$thumb_rotation   = isset( $params['thumb_rotation'] ) ? intval( $params['thumb_rotation'] ) : 0;
		$light_rotation   = isset( $params['light_rotation'] ) ? intval( $params['light_rotation'] ) : 0;

		if ( ! in_array( $thumb_rotation, $valid_rotations, true ) ) {
			return new WP_Error( 'invalid_rotation', 'thumb_rotation must be 0, 90, 180, or 270', array( 'status' => 400 ) );
		}

		if ( ! in_array( $light_rotation, $valid_rotations, true ) ) {
			return new WP_Error( 'invalid_rotation', 'light_rotation must be 0, 90, 180, or 270', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_corrections';
		$wpdb->replace(
			$table,
			array(
				'image_id'      => $file_id,
				'thumb_rotation' => $thumb_rotation,
				'light_rotation' => $light_rotation,
			),
			array( '%s', '%d', '%d' )
		);

		return new WP_REST_Response(
			array(
				'thumb_rotation' => $thumb_rotation,
				'light_rotation' => $light_rotation,
			),
			200
		);
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
