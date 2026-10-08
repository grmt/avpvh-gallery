<?php
/**
 * Contains the Photo_Shares_Limit class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use stdClass;

/**
 * The limit on open shares per user (Photo_Shares::MAX_OPEN): whether the
 * current user has reached it, what to tell them, and — when they choose
 * to — closing their oldest share to make room.
 */
final class Photo_Shares_Limit {

	/**
	 * Whether the current user has the maximum number of shares open.
	 *
	 * @return bool
	 */
	public static function reached() {
		return Photo_Shares::MAX_OPEN <= Photo_Shares_DB::open_count( get_current_user_id() );
	}

	/**
	 * Why no share can be started: names the oldest, which the share button
	 * then offers to remove.
	 *
	 * @return string
	 */
	public static function message() {
		$oldest = Photo_Shares_DB::oldest_open( get_current_user_id() );
		$text   = sprintf( 'Je hebt al %d delingen open.', Photo_Shares::MAX_OPEN );

		if ( ! $oldest instanceof stdClass ) {
			return $text;
		}

		$name = '' === (string) $oldest->description ? '' : sprintf( ' “%s”', $oldest->description );

		return sprintf(
			'%s De oudste is%s van %s.',
			$text,
			$name,
			Photo_Shares::date( (string) $oldest->created_at )
		);
	}

	/**
	 * Removes the current user's oldest open share: its folder is deleted
	 * (if it's still there — it may have been removed in Drive already) and
	 * it shows as removed on the profile, where it can be made again.
	 *
	 * @return void
	 */
	public static function close_oldest() {
		$oldest = Photo_Shares_DB::oldest_open( get_current_user_id() );

		if ( ! $oldest instanceof stdClass ) {
			return;
		}

		Photo_Shares_Removal::close( $oldest );
	}
}
