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
		'tag'      => "SELECT image_id FROM {prefix}agallery_photo_tags WHERE category <> 'personen' AND tag_key = %s",
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
		add_action( 'wp_ajax_gallery_filter', array( self::class, 'handle_filter' ) );
		add_action( 'wp_ajax_gallery_filter_options', array( self::class, 'handle_options' ) );
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

		list( $ids, $total ) = self::matching_ids( $conditions, $page );

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
	 * The valid conditions in the request. A liked_by condition on someone
	 * whose likes the viewer may not see (Like_Visibility) is dropped.
	 *
	 * @return array<array{kind: string, value: string|int, op: string}>
	 */
	private static function conditions_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only lookup; decoded JSON is validated field by field below.
		$raw        = json_decode( wp_unslash( (string) ( $_GET['conditions'] ?? '[]' ) ), true );
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
	private static function valid_condition( $condition ) {
		if ( ! is_array( $condition ) ) {
			return null;
		}

		$kind     = (string) ( $condition['kind'] ?? '' );
		$value    = sanitize_text_field( (string) ( $condition['value'] ?? '' ) );
		$operator = (string) ( $condition['op'] ?? 'and' );

		if (
			! isset( self::SUBQUERIES[ $kind ] )
			|| '' === $value
			|| ! in_array( $operator, array( 'and', 'or', 'not' ), true )
		) {
			return null;
		}

		if ( 'liked_by' === $kind && ! Like_Visibility::can_see( (int) $value ) ) {
			return null;
		}

		return array(
			'kind'  => $kind,
			'op'    => $operator,
			'value' => 'liked_by' === $kind ? (int) $value : $value,
		);
	}

	/**
	 * The IDs of one page of photos matching the conditions, in capture
	 * order (photos without a known capture date last), plus the total.
	 *
	 * @param array<array{kind: string, value: string|int, op: string}> $conditions See conditions_from_request().
	 * @param int                                                       $page       1-based page number.
	 *
	 * @return array{0: array<string>, 1: int}
	 */
	private static function matching_ids( array $conditions, $page ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$where  = array();
		$either = array();
		$args   = array();

		foreach ( $conditions as $condition ) {
			$subquery = str_replace( '{prefix}', $prefix, self::SUBQUERIES[ $condition['kind'] ] );

			if ( 'or' === $condition['op'] ) {
				$either[] = array( "m.image_id IN ({$subquery})", $condition['value'] );

				continue;
			}

			$where[] = 'm.image_id ' . ( 'not' === $condition['op'] ? 'NOT IN' : 'IN' ) . " ({$subquery})";
			$args[]  = $condition['value'];
		}

		if ( array() !== $either ) {
			$where[] = '(' . implode( ' OR ', array_column( $either, 0 ) ) . ')';
			$args    = array_merge( $args, array_column( $either, 1 ) );
		}

		$from = "FROM ( SELECT image_id FROM {$prefix}agallery_photo_reactions
		                UNION SELECT image_id FROM {$prefix}agallery_photo_tags
		                UNION SELECT image_id FROM {$prefix}agallery_photo_places ) m
		         LEFT JOIN {$prefix}agallery_photo_exif_dates d ON d.image_id = m.image_id
		         WHERE " . implode( ' AND ', $where ) . "
		           AND m.image_id NOT IN ( SELECT image_id FROM {$prefix}agallery_photo_exclusions )";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- plugin tables with fixed names; every value is a placeholder (in $from, one per criterion) filled by prepare().
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) {$from}", $args )
		);
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.image_id {$from}
				 ORDER BY d.original_datetime IS NULL, d.original_datetime, m.image_id
				 LIMIT %d OFFSET %d",
				array_merge( $args, array( self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array( array_map( 'strval', $ids ), $total );
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
