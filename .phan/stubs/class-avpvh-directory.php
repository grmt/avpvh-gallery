<?php
/**
 * Minimal stub for the sibling "avpvh-members" plugin's directory (LDAP)
 * class: its groups. Optional at runtime (guarded by class_exists()).
 *
 * @package avpvh-gallery
 */

declare(strict_types = 1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- mirrors avpvh-members' own global class name.

/**
 * Stub for the avpvh-members plugin's directory class.
 */
final class AVPVH_Directory {

	// phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- stub signature only, kept for static-analysis purposes.
	/**
	 * Cached (15 min) group names of one user; [] on error.
	 *
	 * @param string $uid Directory user ID.
	 *
	 * @return array<string>
	 *
	 * @suppress PhanUnusedPublicFinalMethodParameter
	 */
	public static function cached_user_groups( string $uid ): array {
		return array();
	}

	/**
	 * The directory's groups: names, or {id, displayName} records.
	 *
	 * @return array<mixed>|WP_Error
	 */
	public static function list_groups() {
		return array();
	}
}
