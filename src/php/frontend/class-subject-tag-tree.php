<?php
/**
 * Contains the Subject_Tag_Tree class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * The subject-tag vocabulary as an editable tree, stored in
 * agallery_tag_nodes: sections (Wie, Wat, Waar …) hold sections and groups,
 * groups hold tags. A group may allow one tag per photo ("single").
 *
 * Photos refer to a tag by a key made from its id ("t12", stored as the
 * tag row's tag_key with category "subject"), so tags can be renamed and
 * moved without touching tagged photos. The "t" keeps the browser from
 * re-sorting tags by number.
 *
 * Sections may also hold tags directly (Plek › Opgraving next to the group
 * Plek › Kamp).
 *
 * Read-only here; changed through Subject_Tag_Editor (the admin "Tags"
 * page), which also seeded it from the old in-code vocabulary.
 */
final class Subject_Tag_Tree {

	/**
	 * The nodes, loaded once per request (null: not loaded yet).
	 *
	 * @var array<int, array{id: int, parent_id: int|null, type: string, label: string, single: bool, sort_order: int}>|null
	 */
	private static $nodes = null;

	/**
	 * All nodes by id, in display order.
	 *
	 * @return array<int, array{id: int, parent_id: int|null, type: string, label: string, single: bool, sort_order: int}>
	 */
	public static function nodes() {
		$nodes       = self::$nodes ?? self::load();
		self::$nodes = $nodes;

		return $nodes;
	}

	/**
	 * Forgets the loaded nodes, after Subject_Tag_Editor changed them.
	 *
	 * @return void
	 */
	public static function forget() {
		self::$nodes = null;
	}

	/**
	 * The children of a node (null: the top level), in display order.
	 *
	 * @param int|null $parent_id Parent node id.
	 *
	 * @return array<array{id: int, parent_id: int|null, type: string, label: string, single: bool, sort_order: int}>
	 */
	public static function children( $parent_id ) {
		return array_values(
			array_filter(
				self::nodes(),
				static function ( $node ) use ( $parent_id ) {
					return $node['parent_id'] === $parent_id;
				}
			)
		);
	}

	/**
	 * The vocabulary for the tagging panel and the filter: per group (in
	 * display order), its label, the labels of the sections above it, whether
	 * it allows one tag per photo, and its tags (key => label).
	 *
	 * Tags placed directly in a section (Waar › Plek › Opgraving) come as a
	 * group without a label ("s<section id>"), listed before the section's
	 * own groups and subsections.
	 *
	 * @return array<string, array{label: string, path: array<string>, single: bool, tags: array<string, string>}>
	 */
	public static function groups() {
		return self::collect_groups( null, array() );
	}

	/**
	 * The tag a key ("t12") stands for, if it exists.
	 *
	 * @param string $key Tag key.
	 *
	 * @return array{id: int, parent_id: int|null, type: string, label: string, single: bool, sort_order: int}|null
	 */
	public static function tag( $key ) {
		$node_id = 1 === preg_match( '/^t(\d+)$/', $key, $matches ) ? (int) $matches[1] : 0;
		$nodes   = self::nodes();

		return isset( $nodes[ $node_id ] ) && 'tag' === $nodes[ $node_id ]['type'] ? $nodes[ $node_id ] : null;
	}

	/**
	 * The keys of the tags in the same group as a tag (itself included).
	 *
	 * @param string $key Tag key.
	 *
	 * @return array<string>
	 */
	public static function siblings( $key ) {
		$tag = self::tag( $key );

		if ( null === $tag ) {
			return array();
		}

		return array_map(
			static function ( $node ) {
				return 't' . $node['id'];
			},
			self::children( $tag['parent_id'] )
		);
	}

	/**
	 * Whether a tag's group allows only one tag per photo.
	 *
	 * @param string $key Tag key.
	 *
	 * @return bool
	 */
	public static function single( $key ) {
		$tag   = self::tag( $key );
		$nodes = self::nodes();

		return null !== $tag && null !== $tag['parent_id'] && ( $nodes[ $tag['parent_id'] ]['single'] ?? false );
	}

	/**
	 * How many photo tags each tag has (key => count).
	 *
	 * @return array<string, int>
	 */
	public static function usage() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name, no user input.
		$rows = $wpdb->get_results(
			"SELECT tag_key, COUNT(DISTINCT image_id) AS n FROM {$wpdb->prefix}agallery_photo_tags
			 WHERE category = 'subject' GROUP BY tag_key",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_column( is_array( $rows ) ? $rows : array(), 'n', 'tag_key' );
	}

	/**
	 * Reads all nodes, by id, in display order.
	 *
	 * @return array<int, array{id: int, parent_id: int|null, type: string, label: string, single: bool, sort_order: int}>
	 */
	private static function load() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name; cached per request by nodes().
		$rows = $wpdb->get_results(
			"SELECT id, parent_id, type, label, single, sort_order FROM {$wpdb->prefix}agallery_tag_nodes
			 ORDER BY sort_order, id",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$nodes = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$nodes[ (int) $row['id'] ] = array(
				'id'         => (int) $row['id'],
				'label'      => (string) $row['label'],
				'parent_id'  => null === $row['parent_id'] ? null : (int) $row['parent_id'],
				'single'     => (bool) (int) $row['single'],
				'sort_order' => (int) $row['sort_order'],
				'type'       => (string) $row['type'],
			);
		}

		return $nodes;
	}

	/**
	 * The groups below a parent, depth-first in display order (for groups()).
	 *
	 * @param int|null      $parent_id Where to start.
	 * @param array<string> $path      Section labels above it.
	 *
	 * @return array<string, array{label: string, path: array<string>, single: bool, tags: array<string, string>}>
	 */
	private static function collect_groups( $parent_id, array $path ) {
		$groups = self::loose_tags( $parent_id, $path );

		foreach ( self::children( $parent_id ) as $node ) {
			if ( 'section' === $node['type'] ) {
				$groups += self::collect_groups( $node['id'], array_merge( $path, array( $node['label'] ) ) );

				continue;
			}

			if ( 'group' !== $node['type'] ) {
				continue;
			}

			$groups[ 'g' . $node['id'] ] = array(
				'label'  => $node['label'],
				'path'   => $path,
				'single' => $node['single'],
				'tags'   => self::tags_below( $node['id'] ),
			);
		}

		return $groups;
	}

	/**
	 * The tags placed directly in a section, as a group without a label (for
	 * collect_groups()); none at the top level.
	 *
	 * @param int|null      $section_id Section id.
	 * @param array<string> $path       Section labels down to and including it.
	 *
	 * @return array<string, array{label: string, path: array<string>, single: bool, tags: array<string, string>}>
	 */
	private static function loose_tags( $section_id, array $path ) {
		$tags = null === $section_id ? array() : self::tags_below( $section_id );

		if ( array() === $tags ) {
			return array();
		}

		return array(
			's' . $section_id => array(
				'label'  => '',
				'path'   => $path,
				'single' => false,
				'tags'   => $tags,
			),
		);
	}

	/**
	 * The tags directly below a node (key => label), in display order.
	 *
	 * @param int $parent_id Parent node id.
	 *
	 * @return array<string, string>
	 */
	private static function tags_below( $parent_id ) {
		$tags = array();

		foreach ( self::children( $parent_id ) as $node ) {
			if ( 'tag' === $node['type'] ) {
				$tags[ 't' . $node['id'] ] = $node['label'];
			}
		}

		return $tags;
	}
}
