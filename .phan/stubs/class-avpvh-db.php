<?php
/**
 * Minimal stub for the sibling "avpvh-members" plugin's member-lookup class.
 * That plugin is an optional runtime dependency (guarded by class_exists()
 * checks throughout this codebase) and isn't a Composer package, so it has
 * no stub source of its own.
 *
 * @package avpvh-gallery
 */

declare(strict_types = 1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile -- mirrors avpvh-members' own global class names.

/**
 * Stub for the avpvh-members plugin's member-lookup class. Like the real
 * one, it's in the global namespace — declaring it anywhere else lets a
 * wrong lookup (e.g. '\\AVPVH\\AVPVH_DB') pass static analysis while
 * class_exists() fails at runtime.
 */
final class AVPVH_DB {

	// phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- stub signature only, kept for static-analysis purposes.
	/**
	 * Fetches members matching the given query args.
	 *
	 * @param array<string, mixed> $args Query args (real implementation lives in the avpvh-members plugin).
	 *
	 * @return array<int, object{id: int, first_name: string, last_name: string, status: string}>
	 */
	public static function get_members( array $args = array() ) {
		// Stub body only; the real implementation lives in the avpvh-members plugin.
		return array();
	}

	/**
	 * The member linked to a WordPress user, if any.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return object{id: int, wp_user_id: int|null}|null
	 */
	public static function get_member_by_wp_user( int $user_id ) {
		return null;
	}

	/**
	 * The active members of a member's household, including themselves.
	 *
	 * @param int $member_id Member ID.
	 *
	 * @return array<int, object{id: int, wp_user_id: int|null}>
	 */
	public static function get_manageable_members( int $member_id ) {
		return array();
	}

	/**
	 * The household plus each household member's partner.
	 *
	 * @param int $member_id Member ID.
	 *
	 * @return array<int, object{id: int, wp_user_id: int|null}>
	 */
	public static function get_extended_household( int $member_id ) {
		return array();
	}
	// phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.CodeAnalysis.UnusedFunctionParameter.Found
}

/**
 * Stub for avpvh-members' club-role checks.
 */
final class AVPVH_Roles {

	/**
	 * Whether the current user holds the IT administrator role.
	 *
	 * @return bool
	 */
	public static function current_user_is_it_admin() {
		return false;
	}
}
