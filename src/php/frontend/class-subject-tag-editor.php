<?php
/**
 * Contains the Subject_Tag_Editor class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Changes the subject-tag tree (see Subject_Tag_Tree): adding, renaming,
 * moving, reordering and removing sections, groups and tags — for the admin
 * "Tags" page — and seeding it once from the old in-code vocabulary.
 */
final class Subject_Tag_Editor {

	/**
	 * Which node types each type may contain ('' is the top level).
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const CHILD_TYPES = array(
		''        => array( 'section' ),
		'group'   => array( 'tag' ),
		'section' => array( 'section', 'group' ),
		'tag'     => array(),
	);

	/**
	 * The Dutch word for each node type.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const TYPE_NAMES = array(
		'group'   => 'groep',
		'section' => 'sectie',
		'tag'     => 'tag',
	);

	/**
	 * Adds a node at the end of its parent's children.
	 *
	 * @param int|null $parent_id Parent node id (null: top level).
	 * @param string   $type      'section', 'group' or 'tag'.
	 * @param string   $label     Display name.
	 *
	 * @return int|string The new node's id, or an error message.
	 */
	public static function create( $parent_id, $type, $label ) {
		$label = trim( $label );

		if ( '' === $label ) {
			return 'Geef een naam op.';
		}

		$problem = self::placement_problem( $parent_id, $type );

		if ( null !== $problem ) {
			return $problem;
		}

		global $wpdb;
		$siblings = Subject_Tag_Tree::children( $parent_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table.
		$wpdb->insert(
			$wpdb->prefix . 'agallery_tag_nodes',
			array(
				'label'      => mb_substr( $label, 0, 100 ),
				'parent_id'  => $parent_id,
				'sort_order' => array() === $siblings ? 0 : end( $siblings )['sort_order'] + 1,
				'type'       => $type,
			),
			array( '%s', '%d', '%d', '%s' )
		);
		Subject_Tag_Tree::forget();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Renames a node, changes a group's one-tag-per-photo setting, or moves a
	 * node under another parent (at the end of its children).
	 *
	 * @param int                  $node_id Node id.
	 * @param array<string, mixed> $changes Any of label, single, parent_id.
	 *
	 * @return string|null An error message, or null when done.
	 */
	public static function update( $node_id, array $changes ) {
		$node = Subject_Tag_Tree::nodes()[ $node_id ] ?? null;

		if ( null === $node ) {
			return 'Onbekend onderdeel.';
		}

		list( $label_error, $label_fields )   = self::label_change( $changes );
		list( $parent_error, $parent_fields ) = self::parent_change( $node, $changes );
		$error                                = $label_error ?? $parent_error;

		if ( null !== $error ) {
			return $error;
		}

		$fields = $label_fields + $parent_fields;

		if ( isset( $changes['single'] ) && 'group' === $node['type'] ) {
			$fields['single'] = $changes['single'] ? 1 : 0;
		}

		self::write( $node_id, $fields );

		return null;
	}

	/**
	 * Moves a node one place up or down among its siblings.
	 *
	 * @param int $node_id        Node id.
	 * @param int $direction -1 (up) or 1 (down).
	 *
	 * @return void
	 */
	public static function shift( $node_id, $direction ) {
		$node = Subject_Tag_Tree::nodes()[ $node_id ] ?? null;

		if ( null === $node ) {
			return;
		}

		$order    = array_column( Subject_Tag_Tree::children( $node['parent_id'] ), 'id' );
		$position = (int) array_search( $node_id, $order, true );
		$target   = $position + ( 0 > $direction ? -1 : 1 );

		if ( ! isset( $order[ $target ] ) ) {
			return;
		}

		// Swap, then renumber all siblings so equal sort orders can't get stuck.
		list( $order[ $position ], $order[ $target ] ) = array( $order[ $target ], $order[ $position ] );

		foreach ( $order as $index => $sibling_id ) {
			self::write( $sibling_id, array( 'sort_order' => $index ) );
		}
	}

	/**
	 * Removes a node and everything below it, and the tags from photos that
	 * had any of the removed tags.
	 *
	 * @param int $node_id Node id.
	 *
	 * @return int How many tags were taken off photos.
	 */
	public static function delete( $node_id ) {
		global $wpdb;
		$ids  = self::descendants( $node_id );
		$keys = array();

		foreach ( $ids as $node_id ) {
			if ( 'tag' === Subject_Tag_Tree::nodes()[ $node_id ]['type'] ) {
				$keys[] = 't' . $node_id;
			}
		}

		$removed = 0;

		if ( array() !== $keys ) {
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- plugin table, fixed name; one placeholder per key.
			$removed = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}agallery_photo_tags
					 WHERE category = 'subject' AND tag_key IN ({$placeholders})",
					$keys
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		$id_list = implode( ', ', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name; only integers interpolated.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agallery_tag_nodes WHERE id IN ({$id_list})" );
		Subject_Tag_Tree::forget();

		return $removed;
	}

	/**
	 * Updates a node's columns.
	 *
	 * @param int                  $node_id     Node id.
	 * @param array<string, mixed> $fields Columns to set.
	 *
	 * @return void
	 */
	public static function write( $node_id, array $fields ) {
		if ( array() === $fields ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update( $wpdb->prefix . 'agallery_tag_nodes', $fields, array( 'id' => $node_id ) );
		Subject_Tag_Tree::forget();
	}

	/**
	 * The fields for a rename, if one is asked for.
	 *
	 * @param array<string, mixed> $changes Requested changes.
	 *
	 * @return array{0: string|null, 1: array<string, mixed>} An error message, and the fields to write.
	 */
	private static function label_change( array $changes ) {
		if ( ! isset( $changes['label'] ) ) {
			return array( null, array() );
		}

		$label = trim( (string) $changes['label'] );

		if ( '' === $label ) {
			return array( 'Geef een naam op.', array() );
		}

		return array( null, array( 'label' => mb_substr( $label, 0, 100 ) ) );
	}

	/**
	 * The fields for a move, if one is asked for and allowed.
	 *
	 * @param array{id: int, type: string} $node    The node.
	 * @param array<string, mixed>         $changes Requested changes.
	 *
	 * @return array{0: string|null, 1: array<string, mixed>} An error message, and the fields to write.
	 */
	private static function parent_change( array $node, array $changes ) {
		if ( ! array_key_exists( 'parent_id', $changes ) ) {
			return array( null, array() );
		}

		$parent_id = null === $changes['parent_id'] ? null : (int) $changes['parent_id'];
		$problem   = self::move_problem( $node, $parent_id );

		if ( null !== $problem ) {
			return array( $problem, array() );
		}

		$siblings = Subject_Tag_Tree::children( $parent_id );

		return array(
			null,
			array(
				'parent_id'  => $parent_id,
				'sort_order' => array() === $siblings ? 0 : end( $siblings )['sort_order'] + 1,
			),
		);
	}

	/**
	 * Why a node of this type can't go under this parent, if it can't.
	 *
	 * @param int|null $parent_id Parent node id.
	 * @param string   $type      Node type.
	 *
	 * @return string|null
	 */
	private static function placement_problem( $parent_id, $type ) {
		$parent_type = null === $parent_id ? '' : ( Subject_Tag_Tree::nodes()[ $parent_id ]['type'] ?? null );

		if ( null === $parent_type ) {
			return 'Onbekende bovenliggende plek.';
		}

		if ( ! in_array( $type, self::CHILD_TYPES[ $parent_type ] ?? array(), true ) ) {
			$where = '' === $parent_type
				? 'het hoogste niveau'
				: 'een ' . ( self::TYPE_NAMES[ $parent_type ] ?? $parent_type );

			return 'Een ' . ( self::TYPE_NAMES[ $type ] ?? $type ) . ' kan niet onder ' . $where . ' staan.';
		}

		return null;
	}

	/**
	 * Why a node can't move under a new parent, if it can't.
	 *
	 * @param array{id: int, type: string} $node      The node.
	 * @param int|null                     $parent_id The new parent.
	 *
	 * @return string|null
	 */
	private static function move_problem( array $node, $parent_id ) {
		if ( null !== $parent_id && in_array( $parent_id, self::descendants( $node['id'] ), true ) ) {
			return 'Een onderdeel kan niet onder zichzelf geplaatst worden.';
		}

		return self::placement_problem( $parent_id, $node['type'] );
	}

	/**
	 * A node's id and the ids of everything below it.
	 *
	 * @param int $node_id Node id.
	 *
	 * @return array<int>
	 */
	private static function descendants( $node_id ) {
		$ids = array( $node_id );

		foreach ( Subject_Tag_Tree::children( $node_id ) as $child ) {
			$ids = array_merge( $ids, self::descendants( $child['id'] ) );
		}

		return $ids;
	}
}
