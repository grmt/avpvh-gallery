<?php
/**
 * Credential fields preserve secrets and render safe editable/read-only inputs.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Code_String_Option;

/** Tests the public settings rendering and sanitization contract. */
final class Code_String_Option_Test extends WP_UnitTestCase {

	/**
	 * Rendering never leaks a stored secret, whether editable or locked.
	 *
	 * @return void
	 */
	public function test_secrets_are_masked_and_locked_secrets_are_disabled() {
		update_option( 'avpvh_fixture_code', 'fixture-secret<&"' );
		$option = self::option( true );
		$html   = self::render( $option );
		$this->assertStringNotContainsString( 'fixture-secret', $html );
		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'placeholder=', $html );
		$this->assertStringNotContainsString( 'disabled', $html );

		$option->add_field( true );
		$html = self::render( $option );
		$this->assertStringContainsString( 'disabled ', $html );
		$this->assertStringNotContainsString( 'readonly', $html );
		$this->assertStringNotContainsString( 'fixture-secret', $html );
	}

	/**
	 * Ordinary locked fields retain their escaped visible value.
	 *
	 * @return void
	 */
	public function test_nonsecret_values_are_escaped_and_readonly() {
		update_option( 'avpvh_fixture_code', '"><script>alert(1)</script>' );
		$option = self::option( false );
		$option->add_field( true );
		$html = self::render( $option );
		$this->assertStringContainsString( 'readonly ', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;', $html );
	}

	/**
	 * An empty locked secret is readonly rather than disabled.
	 *
	 * @return void
	 */
	public function test_empty_locked_secret_has_no_mask_and_is_readonly() {
		$option = self::option( true );
		$option->add_field( true );
		$html = self::render( $option );
		$this->assertStringContainsString( 'readonly ', $html );
		$this->assertStringNotContainsString( 'placeholder=', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	/**
	 * Saving an unrelated setting cannot erase a masked secret.
	 *
	 * @return void
	 */
	public function test_blank_secret_preserved_and_replacement_accepted() {
		update_option( 'avpvh_fixture_code', 'original-secret' );
		$option = self::option( true );
		$this->assertSame( 'original-secret', $option->sanitize( '' ) );
		$this->assertSame( 'original-secret', $option->sanitize( '  ' ) );
		$this->assertSame( 'replacement-secret', $option->sanitize( 'replacement-secret' ) );
		$this->assertSame( '', self::option( false )->sanitize( '' ) );
	}

	/**
	 * A separate option avoids touching the plugin's real credentials.
	 *
	 * @param bool $secret Whether to mask its stored value.
	 * @return Code_String_Option
	 */
	private static function option( $secret ) {
		return new Code_String_Option( 'fixture_code', '', 'fixture', 'fixture', 'Fixture code', $secret );
	}

	/**
	 * Captures the field's HTML, restoring the output buffer on failure too.
	 *
	 * @param Code_String_Option $option The field.
	 * @return string
	 */
	private static function render( Code_String_Option $option ) {
		ob_start();

		try {
			$option->html();

			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}
}
