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

namespace AVPVH;

/**
 * Stub for the avpvh-members plugin's member-lookup class.
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
	// phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.CodeAnalysis.UnusedFunctionParameter.Found
}
