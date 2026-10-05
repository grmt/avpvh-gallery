<?php
/**
 * Contains the Filter_Memory class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Remembers each user's gallery filter (see Photo_Filter), so the gallery
 * shows it again after a reload or the next login.
 */
final class Filter_Memory {

	/**
	 * User meta holding the remembered filter.
	 */
	private const MEMORY_KEY = 'avpvh_gallery_filter';

	/**
	 * The "gallery_filter_save" endpoint: remembers the user's current filter
	 * (or that there is none), so the gallery shows it again after a reload
	 * or the next login. POST: state, a JSON {conditions: [{kind, value, op,
	 * label}], here} or "null".
	 *
	 * @return void
	 */
	public static function handle_save() {
		check_ajax_referer( 'avpvh_tag_nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; decoded and sanitized field by field in remembered().
		$state = self::remembered( json_decode( wp_unslash( (string) ( $_POST['state'] ?? 'null' ) ), true ) );

		if ( null === $state ) {
			delete_user_meta( get_current_user_id(), self::MEMORY_KEY );
		} else {
			update_user_meta( get_current_user_id(), self::MEMORY_KEY, $state );
		}

		wp_send_json_success();
	}

	/**
	 * The current user's remembered filter, or null.
	 *
	 * @return array{conditions: array<array{kind: string, value: string, op: string, label: string}>, here: bool}|null
	 */
	public static function saved_state() {
		$state = get_user_meta( get_current_user_id(), self::MEMORY_KEY, true );

		return self::remembered( $state );
	}

	/**
	 * A filter state to remember, sanitized, or null for none.
	 *
	 * @param mixed $state Decoded state.
	 *
	 * @return array{conditions: array<array{kind: string, value: string, op: string, label: string}>, here: bool}|null
	 */
	private static function remembered( $state ) {
		if ( ! is_array( $state ) || ! is_array( $state['conditions'] ?? null ) ) {
			return null;
		}

		$conditions = array();

		foreach ( array_slice( $state['conditions'], 0, 20 ) as $condition ) {
			$valid = Photo_Filter::valid_condition( $condition );

			if ( null === $valid ) {
				continue;
			}

			$valid['value'] = (string) $valid['value'];
			$valid['label'] = mb_substr( sanitize_text_field( (string) ( $condition['label'] ?? '' ) ), 0, 200 );
			$conditions[]   = $valid;
		}

		return array() === $conditions ? null : array(
			'conditions' => $conditions,
			'here'       => true === ( $state['here'] ?? false ),
		);
	}
}
