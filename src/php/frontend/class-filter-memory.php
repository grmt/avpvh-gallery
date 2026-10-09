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

	/** User meta holding at most 30 named filters (also read by Filter_Sharing). */
	public const PRESETS_KEY = 'avpvh_gallery_filter_presets';

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
	 * @return array<string, mixed>|null
	 */
	public static function saved_state() {
		$state = get_user_meta( get_current_user_id(), self::MEMORY_KEY, true );

		return self::remembered( $state );
	}

	/**
	 * Saves or replaces a named filter preset.
	 *
	 * @return void
	 */
	public static function handle_preset_save() {
		check_ajax_referer( 'avpvh_tag_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; state is decoded and sanitized field by field.
		$name      = mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ), 0, 80 );
		$filter_id = sanitize_key( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) );
		$state     = self::remembered( json_decode( wp_unslash( (string) ( $_POST['state'] ?? 'null' ) ), true ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $name || null === $state ) {
			wp_send_json_error( array( 'message' => 'Geef het filter een naam' ), 400 );
		}

		$presets = self::saved_filters();

		if ( '' === $filter_id ) {
			$filter_id = sanitize_key( uniqid( 'filter-', true ) );
		}

		$preset = array_merge(
			array(
				'id'   => $filter_id,
				'name' => $name,
			),
			$state
		);
		$found  = false;

		foreach ( $presets as $index => $existing ) {
			if ( $existing['id'] !== $filter_id ) {
				continue;
			}

			// Saving it again keeps whom it is shared with (Filter_Sharing).
			$presets[ $index ] = array_merge( $preset, Filter_Sharing::audience( $existing ) );
			$found             = true;
		}

		if ( ! $found ) {
			$presets[] = $preset;
		}

		$presets = array_slice( $presets, -30 );
		update_user_meta( get_current_user_id(), self::PRESETS_KEY, $presets );
		wp_send_json_success(
			array(
				'filters' => $presets,
				'saved'   => $preset,
			)
		);
	}

	/**
	 * Deletes one named filter preset.
	 *
	 * @return void
	 */
	public static function handle_preset_delete() {
		check_ajax_referer( 'avpvh_tag_nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$filter_id = sanitize_key( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) );
		$presets   = array_values(
			array_filter(
				self::saved_filters(),
				static function ( $preset ) use ( $filter_id ) {
					return $preset['id'] !== $filter_id;
				}
			)
		);
		update_user_meta( get_current_user_id(), self::PRESETS_KEY, $presets );
		wp_send_json_success( array( 'filters' => $presets ) );
	}

	/**
	 * The current user's sanitized named filters.
	 *
	 * @return array<array<string, mixed>>
	 */
	public static function saved_filters() {
		return self::presets_of( get_current_user_id() );
	}

	/**
	 * A user's sanitized named filters, each with whom it is shared with
	 * (see Filter_Sharing).
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array<array<string, mixed>>
	 */
	public static function presets_of( $user_id ) {
		$stored  = get_user_meta( $user_id, self::PRESETS_KEY, true );
		$presets = array();

		foreach ( array_slice( is_array( $stored ) ? $stored : array(), 0, 30 ) as $candidate ) {
			$state     = self::remembered( $candidate );
			$filter_id = sanitize_key( (string) ( $candidate['id'] ?? '' ) );
			$name      = mb_substr( sanitize_text_field( (string) ( $candidate['name'] ?? '' ) ), 0, 80 );

			if ( null === $state || '' === $filter_id || '' === $name ) {
				continue;
			}

			$presets[] = array_merge(
				array(
					'id'   => $filter_id,
					'name' => $name,
				),
				$state,
				Filter_Sharing::audience( $candidate )
			);
		}

		return $presets;
	}

	/**
	 * A filter state sanitized as for remembering, or null for none (also
	 * used for the state a share keeps; see Photo_Shares_Page).
	 *
	 * @param mixed $state Decoded state.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function sanitize_state( $state ) {
		return self::remembered( $state );
	}

	/**
	 * A filter state to remember, sanitized, or null for none.
	 *
	 * @param mixed $state Decoded state.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function remembered( $state ) {
		if ( ! is_array( $state ) || ! is_array( $state['conditions'] ?? null ) ) {
			return null;
		}

		$conditions = self::conditions( $state['conditions'] );
		$folders    = self::folders( $state['folders'] ?? array() );

		return array() === $conditions ? null : array(
			'conditions' => $conditions,
			'folders'    => $folders,
			'here'       => true === ( $state['here'] ?? false ),
			'sort'       => in_array(
				$state['sort'] ?? '',
				array( 'date', 'date_desc' ),
				true
			) ? $state['sort'] : 'date',
		);
	}

	/**
	 * Sanitizes remembered filter conditions.
	 *
	 * @param array<mixed> $raw Raw conditions.
	 *
	 * @return array<array<string, mixed>>
	 */
	private static function conditions( array $raw ) {
		$conditions = array();

		foreach ( array_slice( $raw, 0, 20 ) as $condition ) {
			$valid = Photo_Filter::valid_condition( $condition );

			if ( null === $valid ) {
				continue;
			}

			$valid['value'] = (string) $valid['value'];
			$valid['label'] = mb_substr( sanitize_text_field( (string) ( $condition['label'] ?? '' ) ), 0, 200 );
			$conditions[]   = $valid;
		}

		return $conditions;
	}

	/**
	 * Sanitizes remembered folder selections.
	 *
	 * @param mixed $raw Raw folders.
	 *
	 * @return array<array{id: string, name: string, path: string, exclude?: true}>
	 */
	private static function folders( $raw ) {

		$folders = array();

		foreach ( array_slice( is_array( $raw ) ? $raw : array(), 0, 50 ) as $folder ) {
			if ( ! is_array( $folder ) ) {
				continue;
			}

			$folder_id = sanitize_text_field( (string) ( $folder['id'] ?? '' ) );

			if ( '' === $folder_id ) {
				continue;
			}

			$clean = array(
				'id'   => $folder_id,
				'name' => mb_substr( sanitize_text_field( (string) ( $folder['name'] ?? '' ) ), 0, 200 ),
				'path' => mb_substr( sanitize_text_field( (string) ( $folder['path'] ?? '' ) ), 0, 2000 ),
			);

			if ( true === ( $folder['exclude'] ?? false ) ) {
				$clean['exclude'] = true;
			}

			$folders[] = $clean;
		}

		return $folders;
	}
}
