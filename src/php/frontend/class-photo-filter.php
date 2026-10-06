<?php
/**
 * Contains the Photo_Filter class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Frontend\Page\Images;
use Avpvh\Helpers;

/**
 * Finds photos across the whole gallery by what people added to them:
 * who liked them, who's tagged in them, which subject tag they have, and
 * their own place (see Photo_Places). Unlike a folder page, the matches
 * come from the plugin's own tables rather than from a Drive listing, so
 * one filter covers every folder at once; the matching photos' details are
 * then fetched from Drive, one page at a time.
 *
 * Logged-in users only (wp_ajax_ hooks): who liked what is shown to them,
 * not to the public.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Filter {

	/**
	 * Photos per page of results.
	 */
	private const PAGE_SIZE = 60;

	/**
	 * Per kind of condition: the photos it matches ({prefix} = table prefix;
	 * one placeholder for the condition's value).
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on a multi-line array value.
	private const SUBQUERIES = array(
		'liked_by' => "SELECT image_id FROM {prefix}agallery_photo_reactions WHERE emoji = 'like' AND user_id = %d",
		'person'   => "SELECT image_id FROM {prefix}agallery_photo_tags WHERE category = 'personen' AND tag_key = %s",
		'place'    => 'SELECT image_id FROM {prefix}agallery_photo_places WHERE place = %s',
		'tag'      => "SELECT image_id FROM {prefix}agallery_photo_tags WHERE category = 'subject' AND tag_key = %s",
	);

	/**
	 * The Drive fields fetched per photo — the same as a folder page uses,
	 * plus the folder it's in (for orientation corrections).
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on a multi-line array value.
	private const FILE_FIELDS = array(
		'id',
		'name',
		'mimeType',
		'parents',
		'thumbnailLink',
		'description',
		'imageMediaMetadata' => array(
			'time',
			'width',
			'height',
			'rotation',
			'cameraMake',
			'cameraModel',
			'aperture',
			'exposureTime',
			'isoSpeed',
			'focalLength',
		),
	);

	/**
	 * Registers the AJAX endpoints.
	 */
	public function __construct() {
		// Sharing a filter's photos via Google Drive.
		new Photo_Shares();
		add_action( 'wp_ajax_gallery_filter', array( self::class, 'handle_filter' ) );
		add_action( 'wp_ajax_gallery_filter_options', array( self::class, 'handle_options' ) );
		add_action( 'wp_ajax_gallery_filter_save', array( Filter_Memory::class, 'handle_save' ) );
		add_action( 'wp_ajax_gallery_filter_preset_save', array( Filter_Memory::class, 'handle_preset_save' ) );
		add_action( 'wp_ajax_gallery_filter_preset_delete', array( Filter_Memory::class, 'handle_preset_delete' ) );
	}

	/**
	 * The "gallery_filter" endpoint, with the usual error handling.
	 *
	 * @return void
	 */
	public static function handle_filter() {
		Helpers::ajax_wrapper( array( self::class, 'filter_body' ) );
	}

	/**
	 * Returns one page of matching photos, as a folder page (`images`,
	 * `more`) plus the total number of matches.
	 *
	 * Query: hash (the gallery), page, and conditions — a JSON list of
	 * {kind, value, op}: kind is liked_by (user ID), person (person tag
	 * key), tag (subject tag slug) or place; op is "and" (the photo must
	 * match), "or" (it must match at least one of the "or" conditions) or
	 * "not" (it must not match). At least one and/or condition is needed.
	 * Optional folder: only photos in that folder or below it.
	 *
	 * @return void
	 */
	public static function filter_body() {
		list( , $options ) = Gallery_Context::get();
		$conditions        = self::conditions_from_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
		$page = max( 1, intval( $_GET['page'] ?? 1 ) );

		// Only "not" conditions would mean "every photo except…": not offered.
		$operators = array_column( $conditions, 'op' );

		if ( array() === array_intersect( array( 'and', 'or' ), $operators ) ) {
			wp_send_json(
				array(
					'images' => array(),
					'more'   => false,
					'total'  => 0,
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only lookup; JSON values are sanitized by folder_ids().
		$folder_ids = Photo_Filter_Scope::folder_ids( wp_unslash( (string) ( $_GET['folders'] ?? '' ) ) );

		if ( array() === $folder_ids ) {
			// Backwards compatibility with links/clients that still send one folder.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
			$folder_id  = sanitize_text_field( wp_unslash( (string) ( $_GET['folder'] ?? '' ) ) );
			$folder_ids = '' === $folder_id ? array() : array( $folder_id );
		}

		// "Datum (nieuw → oud)": newest first.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
		$newest_first = 'date_desc' === sanitize_key( wp_unslash( (string) ( $_GET['sort'] ?? '' ) ) );

		list( $ids, $total ) = array() === $folder_ids
			? self::matching_ids( $conditions, $page, $newest_first )
			: self::matching_ids_within( $conditions, $page, $folder_ids, $newest_first );

		wp_send_json(
			array(
				'images' => self::images_for( $ids, $options ),
				'more'   => $page * self::PAGE_SIZE < $total,
				'total'  => $total,
			)
		);
	}

	/**
	 * The "gallery_filter_options" endpoint: what can be filtered on, each
	 * with the number of photos it would find.
	 *
	 * @return void
	 */
	public static function handle_options() {
		wp_send_json_success( Photo_Filter_Options::all() );
	}

	/**
	 * The valid conditions in a JSON list of them, for the current user (see
	 * conditions_from_request()).
	 *
	 * @param string $json JSON list of {kind, value, op}.
	 *
	 * @return array<array{kind: string, value: string|int, op: string}>
	 */
	public static function valid_conditions( $json ) {
		$raw        = json_decode( $json, true );
		$conditions = array();

		foreach ( is_array( $raw ) ? $raw : array() as $condition ) {
			$valid = self::valid_condition( $condition );

			if ( null !== $valid ) {
				$conditions[] = $valid;
			}
		}

		return $conditions;
	}

	/**
	 * One condition, sanitized, or null if it isn't valid or allowed.
	 *
	 * @param mixed $condition A decoded condition.
	 *
	 * @return array{kind: string, value: string|int, op: string}|null
	 */
	public static function valid_condition( $condition ) {
		if ( ! is_array( $condition ) ) {
			return null;
		}

		$kind     = (string) ( $condition['kind'] ?? '' );
		$value    = sanitize_text_field( (string) ( $condition['value'] ?? '' ) );
		$operator = (string) ( $condition['op'] ?? 'and' );

		if (
			! ( isset( self::SUBQUERIES[ $kind ] ) || 'marked' === $kind )
			|| '' === $value
			|| ! in_array( $operator, array( 'and', 'or', 'not' ), true )
		) {
			return null;
		}

		if ( ! self::viewer_may_use( $kind, $value ) ) {
			return null;
		}

		return array(
			'kind'  => $kind,
			'op'    => $operator,
			'value' => 'liked_by' === $kind ? (int) $value : $value,
		);
	}

	/**
	 * All photos matching the conditions, in date order, optionally only
	 * those in a folder or below it — what the gallery shows for that
	 * filter (see Photo_Shares). None when there is no "and"/"or" condition.
	 *
	 * @param array<array{kind: string, value: string|int, op: string}> $conditions See valid_conditions().
	 * @param string|array<string>                                      $folders    Drive folder ID(s), or none for the whole gallery.
	 *
	 * @return array<string>
	 */
	public static function all_matching_ids( array $conditions, $folders ) {
		if ( array() === array_intersect( array( 'and', 'or' ), array_column( $conditions, 'op' ) ) ) {
			return array();
		}

		list( $ids ) = self::matching_ids( $conditions, 0 );

		$folder_ids = is_array( $folders ) ? $folders : ( '' === $folders ? array() : array( $folders ) );

		return array() === $folder_ids ? $ids : Photo_Filter_Scope::within_many( $ids, $folder_ids );
	}

	/**
	 * The valid conditions in the request. A liked_by condition on someone
	 * whose likes the viewer may not see (Like_Visibility) is dropped.
	 *
	 * @return array<array{kind: string, value: string|int, op: string}>
	 */
	private static function conditions_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only lookup; decoded JSON is validated field by field in valid_conditions().
		return self::valid_conditions( wp_unslash( (string) ( $_GET['conditions'] ?? '[]' ) ) );
	}

	/**
	 * Whether the viewer may filter on this: likes only of people whose likes
	 * they may see; marks by a minimum number of stars.
	 *
	 * @param string $kind  Condition kind.
	 * @param string $value Condition value.
	 *
	 * @return bool
	 */
	private static function viewer_may_use( $kind, $value ) {
		if ( 'liked_by' === $kind ) {
			return Like_Visibility::can_see( (int) $value );
		}

		return 'marked' !== $kind || 0 < (int) $value;
	}

	/**
	 * The IDs of one page of photos matching the conditions, in date order
	 * (see Photo_Date_Order), plus the total.
	 *
	 * @param array<array{kind: string, value: string|int, op: string}> $conditions See conditions_from_request().
	 * @param int                                                       $page       1-based page number; 0 for all of them.
	 * @param bool                                                      $newest_first Whether the newest come first.
	 *
	 * @return array{0: array<string>, 1: int}
	 */
	private static function matching_ids( array $conditions, $page, $newest_first = false ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$where  = array();
		$either = array();
		$args   = array();

		$either_args = array();

		foreach ( $conditions as $condition ) {
			list( $subquery, $values ) = self::subquery( $condition, $prefix );

			if ( 'or' === $condition['op'] ) {
				$either[]    = "m.image_id IN ({$subquery})";
				$either_args = array_merge( $either_args, $values );

				continue;
			}

			$where[] = 'm.image_id ' . ( 'not' === $condition['op'] ? 'NOT IN' : 'IN' ) . " ({$subquery})";
			$args    = array_merge( $args, $values );
		}

		if ( array() !== $either ) {
			$where[] = '(' . implode( ' OR ', $either ) . ')';
			$args    = array_merge( $args, $either_args );
		}

		$from = "FROM ( SELECT image_id FROM {$prefix}agallery_photo_reactions
		                UNION SELECT image_id FROM {$prefix}agallery_photo_tags
		                UNION SELECT image_id FROM {$prefix}agallery_photo_places
		                UNION SELECT image_id FROM {$prefix}agallery_photo_marks ) m
		         WHERE " . implode( ' AND ', $where ) . "
		           AND m.image_id NOT IN ( SELECT image_id FROM {$prefix}agallery_photo_exclusions )";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- plugin tables with fixed names; every value is a placeholder (in $from, one per criterion) filled by prepare().
		$all = $wpdb->get_col( $wpdb->prepare( "SELECT m.image_id {$from}", $args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$ids = Photo_Date_Order::sort( array_map( 'strval', $all ) );
		$ids = $newest_first ? array_reverse( $ids ) : $ids;

		return array(
			0 === $page ? $ids : array_slice( $ids, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ),
			count( $ids ),
		);
	}

	/**
	 * One page of the matching photos that are in a folder or below it, and
	 * how many there are in all.
	 *
	 * @param array<array{kind: string, value: string|int, op: string}> $conditions See conditions_from_request().
	 * @param int                                                       $page       1-based page number.
	 * @param array<string>                                             $folder_ids Drive folder IDs.
	 * @param bool                                                      $newest_first Whether the newest come first.
	 *
	 * @return array{0: array<string>, 1: int}
	 */
	private static function matching_ids_within( array $conditions, $page, array $folder_ids, $newest_first = false ) {
		list( $all ) = self::matching_ids( $conditions, 0, $newest_first );
		$inside      = Photo_Filter_Scope::within_many( $all, $folder_ids );

		return array(
			array_slice( $inside, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ),
			count( $inside ),
		);
	}

	/**
	 * The photos a condition matches, as SQL with placeholders and their
	 * values.
	 *
	 * @param array{kind: string, value: string|int, op: string} $condition A valid condition.
	 * @param string                                             $prefix    The table prefix.
	 *
	 * @return array{0: string, 1: array<string|int>}
	 */
	private static function subquery( array $condition, $prefix ) {
		if ( 'marked' !== $condition['kind'] ) {
			return array(
				str_replace( '{prefix}', $prefix, self::SUBQUERIES[ $condition['kind'] ] ),
				array( $condition['value'] ),
			);
		}

		return array(
			"SELECT image_id FROM {$prefix}agallery_photo_marks
			 WHERE circle = %s GROUP BY image_id HAVING SUM(level) >= %d",
			array( Photo_Marks::CIRCLE, (int) $condition['value'] ),
		);
	}

	/**
	 * Fetches the photos' details from Drive (one batched request) and
	 * formats them like a folder page, keeping the given order. Files that
	 * are gone or aren't images are left out.
	 *
	 * @param array<string> $ids     Drive file IDs, in display order.
	 * @param Options_Proxy $options The configuration of the gallery.
	 *
	 * @return array<array<string, mixed>>
	 */
	private static function images_for( array $ids, $options ) {
		$by_folder = array();

		foreach ( self::fetch_records( $ids ) as $record ) {
			$by_folder[ $record['parents'][0] ?? '' ][] = $record;
		}

		$images = array();

		foreach ( $by_folder as $folder_id => $records ) {
			foreach ( Images::from_records( $records, (string) $folder_id, $options ) as $image ) {
				$images[ $image['id'] ] = $image;
			}
		}

		$ordered = array();

		foreach ( $ids as $file_id ) {
			if ( isset( $images[ $file_id ] ) ) {
				$ordered[] = $images[ $file_id ];
			}
		}

		return $ordered;
	}

	/**
	 * Drive records for the given files, skipping ones that can't be fetched
	 * or aren't images.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<array<string, mixed>>
	 */
	private static function fetch_records( array $ids ) {
		$promises = array();

		foreach ( $ids as $file_id ) {
			$promises[ $file_id ] = API_Facade::get_file( $file_id, self::FILE_FIELDS )->then(
				null,
				static function () {
					return null;
				}
			);
		}

		return array_values(
			array_filter(
				API_Client::execute( $promises ),
				static function ( $record ) {
					return is_array( $record )
						&& isset( $record['thumbnailLink'] )
						&& str_starts_with( (string) ( $record['mimeType'] ?? '' ), 'image/' );
				}
			)
		);
	}
}
