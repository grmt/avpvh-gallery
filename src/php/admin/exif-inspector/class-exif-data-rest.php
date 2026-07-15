<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- four related EXIF-extraction endpoints; splitting further would fragment one cohesive concern.
/**
 * Contains the Exif_Data_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Exceptions\API_Exception;
use Avpvh\Exceptions\Not_Found_Exception;
use Avpvh\Exceptions\Plugin_Not_Authorized_Exception;
use Avpvh\Frontend\API_Fields;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// phpcs:disable SlevomatCodingStandard.Classes.ClassLength.ClassTooLong -- see FileTooLong suppression above.
/**
 * REST API controller that extracts and formats EXIF data for the inspector.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Exif_Data_REST {

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
			'exif-inspector/orientation',
			array(
				'callback'            => array( $this, 'get_file_orientation' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/file-data',
			array(
				'callback'            => array( $this, 'file_data' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/full-exif',
			array(
				'callback'            => array( $this, 'get_full_exif' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/preview-exif',
			array(
				'callback'            => array( $this, 'get_preview_exif' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);
	}

	/**
	 * Returns the EXIF Orientation tag (1-8) for a single file using a minimal range request.
	 *
	 * Body: { file_id: string }
	 * Response: { orientation: int, found: bool }
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_file_orientation( $request ) {
		try {
			$params  = $request->get_json_params();
			$file_id = isset( $params['file_id'] ) ? $params['file_id'] : '';

			if ( '' === $file_id ) {
				return new WP_Error( 'invalid_file', 'File ID is required', array( 'status' => 400 ) );
			}

			return new WP_REST_Response( self::read_orientation_tag( $file_id ), 200 );
		} catch ( Throwable $e ) {
			return new WP_REST_Response(
				array(
					'found'       => false,
					'orientation' => 1,
				),
				200
			);
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

			$results         = API_Client::execute(
				array(
					API_Facade::get_file( $file_id, self::file_data_fields() ),
					API_Facade::get_file_parents( $file_id ),
				)
			);
			$file            = $results[0];
			$file['parents'] = $results[1];

			return new WP_REST_Response( array( 'file' => $file ), 200 );
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( Not_Found_Exception $e ) {
			return new WP_Error( 'not_found', 'File not found', array( 'status' => 404 ) );
		} catch ( API_Exception $e ) {
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- logs genuinely unexpected exceptions; no other logging mechanism exists in this plugin.
			error_log(
				'[EXIF Inspector] file_data unexpected: ' . $e::class . ': ' . $e->getMessage() .
				' in ' . $e->getFile() . ':' . $e->getLine()
			);

			return new WP_Error(
				'internal_error',
				$e::class . ': ' . $e->getMessage(),
				array( 'status' => 500 )
			);
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
		$file_id = '';

		try {
			$params  = $request->get_json_params();
			$file_id = isset( $params['file_id'] ) ? $params['file_id'] : '';

			if ( '' === $file_id ) {
				return new WP_Error( 'invalid_file', 'File ID is required', array( 'status' => 400 ) );
			}

			$temp_file = self::download_exif_header( $file_id );

			if ( $temp_file instanceof WP_Error ) {
				return $temp_file;
			}

			list( $flat_exif, $thumb_base64, $thumb_w, $thumb_h ) = self::extract_exif_and_thumbnail( $temp_file );

			return new WP_REST_Response(
				array(
					'corrections'      => self::corrections_for_file( $file_id ),
					'embedded_thumb'   => $thumb_base64,
					'embedded_thumb_h' => $thumb_h,
					'embedded_thumb_w' => $thumb_w,
					'exif'             => $flat_exif,
				),
				200
			);
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( Not_Found_Exception $e ) {
			return new WP_Error( 'not_found', 'File not found', array( 'status' => 404 ) );
		} catch ( API_Exception $e ) {
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		} catch ( Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- logs genuinely unexpected exceptions; no other logging mechanism exists in this plugin.
			error_log(
				'[EXIF Inspector] full-exif failed for ' . $file_id . ': ' .
				$e::class . ': ' . $e->getMessage() . ' in ' .
				$e->getFile() . ':' . $e->getLine()
			);

			return new WP_Error(
				'unexpected_error',
				'Unexpected error: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Downloads a Google preview URL and returns its EXIF data.
	 *
	 * Body: {url: string}
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_preview_exif( $request ) {
		$params = $request->get_json_params();
		$url    = isset( $params['url'] ) ? $params['url'] : '';

		if ( '' === $url ) {
			return new WP_Error( 'invalid_url', 'url is required', array( 'status' => 400 ) );
		}

		if ( false === strpos( $url, 'googleusercontent.com' ) && false === strpos( $url, 'drive.google.com' ) ) {
			return new WP_Error( 'invalid_url', 'URL must be from Google', array( 'status' => 400 ) );
		}

		$temp_file = wp_tempnam( 'pexif-' );

		if ( ! $temp_file ) {
			return new WP_Error( 'temp_file_error', 'Unable to create temporary file', array( 'status' => 500 ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'filename'  => $temp_file,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- pre-existing hook name shared with class-photo-tags.php; renaming would be a breaking change for sites already hooked into it.
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
				'stream'    => true,
				'timeout'   => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $temp_file );

			return new WP_Error( 'download_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		if ( ! function_exists( 'exif_read_data' ) ) {
			wp_delete_file( $temp_file );

			return new WP_REST_Response( array( 'exif' => array() ), 200 );
		}

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$exif_data = @exif_read_data( $temp_file, null, true );
		wp_delete_file( $temp_file );

		$flat = Makernote_Tags::rename( self::flatten_exif_sections( $exif_data ) );

		return new WP_REST_Response( array( 'exif' => $flat ), 200 );
	}

	/**
	 * Returns the access token of the currently authorized Drive client, or '' if unavailable.
	 *
	 * @return string
	 */
	private static function access_token() {
		$raw_client = API_Client::get_authorized_raw_client();
		$token_data = $raw_client->getAccessToken();

		return is_array( $token_data ) && isset( $token_data['access_token'] ) ? $token_data['access_token'] : '';
	}

	/**
	 * Builds the direct-media-download URL for a Drive file.
	 *
	 * @param string $file_id Google Drive file ID.
	 *
	 * @return string
	 */
	private static function media_download_url( $file_id ) {
		return 'https://www.googleapis.com/drive/v3/files/' .
			rawurlencode( $file_id ) .
			'?alt=media&supportsAllDrives=true';
	}

	/**
	 * Flattens exif_read_data()'s nested section→key→value structure into `SECTION:key` entries,
	 * dropping binary/unsafe values along the way.
	 *
	 * @param array<string, array<string, mixed>>|false $exif_data Raw exif_read_data() output.
	 *
	 * @return array<string, mixed>
	 */
	private static function flatten_exif_sections( $exif_data ) {
		$flat = array();

		if ( ! $exif_data ) {
			return $flat;
		}

		foreach ( $exif_data as $section => $data ) {
			if ( is_array( $data ) ) {
				$flat = array_merge( $flat, self::flatten_one_section( (string) $section, $data ) );
			}
		}

		return $flat;
	}

	/**
	 * Flattens one EXIF section's key/value pairs, dropping binary/unsafe values.
	 *
	 * @param string               $section The EXIF section name.
	 * @param array<string, mixed> $data    The section's raw key/value pairs.
	 *
	 * @return array<string, mixed>
	 */
	private static function flatten_one_section( $section, array $data ) {
		$flat = array();

		foreach ( $data as $key => $value ) {
			if ( null === $value || is_array( $value ) || is_object( $value ) || 'MakerNote' === $key ) {
				// MakerNote is a raw binary blob, not parseable.
				continue;
			}

			$value = self::sanitize_exif_value( $value );

			if ( null !== $value ) {
				$flat[ $section . ':' . $key ] = $value;
			}
		}

		return $flat;
	}

	/**
	 * Downloads a small leading range of a file and reads its EXIF Orientation tag.
	 * Any failure along the way (auth, download, missing tag) yields the safe default.
	 *
	 * @param string $file_id Google Drive file ID.
	 *
	 * @return array{orientation: int, found: bool}
	 */
	private static function read_orientation_tag( $file_id ) {
		$default = array(
			'found'       => false,
			'orientation' => 1,
		);
		$bearer  = self::access_token();

		if ( '' === $bearer ) {
			return $default;
		}

		$temp_file = wp_tempnam( 'orient-' . sanitize_file_name( $file_id ) . '-' );

		if ( ! $temp_file ) {
			return $default;
		}

		// Read only the leading JPEG metadata area, not the complete original.
		$dl_response = wp_remote_get(
			self::media_download_url( $file_id ),
			array(
				'filename'  => $temp_file,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $bearer,
					'Range'         => 'bytes=0-131071',
				),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- pre-existing hook name shared with class-photo-tags.php; renaming would be a breaking change for sites already hooked into it.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'stream'    => true,
				'timeout'   => 15,
			)
		);

		$result = self::orientation_from_downloaded_range( $dl_response, $temp_file );
		wp_delete_file( $temp_file );

		return $result;
	}

	/**
	 * Extracts the Orientation tag from a downloaded byte range, once the download itself succeeded.
	 *
	 * @param array<string, mixed>|WP_Error $dl_response Result of wp_remote_get().
	 * @param string                        $temp_file   Path the response body was streamed to.
	 *
	 * @return array{orientation: int, found: bool}
	 */
	private static function orientation_from_downloaded_range( $dl_response, $temp_file ) {
		$default = array(
			'found'       => false,
			'orientation' => 1,
		);

		if ( is_wp_error( $dl_response ) ) {
			return $default;
		}

		$dl_status = (int) wp_remote_retrieve_response_code( $dl_response );

		if ( 200 !== $dl_status && 206 !== $dl_status ) {
			return $default;
		}

		if ( ! function_exists( 'exif_read_data' ) ) {
			return $default;
		}

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$exif = @exif_read_data( $temp_file, 'IFD0' );

		if ( ! is_array( $exif ) || ! isset( $exif['Orientation'] ) ) {
			return $default;
		}

		$o = intval( $exif['Orientation'] );

		if ( $o < 1 || $o > 8 ) {
			return $default;
		}

		return array(
			'found'       => true,
			'orientation' => $o,
		);
	}

	/**
	 * The Drive fields requested for a single file's full data view.
	 *
	 * @return API_Fields
	 */
	private static function file_data_fields() {
		return new API_Fields(
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
				'videoMediaMetadata' => array( 'width', 'height', 'durationMillis' ),
				'mimeType',
				'md5Checksum',
				'modifiedTime',
				'name',
				'size',
				'thumbnailLink',
				'iconLink',
				'hasThumbnail',
				'webContentLink',
				'webViewLink',
			)
		);
	}

	/**
	 * Downloads the leading 128 KB of a Drive file (enough for a JPEG's EXIF APP1 header)
	 * to a temp file and confirms EXIF support is available.
	 *
	 * Uses wp_remote_get with an explicit Bearer token so the range is streamed reliably
	 * to disk (Guzzle sink had issues writing complete files in this context).
	 *
	 * @param string $file_id Google Drive file ID.
	 *
	 * @return string|WP_Error Path to the downloaded temp file, or an error.
	 */
	private static function download_exif_header( $file_id ) {
		$bearer = self::access_token();

		if ( '' === $bearer ) {
			return new WP_Error( 'not_authorized', 'No access token available', array( 'status' => 403 ) );
		}

		$temp_file = wp_tempnam( 'exif-' . sanitize_file_name( $file_id ) . '-' );

		if ( ! $temp_file ) {
			return new WP_Error( 'temp_file_error', 'Unable to create temporary file', array( 'status' => 500 ) );
		}

		// Only the first 128 KB is needed — JPEG EXIF APP1 header is always near the start.
		$dl_response = wp_remote_get(
			self::media_download_url( $file_id ),
			array(
				'filename'  => $temp_file,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $bearer,
					'Range'         => 'bytes=0-131071',
				),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- pre-existing hook name shared with class-photo-tags.php; renaming would be a breaking change for sites already hooked into it.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'stream'    => true,
				'timeout'   => 30,
			)
		);

		if ( is_wp_error( $dl_response ) ) {
			wp_delete_file( $temp_file );

			return new WP_Error( 'download_error', $dl_response->get_error_message(), array( 'status' => 500 ) );
		}

		$dl_status = wp_remote_retrieve_response_code( $dl_response );

		// Accept 200 (full download) or 206 (partial content — expected with Range header).
		if ( 200 !== (int) $dl_status && 206 !== (int) $dl_status ) {
			wp_delete_file( $temp_file );

			return new WP_Error( 'download_error', 'Drive API returned HTTP ' . $dl_status, array( 'status' => 502 ) );
		}

		if ( ! function_exists( 'exif_read_data' ) ) {
			wp_delete_file( $temp_file );

			return new WP_Error( 'no_exif_support', 'EXIF support is not available in PHP', array( 'status' => 500 ) );
		}

		return $temp_file;
	}

	/**
	 * Reads EXIF data and the embedded camera-generated thumbnail from a downloaded file,
	 * then deletes the temp file.
	 *
	 * @param string $temp_file Path to the downloaded file.
	 *
	 * @return array{0: array<string, mixed>, 1: string|null, 2: int, 3: int} Flat EXIF, thumbnail data URI, width, height.
	 */
	private static function extract_exif_and_thumbnail( $temp_file ) {
		// null = return all sections; true = return as nested array by section name.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$exif_data = @exif_read_data( $temp_file, null, true );

		// Extract embedded JPEG thumbnail (camera-generated, stored in EXIF APP1 block).
		$thumb_w    = 0;
		$thumb_h    = 0;
		$thumb_type = 0;
		$thumb_data = false;

		if ( function_exists( 'exif_thumbnail' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$thumb_data = @exif_thumbnail(
				$temp_file,
				$thumb_w,
				$thumb_h,
				$thumb_type
			);
		}

		$thumb_base64 = null;

		if ( false !== $thumb_data && '' !== $thumb_data ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$thumb_base64 = 'data:image/jpeg;base64,' . base64_encode(
				$thumb_data
			);
		}

		wp_delete_file( $temp_file );

		$flat_exif = Makernote_Tags::rename( self::flatten_exif_sections( $exif_data ) );

		return array( $flat_exif, $thumb_base64, $thumb_w, $thumb_h );
	}

	/**
	 * Loads the stored DB corrections for a file (new schema: one row per size_key).
	 *
	 * @param string $file_id Google Drive file ID.
	 *
	 * @return array<string, array{r: int, h: bool, v: bool}>
	 */
	private static function corrections_for_file( $file_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_corrections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$rows_result = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT size_key, rotation, h_flip, v_flip FROM ' . $table . ' WHERE image_id = %s',
				$file_id
			),
			ARRAY_A
		);
		$rows        = is_array( $rows_result ) ? $rows_result : array();
		$corrections = array();

		foreach ( $rows as $r ) {
			$corrections[ $r['size_key'] ] = array(
				'h' => (bool) intval( $r['h_flip'] ),
				'r' => intval( $r['rotation'] ),
				'v' => (bool) intval( $r['v_flip'] ),
			);
		}

		return $corrections;
	}

	/**
	 * Makes a scalar EXIF value safe for a JSON REST response.
	 *
	 * @param mixed $value Raw EXIF value.
	 *
	 * @return int|float|string|null
	 */
	private static function sanitize_exif_value( $value ) {
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			return is_int( $value ) || is_float( $value ) ? $value : null;
		}

		if ( strlen( $value ) > 500 || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) {
			return null;
		}

		// Scanner and camera comments regularly contain invalid legacy bytes.
		// Strip those bytes before WordPress passes the response to json_encode().
		return wp_check_invalid_utf8( $value, true );
	}
}
