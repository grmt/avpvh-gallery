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
 * Tagging something in a taggable group (Plek › Kamp › Keuken) also
 * tags the group; withdrawing the group withdraws your tags in it.
 *
 * Tags are identified by slug alone — a slug is only ever listed in one
 * group — so tags saved under an older grouping (rows whose category is
 * the former 'graven'/'kamp') still count as the same tag.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Subject_Tags {

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
					'everyone' => array(
						'default' => false,
						'type'    => 'boolean',
					),
					'file_id'  => array(
						'required' => true,
						'type'     => 'string',
					),
					'tag_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return null !== Subject_Tag_Tree::tag( (string) $value );
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
		$mine    = array();

		// A tag is a vote: per tag, how many tagged it and who (first first).
		foreach ( $rows as $row ) {
			$details[ $row->tag_key ]       ??= array(
				'at'    => (string) $row->created_at,
				'by'    => array(),
				'count' => 0,
			);
			$details[ $row->tag_key ]['by'][] = $names[ (int) $row->created_by ] ?? '';
			++$details[ $row->tag_key ]['count'];

			if ( get_current_user_id() === (int) $row->created_by ) {
				$mine[] = $row->tag_key;
			}
		}

		foreach ( $details as $slug => $detail ) {
			$details[ $slug ]['by'] = implode( ', ', array_filter( $detail['by'] ) );
		}

		return new WP_REST_Response(
			array(
				'details' => (object) $details,
				'tags'    => array_values( array_unique( $mine ) ),
			),
			200
		);
	}

	/**
	 * Adds or withdraws the viewer's vote for one subject tag. Anyone logged
	 * in may add and withdraw their own; removing everyone's ("everyone")
	 * is limited to the same people who may exclude photos (admins and
	 * "boek" members). In a one-per-photo group, voting for another tag
	 * moves the viewer's vote.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_tag( $request ) {
		$file_id  = sanitize_text_field( (string) $request->get_param( 'file_id' ) );
		$tag_slug = sanitize_text_field( (string) $request->get_param( 'tag_slug' ) );
		$category = null !== Subject_Tag_Tree::tag( $tag_slug ) ? 'subject' : null;

		if ( '' === $file_id || null === $category ) {
			return new WP_Error( 'invalid_tag', 'file_id and a known tag_slug are required', array( 'status' => 400 ) );
		}

		if ( ! (bool) $request->get_param( 'active' ) ) {
			return self::remove_tags(
				$file_id,
				Subject_Tag_Tree::branch( $tag_slug ),
				! (bool) $request->get_param( 'everyone' )
			);
		}

		$mine = array_column(
			array_filter(
				self::rows_for( $file_id ),
				static function ( $row ) {
					return get_current_user_id() === (int) $row->created_by;
				}
			),
			'tag_key'
		);

		// One vote per person in a one-per-photo group: yours moves.
		if ( ! in_array( $tag_slug, $mine, true ) && Subject_Tag_Tree::single( $tag_slug ) ) {
			$siblings = Subject_Tag_Tree::siblings( $tag_slug );
			self::remove_tags( $file_id, array_intersect( $siblings, $mine ), true );
		}

		$group = Subject_Tag_Tree::group_key( $tag_slug );

		foreach ( array_diff( array_filter( array( $tag_slug, $group ) ), $mine ) as $slug ) {
			self::add_tag( $file_id, $category, $slug );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * The label of a tag (by its key, e.g. "t12").
	 *
	 * @param string $key Tag key.
	 *
	 * @return string
	 */
	private static function label( $key ) {
		$tag = Subject_Tag_Tree::tag( $key );

		return null !== $tag ? $tag['label'] : $key;
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
				 WHERE image_id = %s AND category = 'subject' ORDER BY created_at",
				$file_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_values(
			array_filter(
				is_array( $rows ) ? $rows : array(),
				static function ( $row ) {
					return null !== Subject_Tag_Tree::tag( (string) $row->tag_key );
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
		Tag_Log::record( $file_id, $category, $tag_slug, self::label( $tag_slug ), 'add' );
	}

	/**
	 * Removes the current user's votes for subject tags — or, when not
	 * $own_only, everyone's (admins and "boek" members only) — and logs it.
	 *
	 * @param string        $file_id Drive file ID.
	 * @param array<string> $slugs    The tags to remove.
	 * @param bool          $own_only Only the current user's votes.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private static function remove_tags( $file_id, array $slugs, $own_only ) {
		if ( array() === $slugs ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		if ( ! $own_only && ! Exclusion_Permission::check() ) {
			return new WP_Error(
				'forbidden',
				'Alleen beheerders kunnen tags van anderen verwijderen',
				array( 'status' => 403 )
			);
		}

		// Only integers are interpolated below.
		$by_whom = $own_only ? ' AND created_by = ' . get_current_user_id() : '';

		global $wpdb;

		foreach ( $slugs as $slug ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table, fixed name; values are prepared.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}agallery_photo_tags
					 WHERE image_id = %s AND tag_key = %s AND category = 'subject'{$by_whom}",
					$file_id,
					$slug
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Tag_Log::record(
				$file_id,
				'subject',
				$slug,
				self::label( $slug ),
				'remove'
			);
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}
