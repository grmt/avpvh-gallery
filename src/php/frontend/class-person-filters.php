<?php
/**
 * Contains the Person_Filters class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Narrows the persons list offered when tagging a photo to who could
 * plausibly be in it, given the year the photo's folder belongs to: the
 * persons who were members then, the archaeologists, and — to leave out
 * entirely — anyone born after that year.
 *
 * Reads avpvh-members' tables directly (like Activity_Participants), and
 * returns nothing when that plugin isn't active. Its data is incomplete
 * (not everyone has a birth date or membership years), so every filter
 * errs on the side of keeping a person: unknown means "not excluded".
 */
final class Person_Filters {

	/**
	 * Person ID lists for a photo year.
	 *
	 * @param int|null $year The photo's year, if known.
	 *
	 * @return array{year: int|null, members_then: array<int>, archaeologists: array<int>, born_after: array<int>}
	 */
	public static function for_year( $year ) {
		$result = array(
			'archaeologists' => array(),
			'born_after'     => array(),
			'members_then'   => array(),
			'year'           => $year,
		);

		if ( ! class_exists( '\\AVPVH_DB' ) ) {
			return $result;
		}

		$result['archaeologists'] = self::archaeologists();

		if ( null !== $year ) {
			$result['members_then'] = self::members_in( $year );
			$result['born_after']   = self::born_after( $year );
		}

		return $result;
	}

	/**
	 * Persons with the "Archeoloog" flag.
	 *
	 * @return array<int>
	 */
	private static function archaeologists() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- avpvh-members' tables, fixed names; no user input.
		$ids = $wpdb->get_col(
			"SELECT a.member_id FROM {$wpdb->prefix}avm_member_flag_assignments a
			 INNER JOIN {$wpdb->prefix}avm_member_flags f ON f.id = a.flag_id
			 WHERE f.slug = 'archeoloog'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', $ids );
	}

	/**
	 * Persons who were members during the year: their joined/left years
	 * cover it, or one of their dated addresses (from the historical member
	 * lists) was valid during it. For photos from this year or last year,
	 * current active members with no known joined year count too.
	 *
	 * @param int $year The photo's year.
	 *
	 * @return array<int>
	 */
	private static function members_in( $year ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- avpvh-members' tables, fixed names; the year is prepared.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.id FROM {$wpdb->prefix}avm_members m
				 WHERE ( m.joined_year IS NOT NULL AND m.joined_year <= %d
				         AND ( m.left_year IS NULL OR m.left_year >= %d ) )
				    OR EXISTS (
				         SELECT 1 FROM {$wpdb->prefix}avm_addresses a
				         WHERE a.member_id = m.id AND a.valid_from IS NOT NULL
				           AND a.valid_from <= %s
				           AND ( a.valid_until IS NULL OR a.valid_until >= %s ) )
				    OR ( m.status = 'active' AND m.joined_year IS NULL AND %d >= YEAR( CURDATE() ) - 1 )",
				$year,
				$year,
				$year . '-12-31',
				$year . '-01-01',
				$year
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', $ids );
	}

	/**
	 * Persons known to be born after the year — they can't be in the photo.
	 *
	 * @param int $year The photo's year.
	 *
	 * @return array<int>
	 */
	private static function born_after( $year ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- avpvh-members' table, fixed name; the year is prepared.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}avm_members
				 WHERE ( birth_date IS NOT NULL AND YEAR( birth_date ) > %d )
				    OR ( birth_date IS NULL AND birth_year IS NOT NULL AND birth_year > %d )",
				$year,
				$year
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', $ids );
	}
}
