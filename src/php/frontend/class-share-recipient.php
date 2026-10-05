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

/**
 * The Google address a user's shared photo selections go to (see
 * Photo_Shares): both the e-mail with the link and the Drive access.
 */
final class Share_Recipient {

	/**
	 * The user's e-mail address when it's a Gmail address; otherwise the
	 * Google address they verified in avpvh-members, if any; otherwise
	 * still their e-mail address (it may be a Google account on another
	 * domain).
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string
	 */
	public static function for_user( $user_id ) {
		$user    = get_userdata( $user_id );
		$member  = function_exists( 'avpvh_get_member_by_wp_user' ) ? avpvh_get_member_by_wp_user( $user_id ) : null;
		$primary = is_object( $member ) && '' !== (string) ( $member->email ?? '' )
			? (string) $member->email
			: ( false === $user ? '' : (string) $user->user_email );

		if ( 1 === preg_match( '/@(gmail|googlemail)\.com$/i', $primary ) || ! is_object( $member ) ) {
			return $primary;
		}

		return self::google_identities( (int) $member->id )[0] ?? $primary;
	}

	/**
	 * A member's Google-verified addresses (avpvh-members), verified first.
	 *
	 * @param int $member_id avpvh-members member ID.
	 *
	 * @return array<string>
	 */
	private static function google_identities( $member_id ) {
		if ( ! class_exists( '\\AVPVH_DB' ) || ! method_exists( '\\AVPVH_DB', 'get_member_identities' ) ) {
			return array();
		}

		$google = array_filter(
			(array) call_user_func( array( '\\AVPVH_DB', 'get_member_identities' ), $member_id ),
			static function ( $identity ) {
				return is_object( $identity ) && 'google' === ( $identity->provider ?? '' );
			}
		);
		usort(
			$google,
			static function ( $first, $second ) {
				return self::unverified( $first ) - self::unverified( $second );
			}
		);

		return array_map(
			static function ( $identity ) {
				return (string) $identity->email;
			},
			$google
		);
	}

	/**
	 * 1 for an identity that hasn't been verified, else 0.
	 *
	 * @param object $identity An avpvh-members identity.
	 *
	 * @return int
	 */
	private static function unverified( $identity ) {
		return '' === (string) ( $identity->verified_at ?? '' ) ? 1 : 0;
	}
}
