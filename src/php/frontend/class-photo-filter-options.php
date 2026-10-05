<?php
/**
 * Contains the Photo_Filter_Options class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Tag_Log;

/**
 * What the gallery filter (Photo_Filter) can filter on, each with the
 * number of photos it would find.
 */
final class Photo_Filter_Options {

	/**
	 * All options, per kind of filter.
	 *
	 * @return array<string, array<array{value: string, label: string, count: int}>>
	 */
	public static function all() {
		return array(
			'liked_by' => self::likers(),
			'marked'   => self::marks(),
			'persons'  => self::tagged_persons(),
			'places'   => self::places(),
			'tags'     => self::used_tags(),
		);
	}

	/**
	 * Users who liked at least one photo — of those whose likes the viewer
	 * may see (Like_Visibility) — most likes first.
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
		$rows  = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return Like_Visibility::can_see( (int) $row->user_id );
				}
			)
		);
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
	 * Each number of stars in use ("★ 3 of meer"), counting everyone's
	 * votes together, with how many photos have at least that many.
	 *
	 * @return array<array{value: string, label: string, count: int}>
	 */
	private static function marks() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name; the circle is prepared.
		$totals = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT SUM(level) FROM {$wpdb->prefix}agallery_photo_marks WHERE circle = %s GROUP BY image_id",
				Photo_Marks::CIRCLE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array();

		foreach ( array_map( 'intval', $totals ) as $total ) {
			for ( $stars = 1; $stars <= $total; ++$stars ) {
				$counts[ $stars ] = ( $counts[ $stars ] ?? 0 ) + 1;
			}
		}

		$options = array();

		foreach ( $counts as $stars => $count ) {
			$options[] = array(
				'count' => $count,
				'label' => '★ ' . $stars . ( isset( $counts[ $stars + 1 ] ) ? ' of meer' : '' ),
				'value' => (string) $stars,
			);
		}

		return $options;
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
			"SELECT tag_key, MAX(member_name) AS name, COUNT(DISTINCT image_id) AS n
			 FROM {$wpdb->prefix}agallery_photo_tags
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
		$counts = Subject_Tag_Tree::usage();
		$tags   = array();

		foreach ( Subject_Tag_Tree::groups() as $group ) {
			// Tags directly in a section are labelled with the section.
			$heading = '' !== $group['label'] ? $group['label'] : (string) end( $group['path'] );

			// A taggable group itself (its photos: all of its branch).
			if ( isset( $counts[ $group['key'] ] ) ) {
				$tags[] = array(
					'count' => (int) $counts[ $group['key'] ],
					'label' => end( $group['path'] ) . ' › ' . $group['label'],
					'value' => $group['key'],
				);
			}

			foreach ( $group['tags'] as $key => $label ) {
				if ( ! isset( $counts[ $key ] ) ) {
					continue;
				}

				$tags[] = array(
					'count' => (int) $counts[ $key ],
					'label' => $heading . ' › ' . $label,
					'value' => (string) $key,
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
