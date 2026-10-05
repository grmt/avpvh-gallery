<?php
/**
 * Stubs for avpvh-members' global helper functions, used when that optional
 * sibling plugin is active. Scanned for static analysis only.
 *
 * @package avpvh-gallery
 *
 * @phan-file-suppress PhanUnusedGlobalFunctionParameter
 */

// phpcs:ignoreFile -- mirrors avpvh-members' own (unprefixed) function signatures for static analysis only.

/**
 * A member's display name with the tussenvoegsel in its place.
 *
 * @param object $member A member row.
 * @param string $format 'full', 'list' or 'list_suffix'.
 *
 * @return string
 */
function avpvh_format_name( $member, $format = 'full' ) {
	return '';
}

/**
 * The avpvh-members member record of a WordPress user, if any.
 *
 * @param int $user_id WordPress user ID.
 *
 * @return object|null
 */
function avpvh_get_member_by_wp_user( int $user_id ) {
	return null;
}
