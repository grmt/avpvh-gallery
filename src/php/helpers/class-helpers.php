<?php
/**
 * Contains the Helpers class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Exceptions\Exception as Avpvh_Exception;
use Exception as Base_Exception;
use Throwable;
use const WP_DEBUG;
use const WP_DEBUG_DISPLAY;

/**
 * Contains various helper functions.
 */
final class Helpers {

	/**
	 * Checks whether debug info should be displayed
	 *
	 * @return bool True to display debug info.
	 */
	public static function is_debug_display() {
		if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_DISPLAY' ) ) {
			return true === WP_DEBUG && true === WP_DEBUG_DISPLAY;
		}

		return false;
	}

	/**
	 * Runs a members-only AJAX handler: visitors who aren't logged in get a
	 * 401 asking them to log in. The gallery is only shown on the members
	 * pages, and its AJAX endpoints used to rely on the gallery hash alone.
	 *
	 * @param callable $handler The actual handler.
	 *
	 * @return void
	 */
	public static function members_only_ajax( $handler ) {
		if ( ! is_user_logged_in() ) {
			wp_send_json( array( 'error' => "Log in om de foto's te bekijken." ), 401 );
		}

		self::ajax_wrapper( $handler );
	}

	/**
	 * Sends a JSON response that the web server may keep and hand to other
	 * (logged-in) members for a while, so the slow Google Drive calls behind
	 * it aren't repeated on every visit. nginx's FastCGI cache honours
	 * X-Accel-Expires; browsers still get WordPress's no-cache headers.
	 *
	 * Only for responses that are the same for every user — nothing
	 * personal (likes, tags, permissions) may ever be added to them.
	 *
	 * @param mixed $data    The response.
	 * @param int   $seconds How long it may be kept.
	 *
	 * @return void
	 */
	public static function send_shared_json( $data, $seconds = 900 ) {
		if ( ! headers_sent() ) {
			header( 'X-Accel-Expires: ' . $seconds );
		}

		wp_send_json( $data );
	}

	/**
	 * Runs an AJAX handler and handles errors.
	 *
	 * @param callable $handler The actual handler.
	 *
	 * @return void
	 */
	public static function ajax_wrapper( $handler ) {
		try {
			$handler();
		} catch ( Avpvh_Exception $e ) {
			if ( self::is_debug_display() ) {
				wp_send_json(
					array(
						'error' => esc_html( $e->getMessage() ),
						'trace' => esc_html( $e->getTraceAsString() ),
					)
				);
			}

			wp_send_json( array( 'error' => esc_html( $e->getMessage() ) ) );
		} catch ( Base_Exception $e ) {
			if ( self::is_debug_display() ) {
				wp_send_json(
					array(
						'error' => esc_html( $e->getMessage() ),
						'trace' => esc_html( $e->getTraceAsString() ),
					)
				);
			}

			wp_send_json( array( 'error' => esc_html__( 'Unknown error.', 'avpvh-gallery' ) ) );
		}
	}

	/**
	 * A Drive file's name, or '' if it can't be looked up (e.g. deleted).
	 *
	 * @param string $file_id Drive file ID.
	 *
	 * @return string
	 */
	public static function drive_file_name( $file_id ) {
		try {
			$names = API_Client::execute( array( API_Facade::get_file_name( $file_id ) ) );

			return is_string( $names[0] ) ? $names[0] : '';
		} catch ( Throwable $e ) {
			// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- best-effort label only.
			return '';
		}
	}
}
