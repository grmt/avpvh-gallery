<?php
/**
 * Minimal stub for the sibling "avpvh-members" plugin's archive access
 * class: who sees which years. Optional at runtime (guarded by
 * class_exists()).
 *
 * @package avpvh-gallery
 */

declare(strict_types = 1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- mirrors avpvh-members' own global class name.

/**
 * Stub for the avpvh-members plugin's archive access class.
 */
final class AVPVH_Archive_Access {

	public const ALL = 'all';

	public const NONE = 'none';

	/**
	 * The current user's scope: ALL, NONE, or a period of years.
	 *
	 * @return string|array{from: int, to: int}
	 */
	public static function current_scope() {
		return self::NONE;
	}
}
