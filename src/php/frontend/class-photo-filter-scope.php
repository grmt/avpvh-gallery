<?php
/**
 * Contains the Photo_Filter_Scope class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Client;
use Avpvh\API_Facade;

/**
 * Narrows filter results to one folder and its subfolders ("Alleen deze
 * map"). The tag tables only know photo IDs, so each photo's folder chain
 * is looked up on Drive, level by level, in batched requests. What's
 * learned (file or folder → parent) is kept for a day: photos and folders
 * rarely move, and a later filter on the same photos then needs no Drive
 * calls at all.
 */
final class Photo_Filter_Scope {

	/**
	 * Where the file → parent map is kept.
	 */
	private const CACHE_KEY = 'avpvh_filter_parents';

	/**
	 * How many folder levels to climb at most.
	 */
	private const MAX_DEPTH = 10;

	/**
	 * Sanitizes a JSON list of Drive folder IDs (each may start with "!",
	 * meaning left out; see within_many()).
	 *
	 * @param string $json JSON list.
	 *
	 * @return array<string>
	 */
	public static function folder_ids( $json ) {
		$decoded    = json_decode( $json, true );
		$folder_ids = array();

		foreach ( array_slice( is_array( $decoded ) ? $decoded : array(), 0, 50 ) as $folder_id ) {
			$clean = sanitize_text_field( (string) $folder_id );

			if ( '' !== $clean ) {
				$folder_ids[] = $clean;
			}
		}

		return array_values( array_unique( $folder_ids ) );
	}

	/**
	 * Keeps the IDs inside the chosen folders, preserving order. Each
	 * chosen folder takes in or (with a "!" before its ID) leaves out its
	 * whole branch; they come ordered from the top down, so a choice deeper
	 * down overrules one above it ("03-Weekenden" but not "2024 Meerveld").
	 * With only folders left out, the rest of the gallery counts.
	 *
	 * @param array<string> $ids        Photo IDs.
	 * @param array<string> $folder_ids Chosen Drive folder IDs, top down.
	 *
	 * @return array<string>
	 */
	public static function within_many( array $ids, array $folder_ids ) {
		$included = array_filter(
			$folder_ids,
			static function ( $folder_id ) {
				return '!' !== substr( $folder_id, 0, 1 );
			}
		);
		$kept     = array() === $included ? array_fill_keys( $ids, true ) : array();

		foreach ( $folder_ids as $folder_id ) {
			$leave_out = '!' === substr( $folder_id, 0, 1 );
			$branch    = self::within( $ids, $leave_out ? substr( $folder_id, 1 ) : $folder_id );
			$kept      = $leave_out
				? array_diff_key( $kept, array_flip( $branch ) )
				: $kept + array_fill_keys( $branch, true );
		}

		return array_values(
			array_filter(
				$ids,
				static function ( $photo_id ) use ( $kept ) {
					return isset( $kept[ $photo_id ] );
				}
			)
		);
	}

	/**
	 * The photos that are in a folder or anywhere below it, in their
	 * original order.
	 *
	 * @param array<string> $ids       Drive file IDs.
	 * @param string        $folder_id Drive folder ID.
	 *
	 * @return array<string>
	 */
	public static function within( array $ids, $folder_id ) {
		$cached  = get_transient( self::CACHE_KEY );
		$parents = is_array( $cached ) ? $cached : array();
		// Per photo, the folder reached so far on its way up.
		$climbing = array_combine( $ids, $ids );
		$inside   = array();

		for ( $level = 0; $level < self::MAX_DEPTH && array() !== $climbing; ++$level ) {
			$parents                    = self::with_parents_of( array_unique( array_values( $climbing ) ), $parents );
			list( $climbing, $arrived ) = self::climb( $climbing, $parents, $folder_id );
			$inside                    += $arrived;
		}

		set_transient( self::CACHE_KEY, $parents, DAY_IN_SECONDS );

		return array_values(
			array_filter(
				$ids,
				static function ( $file_id ) use ( $inside ) {
					return isset( $inside[ $file_id ] );
				}
			)
		);
	}

