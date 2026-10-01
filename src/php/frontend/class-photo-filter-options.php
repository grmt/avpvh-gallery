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
