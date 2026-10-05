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

use Avpvh\Options;
use Avpvh\Tag_Log;

/**
 * Marking photos as voting with stars (★): every logged-in user may give a
 * photo a few stars (Options::$mark_votes_per_person), a photo gets at most
 * so many from everyone together (Options::$mark_votes_per_photo), and
 * everyone sees everyone's stars — but only takes back their own. Filter on
 * "Gemarkeerd" to see the photos with at least so many stars.
 *
 * One row per photo and voter in agallery_photo_marks (owner = the voter,
 * level = their stars, circle always 'votes').
 *
 * Logged-in users only (wp_ajax_ hooks); changes need the tag nonce and are
 * recorded in the tag log.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Marks {

	/**
	 * The circle column's value for every vote.
	 */
	public const CIRCLE = 'votes';

	/**
	 * Registers the AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_gallery_marks', array( self::class, 'ajax_marks' ) );
		add_action( 'wp_ajax_gallery_mark', array( self::class, 'ajax_mark' ) );
	}

	/**
	 * The stars on some photos, as {image_id: {mine, others, voters}};
	 * photos without stars are left out.
	 *
	 * Query: ids (comma-separated Drive file IDs).
	 *
	 * @return void
	 */
	public static function ajax_marks() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
		$raw_ids = sanitize_text_field( wp_unslash( (string) ( $_GET['ids'] ?? '' ) ) );
		$ids     = array_slice( array_filter( explode( ',', $raw_ids ) ), 0, 500 );

		wp_send_json_success( array( 'marks' => (object) ( array() === $ids ? array() : self::tallies( $ids ) ) ) );
	}

	/**
	 * Adds (delta 1) or takes back (delta -1) one of the current user's stars
	 * on a photo. Returns the photo's new tally, or an error saying which
	 * limit was reached.
	 *
	 * @return void
	 */
	public static function ajax_mark() {
		check_ajax_referer( 'avpvh_tag_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$image_id = sanitize_text_field( wp_unslash( (string) ( $_POST['image_id'] ?? '' ) ) );
		$delta    = 0 > intval( $_POST['delta'] ?? 1 ) ? -1 : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $image_id ) {
			wp_send_json_error( array( 'message' => 'Ongeldige markering' ), 400 );
		}

		$tally   = self::tallies( array( $image_id ) )[ $image_id ] ?? self::empty_tally();
		$refusal = self::refusal( $tally, $delta );

		if ( null !== $refusal ) {
			wp_send_json_error( array( 'message' => $refusal ), 409 );
		}

		$level = $tally['mine'] + $delta;
		self::store( $image_id, $level );
		Tag_Log::record( $image_id, 'mark', self::CIRCLE, $level . ' ster(ren)', 0 < $delta ? 'add' : 'remove' );

		wp_send_json_success( array( 'mark' => self::tallies( array( $image_id ) )[ $image_id ] ?? self::empty_tally() ) );
	}

	/**
	 * Why a star can't be added or taken back, or null if it can.
	 *
	 * @param array{mine: int, others: int, voters: string} $tally The photo's current tally.
	 * @param int                                           $delta 1 or -1.
	 *
	 * @return string|null
	 */
	private static function refusal( array $tally, $delta ) {
		if ( 0 > $delta ) {
			return 0 === $tally['mine'] ? 'Je hebt deze foto geen ster gegeven' : null;
		}

		$per_person = max( 1, (int) Options::$mark_votes_per_person->get() );
		$per_photo  = max( 1, (int) Options::$mark_votes_per_photo->get() );

		if ( $tally['mine'] >= $per_person ) {
			return sprintf( 'Je kunt een foto hooguit %d ster%s geven', $per_person, 1 === $per_person ? '' : 'ren' );
		}

		if ( $tally['mine'] + $tally['others'] >= $per_photo ) {
			return sprintf( 'Deze foto heeft al het maximum van %d sterren', $per_photo );
		}

		return null;
	}

	/**
	 * A tally for a photo without stars.
	 *
	 * @return array{mine: int, others: int, voters: string}
	 */
	private static function empty_tally() {
		return array(
			'mine'   => 0,
			'others' => 0,
			'voters' => '',
		);
	}

	/**
	 * Per photo: the current user's stars, everyone else's, and who gave
	 * how many ("Annet 2, Lisette 1" — see short_names()).
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, array{mine: int, others: int, voters: string}>
	 */
	public static function tallies( array $ids ) {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- plugin table, fixed name; the rest is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT image_id, owner, level FROM {$wpdb->prefix}agallery_photo_marks
				 WHERE circle = %s AND level > 0 AND image_id IN ({$placeholders})
				 ORDER BY level DESC, owner",
				array_merge( array( self::CIRCLE ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows    = is_array( $rows ) ? $rows : array();
		$names   = self::short_names();
		$me      = get_current_user_id();
		$tallies = array();

		foreach ( $rows as $row ) {
			$image_id             = (string) $row->image_id;
			$tallies[ $image_id ] = $tallies[ $image_id ] ?? self::empty_tally();
			$key                  = (int) $row->owner === $me ? 'mine' : 'others';

			$tallies[ $image_id ][ $key ]    += (int) $row->level;
			$tallies[ $image_id ]['voters'] .= ( '' === $tallies[ $image_id ]['voters'] ? '' : ', ' )
				. ( $names[ (int) $row->owner ] ?? '?' ) . ' ' . (int) $row->level;
		}

		return $tallies;
	}

	/**
	 * Every user's first name, followed by the initial of their last name
	 * when someone else has the same first name ("Annet", "Garmt B.").
	 * Names come from avpvh-members when the user is a member, else from the
	 * display name. Cached for ten minutes.
	 *
	 * @return array<int, string> User ID => short name.
	 */
	private static function short_names() {
		$cached = get_transient( 'avpvh_voter_names' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$parts = array();

		foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
			$parts[ (int) $user->ID ] = self::name_parts( (int) $user->ID, (string) $user->display_name );
		}

		$firsts = array_count_values( array_map( 'mb_strtolower', array_column( $parts, 0 ) ) );
		$names  = array();

		foreach ( $parts as $user_id => list( $first, $last ) ) {
			$names[ $user_id ] = 1 < $firsts[ mb_strtolower( $first ) ] && '' !== $last
				? $first . ' ' . mb_strtoupper( mb_substr( $last, 0, 1 ) ) . '.'
				: $first;
		}

		set_transient( 'avpvh_voter_names', $names, 10 * MINUTE_IN_SECONDS );

		return $names;
	}

	/**
	 * A user's first and last name (without tussenvoegsel).
	 *
	 * @param int    $user_id      WordPress user ID.
	 * @param string $display_name Their display name, used when they aren't a member.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function name_parts( $user_id, $display_name ) {
		$member = function_exists( 'avpvh_get_member_by_wp_user' ) ? avpvh_get_member_by_wp_user( $user_id ) : null;

		if ( is_object( $member ) && '' !== trim( (string) ( $member->first_name ?? '' ) ) ) {
			return array( trim( (string) $member->first_name ), trim( (string) ( $member->last_name ?? '' ) ) );
		}

		$words = preg_split( '/\s+/', trim( $display_name ) );
		$words = false === $words ? array() : $words;

		return array( (string) ( $words[0] ?? '' ), (string) ( $words[1] ?? '' ) );
	}

	/**
	 * Saves the current user's stars on a photo.
	 *
	 * @param string $image_id Drive file ID.
	 * @param int    $level    Their stars; 0 removes their vote.
	 *
	 * @return void
	 */
	private static function store( $image_id, $level ) {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_marks';
		$key   = array(
			'circle'   => self::CIRCLE,
			'image_id' => $image_id,
			'owner'    => get_current_user_id(),
		);

		if ( 0 === $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->delete( $table, $key, array( '%s', '%s', '%d' ) );

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->replace(
			$table,
			array_merge(
				$key,
				array(
					'level'      => $level,
					'updated_by' => get_current_user_id(),
				)
			),
			array( '%s', '%s', '%d', '%d', '%d' )
		);
	}
}
