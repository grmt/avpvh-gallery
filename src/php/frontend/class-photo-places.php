<?php
/**
 * Contains the Photo_Places class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Tag_Log;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Where a photo was taken, when that isn't the place in its folder name.
 *
 * A photo's place normally follows from its folder ("2026 Goeblange" →
 * Goeblange, see Photo_Folder_Context), but during a dig the group may go
 * on an excursion to a city or a museum, and those photos end up in the
 * same folder. This stores a per-photo place that replaces the folder's.
 * Like tags: anyone logged in may set a place on a photo that has none;
 * changing or removing one is limited to admins and "boek" members.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Places {

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
			'photo-place',
			array(
				'args'                => array(
					'file_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => array( self::class, 'get_place' ),
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'avpvh-gallery/v1',
			'photo-place',
			array(
				'args'                => array(
					'file_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'place'   => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => array( self::class, 'set_place' ),
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Returns the photo's own place (if any, with who set it and when) and
	 * the places entered on other photos, as suggestions.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_place( $request ) {
		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		$row   = self::row_for( $file_id );
		$names = null !== $row ? Tag_Log::user_names( array( $row['created_by'] ) ) : array();

		return new WP_REST_Response(
			array(
				'at'          => null !== $row ? $row['created_at'] : '',
				'by'          => null !== $row ? ( $names[ $row['created_by'] ] ?? '' ) : '',
				'place'       => null !== $row ? $row['place'] : '',
				'suggestions' => self::known_places(),
			),
			200
		);
	}

	/**
	 * Sets (or, with an empty place, removes) the photo's own place.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_place( $request ) {
		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );
		$place   = trim(
			preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $request->get_param( 'place' ) ) ) ?? ''
		);

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		$current = self::row_for( $file_id );

		if ( null !== $current && $current['place'] === $place ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		if ( null !== $current && ! Exclusion_Permission::check() ) {
			return new WP_Error(
				'forbidden',
				'Alleen boek-leden kunnen een locatie wijzigen of verwijderen',
				array( 'status' => 403 )
			);
		}

		self::store( $file_id, $current, $place );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Writes the change and logs it (a replacement as remove + add).
	 *
	 * @param string                                                         $file_id Drive file ID.
	 * @param array{place: string, created_by: int, created_at: string}|null $current The existing row, if any.
	 * @param string                                                         $place   The new place; '' removes it.
	 *
	 * @return void
	 */
	private static function store( $file_id, $current, $place ) {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_places';

		if ( null !== $current ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table.
			$wpdb->delete( $table, array( 'image_id' => $file_id ), array( '%s' ) );
			Tag_Log::record( $file_id, 'waar', 'place', $current['place'], 'remove' );
		}

		if ( '' === $place ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table.
		$wpdb->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql' ),
				'created_by' => get_current_user_id(),
				'image_id'   => $file_id,
				'place'      => $place,
			),
			array( '%s', '%d', '%s', '%s' )
		);
		Tag_Log::record( $file_id, 'waar', 'place', $place, 'add' );
	}

	/**
	 * The photo's own place row, if it has one.
	 *
	 * @param string $file_id Drive file ID.
	 *
	 * @return array{place: string, created_by: int, created_at: string}|null
	 */
	private static function row_for( $file_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table, fixed name; the file ID is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT place, created_by, created_at FROM {$wpdb->prefix}agallery_photo_places WHERE image_id = %s",
				$file_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_object( $row ) ) {
			return null;
		}

		$fields = get_object_vars( $row );

		return array(
			'created_at' => (string) ( $fields['created_at'] ?? '' ),
			'created_by' => (int) ( $fields['created_by'] ?? 0 ),
			'place'      => (string) ( $fields['place'] ?? '' ),
		);
	}

	/**
	 * Places entered on photos so far, most used first — so the next photo
	 * from the same excursion can pick the same spelling.
	 *
	 * @return array<string>
	 */
	private static function known_places() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table, fixed name, no user input.
		$places = $wpdb->get_col(
			"SELECT place FROM {$wpdb->prefix}agallery_photo_places
			 GROUP BY place ORDER BY COUNT(*) DESC, place LIMIT 200"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'strval', $places );
	}
}
