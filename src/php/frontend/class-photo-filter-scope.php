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
