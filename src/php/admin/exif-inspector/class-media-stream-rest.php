<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- one line over the limit; splitting stream_video's helpers into another file would fragment a single cohesive operation.
/**
 * Contains the Media_Stream_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

use Avpvh\API_Client;
use Avpvh\Exceptions\Plugin_Not_Authorized_Exception;
use Exception;
use WP_Error;
use WP_REST_Request;

/**
 * REST API controller that streams and proxies media bytes for the EXIF Inspector.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Media_Stream_REST {

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
			'exif-inspector/proxy-image',
			array(
				'args'                => array(
					'url' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => array( $this, 'proxy_image' ),
				'methods'             => 'GET',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/download-original',
			array(
				'args'                => array(
					'file_id'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'mime_type' => array(
						'required' => true,
						'type'     => 'string',
					),
					'size'      => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
				'callback'            => array( $this, 'download_original' ),
				'methods'             => 'GET',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/video-stream',
			array(
				'args'                => array(
					'file_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => array( $this, 'stream_video' ),
				'methods'             => 'GET',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);
	}

	/**
	 * Streams a video to the inspector and supports browser byte-range requests.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_Error|void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public function stream_video( $request ) {
		$file_id   = $request->get_param( 'file_id' );
		$mime_type = sanitize_mime_type( (string) $request->get_param( 'mime_type' ) );
		$size      = intval( $request->get_param( 'size' ) );

		if ( '' === $file_id || ! str_starts_with( $mime_type, 'video/' ) || $size < 1 ) {
			return new WP_Error( 'invalid_video', 'Valid video metadata is required', array( 'status' => 400 ) );
		}

		try {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$range    = isset( $_SERVER['HTTP_RANGE'] ) ? wp_unslash( $_SERVER['HTTP_RANGE'] ) : '';
			$resolved = self::resolve_byte_range( $range, $size );

			if ( $resolved instanceof WP_Error ) {
				return $resolved;
			}

			list( $start, $end ) = $resolved;

			$stream = self::open_drive_range_stream( $file_id, $start, $end );

			if ( null === $stream ) {
				return new WP_Error( 'stream_error', 'Failed to open video stream', array( 'status' => 500 ) );
			}

			self::send_video_headers( $mime_type, $start, $end, $size, '' !== $range );

			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			fpassthru( $stream );
			exit;
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( Exception $e ) {
			return new WP_Error(
				'video_stream_error',
				'Video stream failed: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
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

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file_id', 'File ID is required', array( 'status' => 400 ) );
		}

		try {
			$http = API_Client::get_authorized_raw_client()->authorize();
			$meta = self::fetch_file_meta( $http, $file_id );

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

			header( 'Content-Type: ' . $meta['mime_type'] );
			header( 'Content-Disposition: attachment; filename="' . addslashes( $meta['filename'] ) . '"' );

			if ( $meta['size'] > 0 ) {
				header( 'Content-Length: ' . $meta['size'] );
			}

			ob_end_clean();
			fpassthru( $stream );
			exit;
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( Exception $e ) {
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
	 * @return WP_Error|void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public function proxy_image( $request ) {
		$url = $request->get_param( 'url' );

		if ( '' === $url ) {
			return new WP_Error( 'invalid_url', 'URL is required', array( 'status' => 400 ) );
		}

		// Validate URL is from Google.
		if ( false === strpos( $url, 'googleusercontent.com' ) && false === strpos( $url, 'drive.google.com' ) ) {
			return new WP_Error( 'invalid_url', 'Invalid URL', array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'redirection' => 5,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- pre-existing hook name shared with class-photo-tags.php; renaming would be a breaking change for sites already hooked into it.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', true ),
				'timeout'     => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'request_failed',
				'Failed to fetch URL: ' . $response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$status_code  = wp_remote_retrieve_response_code( $response );
		$body         = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'upstream_error',
				'Upstream returned status ' . $status_code,
				array( 'status' => 502 )
			);
		}

		// Stream the image bytes back with proper headers.
		header( 'Content-Type: ' . ( '' !== $content_type ? $content_type : 'image/jpeg' ) );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'Cache-Control: public, max-age=3600' );

		// Note: same-origin response, so JS can read Content-Length natively.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body;
		exit;
	}

	/**
	 * Opens a byte-range stream to a Drive file's raw content, authorized via the plugin's OAuth token.
	 *
	 * @param string $file_id Google Drive file ID.
	 * @param int    $start   First byte to request (inclusive).
	 * @param int    $end     Last byte to request (inclusive).
	 *
	 * @return resource|null
	 */
	private static function open_drive_range_stream( $file_id, $start, $end ) {
		$http     = API_Client::get_authorized_raw_client()->authorize();
		$response = $http->request(
			'GET',
			'drive/v3/files/' . $file_id,
			array(
				'headers' => array( 'Range' => 'bytes=' . $start . '-' . $end ),
				'query'   => array(
					'alt'               => 'media',
					'supportsAllDrives' => 'true',
				),
				'stream'  => true,
			)
		);

		return $response->getBody()->detach();
	}

	/**
	 * Sends the response headers for a (possibly partial) video stream.
	 *
	 * @param string $mime_type   The video's MIME type.
	 * @param int    $start       First byte being sent (inclusive).
	 * @param int    $end         Last byte being sent (inclusive).
	 * @param int    $size        Total size of the video.
	 * @param bool   $is_partial  Whether a byte range was requested.
	 *
	 * @return void
	 */
	private static function send_video_headers( $mime_type, $start, $end, $size, $is_partial ) {
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: inline' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );

		if ( $is_partial ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
			http_response_code( 206 );
		}

		header( 'Cache-Control: private, no-store' );
	}

	/**
	 * Resolves the requested byte range against the file size.
	 *
	 * @param string $range Raw `Range` request header value (may be empty).
	 * @param int    $size  Total size of the file being streamed.
	 *
	 * @return array{0: int, 1: int}|WP_Error Start/end byte offsets (inclusive), or an error.
	 */
	private static function resolve_byte_range( $range, $size ) {
		if ( '' === $range ) {
			return array( 0, $size - 1 );
		}

		if (
			1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $matches ) ||
			( '' === $matches[1] && '' === $matches[2] )
		) {
			return new WP_Error( 'invalid_range', 'Invalid byte range', array( 'status' => 416 ) );
		}

		list( $start, $end ) = self::start_and_end( $matches[1], $matches[2], $size );

		if ( $start > $end || $start >= $size ) {
			return new WP_Error(
				'invalid_range',
				'Requested byte range is outside the video',
				array( 'status' => 416 )
			);
		}

		return array( $start, $end );
	}

	/**
	 * Converts the captured `Range` header groups into concrete start/end byte offsets.
	 *
	 * @param string $first_group  The first `bytes=X-Y` capture group (may be empty for a suffix range).
	 * @param string $second_group The second `bytes=X-Y` capture group (may be empty for an open-ended range).
	 * @param int    $size         Total size of the file being streamed.
	 *
	 * @return array{0: int, 1: int}
	 */
	private static function start_and_end( $first_group, $second_group, $size ) {
		if ( '' === $first_group && '' !== $second_group ) {
			$suffix_length = min( intval( $second_group ), $size );

			return array( $size - $suffix_length, $size - 1 );
		}

		$start = '' !== $first_group ? intval( $first_group ) : 0;
		$end   = '' !== $second_group ? min( intval( $second_group ), $size - 1 ) : $size - 1;

		return array( $start, $end );
	}

	/**
	 * Fetches a Drive file's name, MIME type, and size.
	 *
	 * @param object $http    An authorized raw HTTP client.
	 * @param string $file_id Google Drive file ID.
	 *
	 * @return array{filename: string, mime_type: string, size: int}
	 */
	private static function fetch_file_meta( $http, $file_id ) {
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

		return array(
			'filename'  => isset( $meta['name'] ) ? $meta['name'] : 'download',
			'mime_type' => isset( $meta['mimeType'] ) ? $meta['mimeType'] : 'application/octet-stream',
			'size'      => isset( $meta['size'] ) ? (int) $meta['size'] : 0,
		);
	}
}
