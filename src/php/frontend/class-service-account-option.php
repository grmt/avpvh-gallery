<?php
/**
 * Contains the Service_Account_Option class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * An option holding a Google service account's JSON key. Write-only in the
 * settings: once set, only the account's address is shown; pasting a new
 * key replaces it, ticking "remove" clears it, and leaving the field empty
 * keeps it.
 */
final class Service_Account_Option extends Option {

	/**
	 * Registers the option with WordPress.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			$this->page,
			$this->name,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
				'type'              => 'string',
			)
		);
	}

	/**
	 * Renders the key field: the current account, if any, and a box for a
	 * new key.
	 *
	 * @return void
	 */
	public function html() {
		$email = (string) ( $this->credentials()['client_email'] ?? '' );

		if ( '' !== $email ) {
			echo '<p>' . esc_html__( 'Set for', 'avpvh-gallery' ) . ' <code>' . esc_html( $email ) . '</code> ';
			echo '<label><input type="checkbox" name="' . esc_attr( $this->name ) . '_remove" value="1"> ' . esc_html__( 'remove', 'avpvh-gallery' ) . '</label></p>';
		}

		echo '<textarea name="' . esc_attr( $this->name ) . '" rows="4" cols="60" autocomplete="off" placeholder="' .
			esc_attr__( 'Paste the service account\'s JSON key to set or replace it', 'avpvh-gallery' ) . '"></textarea>';
	}

	/**
	 * Keeps the stored key unless a valid new one is pasted or removal is
	 * ticked.
	 *
	 * @param mixed $value The pasted text.
	 *
	 * @return string The JSON key to store.
	 */
	public function sanitize( $value ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checked the settings nonce.
		if ( isset( $_POST[ $this->name . '_remove' ] ) ) {
			return '';
		}

		$pasted = trim( is_string( $value ) ? $value : '' );

		if ( '' === $pasted ) {
			return (string) get_option( $this->name, '' );
		}

		$key = json_decode( $pasted, true );

		if ( ! is_array( $key ) || 'service_account' !== ( $key['type'] ?? '' ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			add_settings_error( $this->name, 'invalid', esc_html__( 'That is not a Google service account JSON key; the previous key was kept.', 'avpvh-gallery' ) );

			return (string) get_option( $this->name, '' );
		}

		return (string) wp_json_encode( $key );
	}

	/**
	 * The stored key, decoded, or an empty array.
	 *
	 * @return array<string, mixed>
	 */
	public function credentials() {
		$key = json_decode( (string) $this->get(), true );

		return is_array( $key ) ? $key : array();
	}
}
