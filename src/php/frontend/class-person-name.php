<?php
/**
 * Contains the Person_Name class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * A member's display name with the tussenvoegsel in its place ("Germie van
 * den Berg", "Guy De Boe"), however avpvh-members stores it: in its own
 * suffix column, or — in older rows — glued into the last name as "Berg,
 * van den". Uses avpvh-members' own avpvh_format_name() when available.
 */
final class Person_Name {

	/**
	 * The full display name.
	 *
	 * @param object $member A row with first_name, last_name and (optionally) suffix.
	 *
	 * @return string
	 */
	public static function format( $member ) {
		if ( function_exists( 'avpvh_format_name' ) ) {
			return trim( avpvh_format_name( $member ) );
		}

		$first  = isset( $member->first_name ) ? (string) $member->first_name : '';
		$suffix = isset( $member->suffix ) ? trim( (string) $member->suffix ) : '';
		$last   = isset( $member->last_name ) ? (string) $member->last_name : '';

		if ( '' === $suffix && str_contains( $last, ',' ) ) {
			list( $last, $suffix ) = array_map( 'trim', explode( ',', $last, 2 ) );
		}

		return trim( preg_replace( '/\s+/', ' ', "{$first} {$suffix} {$last}" ) ?? '' );
	}
}
