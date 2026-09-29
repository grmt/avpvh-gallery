<?php
/**
 * Contains the Subject_Tags class.
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
 * Fixed-vocabulary "what's in this photo" checklist tags, independent of
 * who's in the photo, grouped by what they describe: the kind of photo,
 * time of day, weather, activity, finds, and so on. Each tag is a simple
 * boolean per photo — checked or not — so the REST API is a read (all
 * active slugs for a photo) and a single-tag toggle, matching the checkbox
 * UI in the lightbox. A group marked 'single' allows one tag per photo
 * (a photo is either a portrait or an overview, taken in the morning or
 * the evening): checking one unchecks the others.
 *
 * Tags are identified by slug alone — a slug is only ever listed in one
 * group — so tags saved under an older grouping (rows whose category is
 * the former 'graven'/'kamp') still count as the same tag.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Subject_Tags {

	/**
	 * The fixed vocabulary — see Subject_Tag_Groups.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility -- no-modifier matches the convention used elsewhere.
	const CATEGORIES = Subject_Tag_Groups::GROUPS;

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
			'subject-tags',
			array(
				'args'                => array(
					'file_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => array( $this, 'get_tags' ),
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'avpvh-gallery/v1',
			'subject-tags',
			array(
				'args'                => array(
					'active'   => array(
						'required' => true,
						'type'     => 'boolean',
					),
					'file_id'  => array(
						'required' => true,
						'type'     => 'string',
					),
					'tag_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return isset( self::all_tags()[ $value ] );
						},
					),
				),
				'callback'            => array( $this, 'toggle_tag' ),
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Returns the subject tags on a photo, with who added each and when.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tags( $request ) {
		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		$rows    = self::rows_for( $file_id );
		$names   = Tag_Log::user_names( array_map( 'intval', array_column( $rows, 'created_by' ) ) );
		$details = array();

		foreach ( $rows as $row ) {
			$details[ $row->tag_key ] = array(
				'at' => (string) $row->created_at,
				'by' => $names[ (int) $row->created_by ] ?? '',
			);
		}

		return new WP_REST_Response(
			array(
				'details' => (object) $details,
				'tags'    => array_keys( $details ),
			),
			200
		);
	}

	/**
	 * Adds or removes one subject tag. Any logged-in user may add; removing
	 * — including replacing the tag in a one-per-photo group — is limited to
	 * the same people who may exclude photos (admins and "boek" members).
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_tag( $request ) {
		$file_id  = sanitize_text_field( (string) $request->get_param( 'file_id' ) );
		$tag_slug = sanitize_text_field( (string) $request->get_param( 'tag_slug' ) );
		$category = self::category_for_slug( $tag_slug );

		if ( '' === $file_id || null === $category ) {
			return new WP_Error( 'invalid_tag', 'file_id and a known tag_slug are required', array( 'status' => 400 ) );
		}

		$current = array_column( self::rows_for( $file_id ), 'tag_key' );

		if ( ! (bool) $request->get_param( 'active' ) ) {
			return self::remove_tags( $file_id, array_intersect( array( $tag_slug ), $current ) );
		}

		if ( in_array( $tag_slug, $current, true ) ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		$replaced = self::CATEGORIES[ $category ]['single']
			? array_intersect( array_keys( self::CATEGORIES[ $category ]['tags'] ), $current )
			: array();

		if ( array() !== $replaced ) {
			$removed = self::remove_tags( $file_id, $replaced );

			if ( $removed instanceof WP_Error ) {
				return $removed;
			}
		}

		self::add_tag( $file_id, $category, $tag_slug );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Resolves which group a slug belongs to.
	 *
	 * @param string $slug A tag slug.
	 *
	 * @return string|null The group key (e.g. 'weer'), or null if the slug isn't in any group.
	 */
	public static function category_for_slug( $slug ) {
		foreach ( self::CATEGORIES as $category => $group ) {
			if ( isset( $group['tags'][ $slug ] ) ) {
				return $category;
			}
		}

		return null;
	}

	/**
	 * All tags across every rubriek, flattened to slug => label — used to
	 * validate a submitted tag_slug without caring which rubriek it's in.
	 *
	 * @return array<string, string>
	 */
	private static function all_tags() {
		$all = array();

		foreach ( self::CATEGORIES as $group ) {
			$all = array_merge( $all, $group['tags'] );
		}

		return $all;
	}

	/**
	 * The subject tag rows on a photo (any non-person category, so rows
	 * saved under an older grouping still count).
	 *
	 * @param string $file_id Drive file ID.
	 *
	 * @return array<object{tag_key: string, created_by: int|string|null, created_at: string}>
	 */
	private static function rows_for( $file_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table, fixed name; the file ID is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tag_key, created_by, created_at FROM {$wpdb->prefix}agallery_photo_tags
				 WHERE image_id = %s AND category <> 'personen' ORDER BY created_at",
				$file_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$known = self::all_tags();

		return array_values(
			array_filter(
				is_array( $rows ) ? $rows : array(),
				static function ( $row ) use ( $known ) {
					return isset( $known[ $row->tag_key ] );
				}
			)
		);
	}

	/**
	 * Adds a subject tag and logs it.
	 *
	 * @param string $file_id  Drive file ID.
	 * @param string $category The tag's group.
	 * @param string $tag_slug The tag.
	 *
	 * @return void
	 */
	private static function add_tag( $file_id, $category, $tag_slug ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table.
		$wpdb->replace(
			$wpdb->prefix . 'agallery_photo_tags',
			array(
				'category'   => $category,
				'created_at' => current_time( 'mysql' ),
				'created_by' => get_current_user_id(),
				'image_id'   => $file_id,
				'tag_key'    => $tag_slug,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
		Tag_Log::record( $file_id, $category, $tag_slug, self::all_tags()[ $tag_slug ], 'add' );
	}

	/**
	 * Removes subject tags (if the current user may) and logs each.
	 *
	 * @param string        $file_id Drive file ID.
	 * @param array<string> $slugs   The tags to remove; only those actually on the photo.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private static function remove_tags( $file_id, array $slugs ) {
		if ( array() === $slugs ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		if ( ! Exclusion_Permission::check() ) {
			return new WP_Error(
				'forbidden',
				'Alleen boek-leden kunnen tags verwijderen of wijzigen',
				array( 'status' => 403 )
			);
		}

		global $wpdb;

		foreach ( $slugs as $slug ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table, fixed name; values are prepared.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}agallery_photo_tags
					 WHERE image_id = %s AND tag_key = %s AND category <> 'personen'",
					$file_id,
					$slug
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Tag_Log::record(
				$file_id,
				(string) self::category_for_slug( $slug ),
				$slug,
				self::all_tags()[ $slug ] ?? $slug,
				'remove'
			);
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}
