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
use Avpvh\Tag_Log;

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
	 * Query: hash (the gallery), page, and any of liked_by (user ID),
	 * person (person tag key), tag (subject tag slug), place.
	 *
	 * @return void
	 */
	public static function filter_body() {
		list( , $options ) = Gallery_Context::get();
		$criteria          = self::criteria_from_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
		$page = max( 1, intval( $_GET['page'] ?? 1 ) );

		if ( array() === $criteria ) {
			wp_send_json(
				array(
					'images' => array(),
					'more'   => false,
					'total'  => 0,
				)
			);
		}

		list( $ids, $total ) = self::matching_ids( $criteria, $page );

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
		wp_send_json_success(
			array(
				'liked_by' => self::likers(),
				'persons'  => self::tagged_persons(),
				'places'   => self::places(),
				'tags'     => self::used_tags(),
			)
		);
	}

	/**
	 * The filter criteria in the request, keyed by kind; empty ones left out.
	 *
	 * @return array<string, string|int>
	 */
	private static function criteria_from_request() {
		$criteria = array();

		foreach ( array( 'liked_by', 'person', 'tag', 'place' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
			$value = sanitize_text_field( wp_unslash( (string) ( $_GET[ $key ] ?? '' ) ) );

			if ( '' !== $value ) {
				$criteria[ $key ] = 'liked_by' === $key ? intval( $value ) : $value;
			}
		}

		return $criteria;
	}

	/**
	 * The IDs of one page of photos matching every criterion, in capture
	 * order (photos without a known capture date last), plus the total.
	 *
	 * @param array<string, string|int> $criteria See criteria_from_request().
	 * @param int                       $page     1-based page number.
	 *
	 * @return array{0: array<string>, 1: int}
	 */
	private static function matching_ids( array $criteria, $page ) {
		global $wpdb;
		$prefix     = $wpdb->prefix;
		$reactions  = $prefix . 'agallery_photo_reactions';
		$tags       = $prefix . 'agallery_photo_tags';
		$subqueries = array(
			'liked_by' => "SELECT image_id FROM {$reactions} WHERE emoji = 'like' AND user_id = %d",
			'person'   => "SELECT image_id FROM {$tags} WHERE category = 'personen' AND tag_key = %s",
			'place'    => "SELECT image_id FROM {$prefix}agallery_photo_places WHERE place = %s",
			'tag'      => "SELECT image_id FROM {$tags} WHERE category <> 'personen' AND tag_key = %s",
		);
		$conditions = array();
		$args       = array();

		foreach ( $criteria as $key => $value ) {
			$conditions[] = 'm.image_id IN (' . $subqueries[ $key ] . ')';
			$args[]       = $value;
		}

		$from = "FROM ( SELECT image_id FROM {$prefix}agallery_photo_reactions
		                UNION SELECT image_id FROM {$prefix}agallery_photo_tags
		                UNION SELECT image_id FROM {$prefix}agallery_photo_places ) m
		         LEFT JOIN {$prefix}agallery_photo_exif_dates d ON d.image_id = m.image_id
		         WHERE " . implode( ' AND ', $conditions ) . "
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

	/**
	 * Users who liked at least one photo, most likes first.
	 *
	 * @return array<array{value: string, label: string, count: int}>
	 */
	private static function likers() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name, no user input.
		$rows = $wpdb->get_results(
			"SELECT user_id, COUNT(*) AS n FROM {$wpdb->prefix}agallery_photo_reactions
			 WHERE emoji = 'like' GROUP BY user_id ORDER BY n DESC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$names = Tag_Log::user_names( array_map( 'intval', array_column( $rows, 'user_id' ) ) );

		return array_map(
			static function ( $row ) use ( $names ) {
				return array(
					'count' => (int) $row->n,
					'label' => $names[ (int) $row->user_id ] ?? '',
					'value' => (string) $row->user_id,
				);
			},
			$rows
		);
	}

	/**
	 * Persons tagged in at least one photo, by name.
	 *
	 * @return array<array{value: string, label: string, count: int}>
	 */
	private static function tagged_persons() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name, no user input.
		$rows = $wpdb->get_results(
			"SELECT tag_key, MAX(member_name) AS name, COUNT(*) AS n FROM {$wpdb->prefix}agallery_photo_tags
			 WHERE category = 'personen' GROUP BY tag_key ORDER BY name"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'count' => (int) $row->n,
					'label' => (string) $row->name,
					'value' => (string) $row->tag_key,
				);
			},
			$rows
		);
	}

	/**
	 * Subject tags used on at least one photo, in vocabulary order, labelled
	 * with their group ("Weer › Regen").
	 *
	 * @return array<array{value: string, label: string, count: int}>
	 */
	private static function used_tags() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name, no user input.
		$counts = $wpdb->get_results(
			"SELECT tag_key, COUNT(*) AS n FROM {$wpdb->prefix}agallery_photo_tags
			 WHERE category <> 'personen' GROUP BY tag_key",
			OBJECT_K
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tags = array();

		foreach ( Subject_Tag_Groups::GROUPS as $group ) {
			foreach ( $group['tags'] as $slug => $label ) {
				if ( ! isset( $counts[ $slug ] ) ) {
					continue;
				}

				$tags[] = array(
					'count' => (int) $counts[ $slug ]->n,
					'label' => $group['label'] . ' › ' . $label,
					'value' => (string) $slug,
				);
			}
		}

		return $tags;
	}

	/**
	 * Places set on at least one photo, most used first.
	 *
	 * @return array<array{value: string, label: string, count: int}>
	 */
	private static function places() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name, no user input.
		$rows = $wpdb->get_results(
			"SELECT place, COUNT(*) AS n FROM {$wpdb->prefix}agallery_photo_places
			 GROUP BY place ORDER BY n DESC, place"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'count' => (int) $row->n,
					'label' => (string) $row->place,
					'value' => (string) $row->place,
				);
			},
			$rows
		);
	}
}
