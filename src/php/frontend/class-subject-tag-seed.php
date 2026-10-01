<?php
/**
 * Contains the Subject_Tag_Seed class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Fills the subject-tag tree (Subject_Tag_Tree) once from the vocabulary
 * that used to live in code (Subject_Tag_Groups), and converts tags already
 * on photos from their old slugs to the tree's keys.
 */
final class Subject_Tag_Seed {

	/**
	 * Fills the tree from the vocabulary that used to live in code, and
	 * converts tags already on photos (stored by slug) to the new keys. Runs
	 * once: does nothing when the tree has nodes.
	 *
	 * @return void
	 */
	public static function seed_from_code() {
		Subject_Tag_Tree::forget();

		if ( array() !== Subject_Tag_Tree::nodes() ) {
			return;
		}

		$slugs = array();

		foreach ( Subject_Tag_Groups::GROUPS as $group_key => $group ) {
			$parent_id = null;

			foreach ( $group['path'] as $section ) {
				$parent_id = self::find_or_create( $parent_id, 'section', $section );
			}

			$group_id = (int) Subject_Tag_Editor::create( $parent_id, 'group', $group['label'] );
			Subject_Tag_Editor::write(
				$group_id,
				array(
					'legacy_key' => $group_key,
					'single'     => $group['single'] ? 1 : 0,
				)
			);

			foreach ( $group['tags'] as $slug => $label ) {
				$tag_id = (int) Subject_Tag_Editor::create( $group_id, 'tag', $label );
				Subject_Tag_Editor::write( $tag_id, array( 'legacy_key' => $slug ) );
				$slugs[ $slug ] = 't' . $tag_id;
			}
		}

		self::convert_photo_tags( $slugs );
	}

	/**
	 * A child node with this type and label, created if missing.
	 *
	 * @param int|null $parent_id Parent node id.
	 * @param string   $type      Node type.
	 * @param string   $label     Display name.
	 *
	 * @return int
	 */
	private static function find_or_create( $parent_id, $type, $label ) {
		foreach ( Subject_Tag_Tree::children( $parent_id ) as $node ) {
			if ( $node['type'] === $type && $node['label'] === $label ) {
				return $node['id'];
			}
		}

		return (int) Subject_Tag_Editor::create( $parent_id, $type, $label );
	}

	/**
	 * Re-keys subject tags on photos from their old slug to the new tag key.
	 * Where one user had the same tag twice (under two old group keys), the
	 * duplicate is dropped.
	 *
	 * @param array<string, string> $slugs Old slug => new key.
	 *
	 * @return void
	 */
	private static function convert_photo_tags( array $slugs ) {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_tags';

		foreach ( $slugs as $slug => $key ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name; values prepared.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE IGNORE {$table} SET category = 'subject', tag_key = %s
					 WHERE category NOT IN ('personen', 'subject') AND tag_key = %s",
					$key,
					$slug
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE category NOT IN ('personen', 'subject') AND tag_key = %s",
					$slug
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
