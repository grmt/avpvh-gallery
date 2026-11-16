<?php
/**
 * OAuth redirects preserve errors and credentials without contacting Google.
 *
 * @package avpvh-gallery
 */

use Avpvh\Admin\OAuth_Helpers;
use Avpvh\Tests\Google_Transport;
use Avpvh\Vendor\Google\Client;
use Avpvh\Vendor\GuzzleHttp\Psr7\Response;

require_once dirname( __DIR__ ) . '/fixtures/class-google-transport.php';

/** Verifies the redirect destination and persistent settings notices. */
final class OAuth_Helpers_Test extends WP_UnitTestCase {

	/**
	 * A missing authorization code is reported after redirecting back to settings.
	 *
	 * @return void
	 */
	public function test_missing_code_redirects_with_error() {
		$this->with_redirect(
			function () {
				OAuth_Helpers::grant_return();
				$errors = get_transient( 'settings_errors' );
				$this->assertSame( 'oauth_failed', $errors[0]['code'] );
				$this->assertSame( 'error', $errors[0]['type'] );
				$this->assertFalse( get_option( 'avpvh_access_token' ) );
			},
			admin_url( 'admin.php?page=avpvh_basic&settings-updated=true' )
		);
	}

	/**
	 * Successful revocation deletes the stored credential before reporting success.
	 *
	 * @return void
	 */
	public function test_revoke_removes_token_and_redirects() {
		Google_Transport::run(
			static function () {
				return new Response( 200, array(), '{}' );
			},
			function () {
				$this->with_redirect(
					function () {
						OAuth_Helpers::revoke();
						$this->assertFalse( get_option( 'avpvh_access_token' ) );
						$errors = get_transient( 'settings_errors' );
						$this->assertSame( 'oauth_updated', $errors[0]['code'] );
					},
					admin_url( 'admin.php?page=avpvh_basic&settings-updated=true' )
				);
			}
		);
	}

	/**
	 * Google's authorization URL retains the client and callback parameters.
	 *
	 * @return void
	 */
	public function test_grant_redirect_uses_google_authorization_url() {
		Google_Transport::run(
			static function (): Response {
				self::fail( 'Creating an authorization URL must not make an HTTP request.' );
			},
			function ( Client $client ) {
				$client->setClientId( 'fixture-client' );
				$client->setRedirectUri( admin_url( 'admin.php?page=avpvh_basic' ) );
				$client->addScope( 'https://www.googleapis.com/auth/drive.readonly' );
				$this->with_redirect( array( OAuth_Helpers::class, 'grant_redirect' ), $client->createAuthUrl() );
			}
		);
	}

	/**
	 * Captures WordPress's redirect filter without sending headers from PHPUnit.
	 *
	 * @param callable():void $run Assertions and action.
	 * @param string          $expected Expected redirect URL.
	 * @return void
	 */
	private function with_redirect( callable $run, $expected ) {
		$locations = new ArrayObject();
		$capture   = static function ( $location, $status ) use ( $locations ) {
			self::assertSame( 302, $status );
			$locations[] = $location;

			return false;
		};
		set_current_screen( 'dashboard' );
		$GLOBALS['wp_settings_errors'] = array();
		add_filter( 'wp_redirect', $capture, 10, 2 );

		try {
			$run();
			$this->assertSame( array( $expected ), $locations->getArrayCopy() );
		} finally {
			remove_filter( 'wp_redirect', $capture );
			$GLOBALS['wp_settings_errors'] = array();
			set_current_screen( 'front' );
		}
	}
}
