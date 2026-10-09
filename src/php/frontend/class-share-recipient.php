<?php
/**
 * Contains the Share_Recipient class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use AVPVH_DB;

/**
 * The Google address a user's shared photo selections go to (see
 * Photo_Shares): both the e-mail with the link and the Drive access.
 */
final class Share_Recipient {

	/**
	 * User meta: the Google address the user gave when sharing.
	 */
	private const META = 'avpvh_share_google';

	/**
	 * The Google address the user gave when sharing, if any; else their
	 * e-mail address when it's a Gmail address; otherwise the Google
	 * address they verified in avpvh-members, if any; otherwise still their
	 * e-mail address (it may be a Google account on another domain).
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string
	 */
	public static function for_user( $user_id ) {
		$given = (string) get_user_meta( $user_id, self::META, true );

		return '' !== $given ? $given : self::found( $user_id );
	}

	/**
	 * Whether the address is known to be a Google account: given by the
	 * user, a Gmail address, or one verified in avpvh-members. If not, the
	 * share button asks for one.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return bool
	 */
	public static function known( $user_id ) {
		$address = self::for_user( $user_id );

		return '' !== (string) get_user_meta( $user_id, self::META, true )
			|| 1 === preg_match( '/@(gmail|googlemail)\.com$/i', $address )
			|| self::found( $user_id ) !== self::primary( $user_id );
	}

	/**
	 * Remembers the Google address the user gave (ignored unless valid).
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $address The address.
	 *
	 * @return void
	 */
	public static function remember( $user_id, $address ) {
		$address = sanitize_email( $address );

		if ( '' !== $address && false !== is_email( $address ) ) {
			update_user_meta( $user_id, self::META, $address );
		}
	}

	/**
	 * Forgets a given address that turned out not to be a Google account,
	 * so the share button asks again.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $address The address that failed.
	 *
	 * @return void
	 */
	public static function forget( $user_id, $address ) {
		if ( (string) get_user_meta( $user_id, self::META, true ) === $address ) {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * The address found without asking (see for_user()).
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string
	 */
	private static function found( $user_id ) {
		$member  = function_exists( 'avpvh_get_member_by_wp_user' ) ? avpvh_get_member_by_wp_user( $user_id ) : null;
		$fields  = is_object( $member ) ? get_object_vars( $member ) : array();
		$primary = self::primary( $user_id );

		if ( 1 === preg_match( '/@(gmail|googlemail)\.com$/i', $primary ) || ! isset( $fields['id'] ) ) {
			return $primary;
		}

		return self::google_identities( (int) $fields['id'] )[0] ?? $primary;
	}

	/**
	 * The user's e-mail address: the member's, else the WordPress account's.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string
	 */
	private static function primary( $user_id ) {
		$user    = get_userdata( $user_id );
		$member  = function_exists( 'avpvh_get_member_by_wp_user' ) ? avpvh_get_member_by_wp_user( $user_id ) : null;
		$fields  = is_object( $member ) ? get_object_vars( $member ) : array();
		$primary = (string) ( $fields['email'] ?? '' );

		return '' !== $primary || false === $user ? $primary : (string) $user->user_email;
	}

	/**
	 * A member's Google-verified addresses (avpvh-members), verified first.
	 *
	 * @param int $member_id avpvh-members member ID.
	 *
	 * @return array<string>
	 */
	private static function google_identities( $member_id ) {
		if ( ! class_exists( AVPVH_DB::class ) || ! method_exists( AVPVH_DB::class, 'get_member_identities' ) ) {
			return array();
		}

		$google = array();

		foreach ( AVPVH_DB::get_member_identities( $member_id ) as $identity ) {
			$fields = get_object_vars( $identity );

			if ( 'google' === ( $fields['provider'] ?? '' ) ) {
				$google[] = $fields;
			}
		}

		usort(
			$google,
			static function ( $first, $second ) {
				return self::unverified( $first ) - self::unverified( $second );
			}
		);

		return array_map(
			static function ( $identity ) {
				return (string) ( $identity['email'] ?? '' );
			},
			$google
		);
	}

	/**
	 * 1 for an identity that hasn't been verified, else 0.
	 *
	 * @param array<string, mixed> $identity An avpvh-members identity's fields.
	 *
	 * @return int
	 */
	private static function unverified( array $identity ) {
		return '' === (string) ( $identity['verified_at'] ?? '' ) ? 1 : 0;
	}
}