	/**
	 * Each file's parent folder ('' when unknown), from the same cache.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, string>
	 */
	public static function parents( array $ids ) {
		$cached  = get_transient( self::CACHE_KEY );
		$parents = self::with_parents_of( $ids, is_array( $cached ) ? $cached : array() );
		set_transient( self::CACHE_KEY, $parents, DAY_IN_SECONDS );

		return array_intersect_key( $parents, array_flip( $ids ) );
	}

	/**
	 * Folder display paths below the gallery root, using the same prefix
	 * trimming as breadcrumbs. Shared ancestors are fetched once per level;
	 * names and parents use the existing caches across result pages.
	 *
	 * @param array<string> $folder_ids Photo folder IDs.
	 * @param string        $root_id    Gallery root folder ID.
	 * @param Options_Proxy $options    Gallery options.
	 *
	 * @return array<string, string> Folder ID => display path.
	 */
	public static function paths( array $folder_ids, $root_id, $options ) {
		$parts    = array_fill_keys( $folder_ids, array() );
		$climbing = array_combine( $folder_ids, $folder_ids );
		$prefix   = (string) $options->get( 'dir_prefix' );

		// Stop at the gallery root, including for photos directly in it.
		$below_root = static function ( $folder_id ) use ( $root_id ) {
			return '' !== $folder_id && $root_id !== $folder_id;
		};
		$climbing   = array_filter( $climbing, $below_root );

		// Bound malformed/cyclic parent chains without limiting normal paths
		// to the shallower depth used for filter membership checks.
		for ( $level = 0; $level < 50 && array() !== $climbing; ++$level ) {
			$current = array_values( array_unique( $climbing ) );
			$names   = array_map(
				static function ( $name ) use ( $prefix ) {
					$position = '' === $prefix ? false : mb_strpos( $name, $prefix );

					return mb_substr( $name, false === $position ? 0 : $position + 1 );
				},
				Photo_Date_Order::folder_names( $current )
			);
			$parents = self::parents( $current );

			foreach ( $climbing as $folder_id => $ancestor_id ) {
				$parts[ $folder_id ][]  = $names[ $ancestor_id ] ?? '';
				$climbing[ $folder_id ] = $parents[ $ancestor_id ] ?? '';
			}

			$climbing = array_filter( $climbing, $below_root );
		}

		return array_map(
			static function ( $segments ) {
				return implode( '/', array_reverse( $segments ) );
			},
			$parts
		);
	}

	/**
	 * Moves every photo one folder up: the photos whose next folder is the
	 * one asked for have arrived; those at the top are dropped.
	 *
	 * @param array<string, string> $climbing  Per photo, the folder reached so far.
	 * @param array<string, string> $parents   File or folder → parent.
	 * @param string                $folder_id The folder asked for.
	 *
	 * @return array{0: array<string, string>, 1: array<string, bool>} Still climbing, then arrived.
	 */
	private static function climb( array $climbing, array $parents, $folder_id ) {
		$arrived = array();

		foreach ( $climbing as $photo => $node ) {
			$parent = $parents[ $node ] ?? '';

			if ( $parent === $folder_id ) {
				$arrived[ $photo ] = true;
			}

			if ( $parent === $folder_id || '' === $parent ) {
				unset( $climbing[ $photo ] );

				continue;
			}

			$climbing[ $photo ] = $parent;
		}

		return array( $climbing, $arrived );
	}

	/**
	 * The parent map, with the parents of the given files added (one
	 * batched Drive request for those not known yet; '' for a file
	 * without a parent, such as a shared drive's root).
	 *
	 * @param array<string>         $file_ids Drive file or folder IDs.
	 * @param array<string, string> $parents  What's known so far.
	 *
	 * @return array<string, string>
	 */
	private static function with_parents_of( array $file_ids, array $parents ) {
		$promises = array();

		foreach ( $file_ids as $file_id ) {
			if ( isset( $parents[ $file_id ] ) ) {
				continue;
			}

			$promises[ $file_id ] = API_Facade::get_file_parents( $file_id )->then(
				null,
				static function () {
					return array();
				}
			);
		}

		if ( array() === $promises ) {
			return $parents;
		}

		foreach ( API_Client::execute( $promises ) as $file_id => $found ) {
			$parents[ (string) $file_id ] = is_array( $found ) && isset( $found[0] ) ? (string) $found[0] : '';
		}

		return $parents;
	}
}
