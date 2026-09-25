<?php
/**
 * Contains the Exif_Date_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Public REST endpoint that returns a photo's true EXIF DateTimeOriginal,
 * for display in the frontend lightbox.
 *
 * Google Drive's own `imageMediaMetadata.time` is usually derived from this
 * same tag, but isn't guaranteed to match it exactly. Reading the real tag
 * requires downloading part of the original file, so this endpoint is a
 * pure cache reader — it never fetches from Drive itself. The cache
 * (`agallery_photo_exif_dates`) is only ever populated as a side effect of
 * an admin opening the photo in the EXIF Inspector (see
 * Exif_Data_REST::get_full_exif() in the admin namespace), which already
 * downloads and parses the file's full EXIF data for that view. A photo an
 * admin has never inspected simply has no date to show here yet.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Exif_Date_REST {

	/**
	 * Registers the REST route.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST route. Public — no permission gate — the underlying
	 * data (a photo's capture date) is already visible in the gallery itself.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'avpvh-gallery/v1',
			'exif-date',
			array(
				'args'                => array(
					'file_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => array( $this, 'get_exif_date' ),
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Returns the cached EXIF DateTimeOriginal for a file, if it's ever been
	 * inspected by an admin.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_exif_date( $request ) {
		global $wpdb;
		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_exif_dates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$cached = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is concatenated (not user-supplied); %s below is a real placeholder.
				'SELECT original_datetime FROM ' . $table . ' WHERE image_id = %s',
				$file_id
			),
			ARRAY_A
		);

		$stored = is_array( $cached ) ? $cached['original_datetime'] : null;

		return new WP_REST_Response(
			// The frontend's EXIF date formatter expects EXIF's own "Y:m:d H:i:s" style
			// (colons throughout); MySQL's DATETIME format uses dashes in the date part.
			array( 'original_datetime' => null !== $stored ? str_replace( '-', ':', $stored ) : null ),
			200
		);
	}
}
