<?php
/**
 * Contains the Photo_Marks class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Tag_Log;

/**
 * Marking photos: a selection narrowed down in rounds — mark photos (★),
 * filter on the marked ones, mark the best of those again (★★), and so on
 * — kept per circle (see Mark_Circles). In a group circle all members share
 * one level per photo. In the family circle each user has their own row
 * and the household sees the highest; raising sets your own row above it,
 * lowering brings the whole household's rows down to below it.
 *
 * Logged-in users only (wp_ajax_ hooks); changes need the tag nonce and are
 * recorded in the tag log.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Marks {

	/**
	 * The highest mark level.
	 */
	private const MAX_LEVEL = 5;

	/**
	 * Registers the AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_gallery_mark_circles', array( self::class, 'ajax_circles' ) );
		add_action( 'wp_ajax_gallery_marks', array( self::class, 'ajax_marks' ) );
		add_action( 'wp_ajax_gallery_mark', array( self::class, 'ajax_mark' ) );
	}

	/**
	 * The current user's circles, as [{key, label}].
	 *
	 * @return void
	 */
	public static function ajax_circles() {
		$circles = array();

		foreach ( Mark_Circles::available() as $key => $label ) {
			$circles[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		wp_send_json_success( array( 'circles' => $circles ) );
	}

	/**
	 * The mark levels of some photos in a circle, as {image_id: level};
	 * unmarked photos are left out.
	 *
	 * Query: circle, ids (comma-separated Drive file IDs).
	 *
	 * @return void
	 */
	public static function ajax_marks() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup.
		$circle  = sanitize_text_field( wp_unslash( (string) ( $_GET['circle'] ?? '' ) ) );
		$raw_ids = sanitize_text_field( wp_unslash( (string) ( $_GET['ids'] ?? '' ) ) );
		$ids     = array_filter( explode( ',', $raw_ids ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! Mark_Circles::allowed( $circle ) || array() === $ids ) {
			wp_send_json_success( array( 'levels' => (object) array() ) );
		}

		wp_send_json_success( array( 'levels' => (object) self::levels( $circle, array_slice( $ids, 0, 500 ) ) ) );
	}

	/**
	 * Raises (delta 1) or lowers (delta -1) a photo's mark in a circle.
	 * Returns the new level.
	 *
	 * @return void
	 */
	public static function ajax_mark() {
		check_ajax_referer( 'avpvh_tag_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$circle   = sanitize_text_field( wp_unslash( (string) ( $_POST['circle'] ?? '' ) ) );
		$image_id = sanitize_text_field( wp_unslash( (string) ( $_POST['image_id'] ?? '' ) ) );
		$delta    = 0 > intval( $_POST['delta'] ?? 1 ) ? -1 : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $image_id || ! Mark_Circles::allowed( $circle ) ) {
			wp_send_json_error( array( 'message' => 'Ongeldige markering' ), 400 );
		}

		$current = self::levels( $circle, array( $image_id ) )[ $image_id ] ?? 0;
		$level   = max( 0, min( self::MAX_LEVEL, $current + $delta ) );

		if ( $level !== $current ) {
			self::store( $circle, $image_id, $level );
			Tag_Log::record( $image_id, 'mark', $circle, $level . ' ster(ren)', 0 < $delta ? 'add' : 'remove' );
		}

		wp_send_json_success( array( 'level' => $level ) );
	}

	/**
	 * The visible mark level per photo in a circle (the household's highest
	 * for the family circle).
	 *
	 * @param string        $circle Circle key.
	 * @param array<string> $ids    Drive file IDs.
	 *
	 * @return array<string, int>
	 */
	public static function levels( $circle, array $ids ) {
		global $wpdb;
		$table        = $wpdb->prefix . 'agallery_photo_marks';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		$owner_clause = Mark_Circles::owner_clause( $circle );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- plugin table, fixed name; owner IDs are integers; the rest is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT image_id, MAX(level) AS level FROM {$table}
				 WHERE circle = %s AND {$owner_clause} AND image_id IN ({$placeholders})
				 GROUP BY image_id",
				array_merge( array( $circle ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$levels = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$levels[ (string) $row->image_id ] = (int) $row->level;
		}

		return $levels;
	}

	/**
	 * Saves a photo's new visible level in a circle.
	 *
	 * @param string $circle   Circle key.
	 * @param string $image_id Drive file ID.
	 * @param int    $level    The new level; 0 removes the mark.
	 *
	 * @return void
	 */
	private static function store( $circle, $image_id, $level ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'agallery_photo_marks';
		$owners = Mark_Circles::household_owners( $circle );
		$owner  = null === $owners ? 0 : get_current_user_id();
		$within = Mark_Circles::owner_clause( $circle );

		// Family: nobody in the household may stay above the new level.
		if ( null !== $owners ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed name; owner IDs are integers; the rest is prepared.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE image_id = %s AND circle = %s AND level > %d AND {$within}",
					$image_id,
					$circle,
					$level
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( 0 === $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->delete(
				$table,
				array(
					'circle'   => $circle,
					'image_id' => $image_id,
					'owner'    => $owner,
				),
				array( '%s', '%s', '%d' )
			);

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->replace(
			$table,
			array(
				'circle'     => $circle,
				'image_id'   => $image_id,
				'level'      => $level,
				'owner'      => $owner,
				'updated_by' => get_current_user_id(),
			),
			array( '%s', '%s', '%d', '%d', '%d' )
		);
	}
}
