<?php
/**
 * Contains the Share_Caption class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * The text Share_Image writes on a shared photo: for photos somewhere
 * below the digs folder (01-Opgravingen), the name of the dig's folder
 * just below it — its year and place, such as "1983 Grobbendonk" (also for
 * photos in subfolders of it, like "1983 Grobbendonk/Rob"). Other photos
 * get none.
 */
final class Share_Caption {

	/**
	 * The digs folder's name (any leading number aside).
	 */
	private const DIGS = '/^[\d\s.-]*opgravingen$/i';

	/**
	 * Levels to climb at most from a photo's folder.
	 */
	private const DEPTH = 6;

	/**
	 * Each photo's caption ('' for none).
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, string>
	 */
	public static function for_photos( array $ids ) {
		$parents  = Photo_Filter_Scope::parents( $ids );
		$folders  = array_values( array_unique( array_filter( $parents ) ) );
		$captions = self::folder_captions( $folders );

		return array_map(
			static function ( $folder_id ) use ( $captions ) {
				return $captions[ $folder_id ] ?? '';
			},
			$parents
		);
	}

	/**
	 * The caption for the photos in each folder: climbs from each folder,
	 * remembering the last name passed, until it reaches the digs folder
	 * (or the top). One batch of Drive requests per level.
	 *
	 * @param array<string> $folder_ids Drive folder IDs.
	 *
	 * @return array<string, string>
	 */
	private static function folder_captions( array $folder_ids ) {
		$captions = array_fill_keys( $folder_ids, '' );
		$climbing = array();

		foreach ( $folder_ids as $folder_id ) {
			$climbing[ $folder_id ] = array( $folder_id, '' );
		}

		for ( $level = 0; $level < self::DEPTH && array() !== $climbing; ++$level ) {
			list( $climbing, $found ) = self::climb( $climbing );
			$captions                 = array_replace( $captions, $found );
		}

		return $captions;
	}

	/**
	 * One level up for every folder still climbing: those that reached
	 * the digs folder get the name passed just below it as their caption;
	 * those at the top stop without one.
	 *
	 * @param array<string, array{0: string, 1: string}> $climbing Per folder: where it is, and the name below.
	 *
	 * @return array{0: array<string, array{0: string, 1: string}>, 1: array<string, string>} Still climbing, then found captions.
	 */
	private static function climb( array $climbing ) {
		$current = array_values( array_unique( array_column( $climbing, 0 ) ) );
		$names   = Photo_Date_Order::folder_names( $current );
		$parents = Photo_Filter_Scope::parents( $current );
		$found   = array();

		foreach ( $climbing as $folder_id => list( $node, $below ) ) {
			$name = trim( $names[ $node ] ?? '' );

			if ( 1 === preg_match( self::DIGS, $name ) ) {
				$found[ $folder_id ] = $below;
				unset( $climbing[ $folder_id ] );
			} elseif ( '' === ( $parents[ $node ] ?? '' ) ) {
				unset( $climbing[ $folder_id ] );
			} else {
				$climbing[ $folder_id ] = array( $parents[ $node ], $name );
			}
		}

		return array( $climbing, $found );
	}
}
