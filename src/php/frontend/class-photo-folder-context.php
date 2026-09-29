<?php
/**
 * Contains the Photo_Folder_Context class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Throwable;

/**
 * What a photo's Drive folder says about the photo: the year it belongs to
 * and the activity (and so the participants) it's from.
 */
final class Photo_Folder_Context {

	/**
	 * Best-effort: the year a photo's folder belongs to and the matching
	 * activity's participants. Photos usually sit in a per-photographer
	 * subfolder (e.g. "2025 Goeblange - GKA" or a plain name) of the
	 * activity's own folder ("2025 Goeblange"), so this walks up from the
	 * photo's folder. Never fails the caller.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 *
	 * @return array{year: int|null, participants: array<array{id: int, name: string}>}
	 */
	public static function for_folder( $folder_id ) {
		$cache_key = 'avpvh_folder_context_' . md5( $folder_id );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['participants'] ) && is_array( $cached['participants'] ) ) {
			return array(
				'participants' => $cached['participants'],
				'year'         => isset( $cached['year'] ) ? (int) $cached['year'] : null,
			);
		}

		try {
			$context = self::context_from_ancestors( $folder_id );
		} catch ( Throwable $e ) {
			// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- best-effort lookup; any failure just means no context.
			$context = array(
				'participants' => array(),
				'year'         => null,
			);
		}

		set_transient( $cache_key, $context, 10 * MINUTE_IN_SECONDS );

		return $context;
	}

	/**
	 * Tries the folder and up to three of its ancestors, nearest first: the
	 * year is taken from the first folder name starting with one ("2026
	 * Goeblange"), the participants from the first one matching an activity.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 *
	 * @return array{year: int|null, participants: array<array{id: int, name: string}>}
	 */
	private static function context_from_ancestors( $folder_id ) {
		$year = null;

		for ( $level = 0; $level < 4 && '' !== $folder_id; ++$level ) {
			list( $folder_name, $parent_id ) = self::folder_name_and_parent( $folder_id );

			if ( null === $year && 1 === preg_match( '/^(\d{4})\b/', trim( $folder_name ), $matches ) ) {
				$year = (int) $matches[1];
			}

			$participants = Activity_Participants::for_folder_name( $folder_name );

			if ( array() !== $participants ) {
				return array(
					'participants' => $participants,
					'year'         => $year,
				);
			}

			$folder_id = $parent_id;
		}

		return array(
			'participants' => array(),
			'year'         => $year,
		);
	}

	/**
	 * Looks up a Drive folder's name and its (first) parent folder ID.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 *
	 * @return array{0: string, 1: string} Name, then parent ID ('' for either if unknown).
	 */
	private static function folder_name_and_parent( $folder_id ) {
		$results = API_Client::execute(
			array(
				API_Facade::get_file_name( $folder_id ),
				API_Facade::get_file_parents( $folder_id ),
			)
		);

		return array(
			is_string( $results[0] ) ? $results[0] : '',
			is_array( $results[1] ) && isset( $results[1][0] ) ? (string) $results[1][0] : '',
		);
	}
}
