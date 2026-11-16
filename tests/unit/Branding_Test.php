<?php
/**
 * Branded logos retain accessibility and dimensions while replacing raster sources.
 *
 * @package avpvh-gallery
 */

use Avpvh\Main;

/** Verifies rendered HTML through the public filter. */
final class Branding_Test extends WP_UnitTestCase {

	/** A logo with responsive sources and accessible theme markup. */
	private const LOGO = '<a href="/"><img src="old.png" srcset="old.png 1x, large.png 2x"' .
		' sizes="100vw" width="150" alt="Club"></a>';

	/**
	 * Responsive raster sources must not override the replacement logo in browsers.
	 *
	 * @return void
	 */
	public function test_branded_logo_replaces_responsive_sources() {
		update_option( 'avpvh_asset_set', 'branded' );
		$logo = Main::filter_custom_logo( self::LOGO, 1 );
		$this->assertStringNotContainsString( 'srcset=', $logo );
		$this->assertStringNotContainsString( 'sizes=', $logo );
		$this->assertStringNotContainsString( 'old.png', $logo );
		$this->assertStringContainsString( 'width="150" alt="Club"', $logo );
		$this->assertStringContainsString( '<a href="/">', $logo );
		update_option( 'avpvh_asset_set', 'neutral' );
		$this->assertSame( self::LOGO, Main::filter_custom_logo( self::LOGO, 1 ) );
	}
}
