<?php
/**
 * Contains the Mark_Circles class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * The circles a user can mark photos in (see Photo_Marks): their family —
 * the household avpvh-members knows (Like_Visibility), each user keeping
 * their own marks and the household seeing them together — and every LDAP
 * group they're in, whose members share one selection. LLDAP's own system
 * groups (directory permissions, not groups of people) and the groups of
 * other sites sharing the directory are left out.
 */
final class Mark_Circles {

	/**
	 * LLDAP's built-in permission groups.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const SYSTEM_GROUPS = array( 'lldap_admin', 'lldap_password_manager', 'lldap_strict_readonly' );

	/**
	 * Name prefixes of groups that belong to other sites using the same
	 * directory (vp4042-…: vp4042.vve.rechtspreker.nl).
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const OTHER_SITE_PREFIXES = array( 'vp4042' );

	/**
	 * The current user's circles: key => label.
	 *
	 * @return array<string, string>
	 */
	public static function available() {
		$circles = array( 'family' => 'Familie' );

		foreach ( Exclusion_Permission::current_group_names() as $group ) {
			if ( ! self::is_ours( $group ) ) {
				continue;
			}

			$circles[ 'group:' . $group ] = ucfirst( $group );
		}

		return $circles;
	}

	/**
	 * Whether the current user may use a circle.
	 *
	 * @param string $circle Circle key.
	 *
	 * @return bool
	 */
	public static function allowed( $circle ) {
		return isset( self::available()[ $circle ] );
	}

	/**
	 * The WordPress users whose family marks the current user sees — their
	 * household — or null for a group circle (one shared row, owner 0).
	 *
	 * @param string $circle Circle key.
	 *
	 * @return array<int>|null
	 */
	public static function household_owners( $circle ) {
		if ( 'family' !== $circle ) {
			return null;
		}

		$household = Like_Visibility::household_of_current_user();

		return array() === $household ? array( get_current_user_id() ) : $household;
	}

	/**
	 * SQL restricting marks to the rows the current user sees in a circle:
	 * their household's for the family circle, the shared row (owner 0) for
	 * a group. Only integers are interpolated.
	 *
	 * @param string $circle Circle key.
	 *
	 * @return string
	 */
	public static function owner_clause( $circle ) {
		$owners = self::household_owners( $circle );

		if ( null === $owners ) {
			return 'owner = 0';
		}

		return 'owner IN (' . implode( ', ', array_map( 'intval', $owners ) ) . ')';
	}

	/**
	 * Whether an LDAP group is a group of people on this site.
	 *
	 * @param string $group Group name.
	 *
	 * @return bool
	 */
	private static function is_ours( $group ) {
		if ( '' === $group || in_array( $group, self::SYSTEM_GROUPS, true ) ) {
			return false;
		}

		foreach ( self::OTHER_SITE_PREFIXES as $prefix ) {
			if ( str_starts_with( $group, $prefix ) ) {
				return false;
			}
		}

		return true;
	}
}
