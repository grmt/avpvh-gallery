<?php
/**
 * E-mail removal links require the correct token and an explicit confirmation.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Photo_Shares_DB;
use Avpvh\Frontend\Photo_Shares_Removal;
use Avpvh\Photo_Tags_DB;

/** Exercises the public removal handler without creating a Drive folder. */
final class Photo_Shares_Removal_Test extends WP_UnitTestCase {

	/**
	 * A valid link shows confirmation without removing anything; POST removes it.
	 *
	 * @return void
	 */
	public function test_get_confirms_and_post_removes() {
		$share_id = $this->share();
		$share    = self::record( $share_id );
		parse_str( (string) wp_parse_url( Photo_Shares_Removal::url( $share ), PHP_URL_QUERY ), $query );
		$_REQUEST = $query;
		$_POST    = array();
		$page     = self::page();
		$this->assertSame( 200, $page['status'] );
		$this->assertStringContainsString( '<form method="post"', $page['message'] );
		$this->assertStringContainsString( '&lt;b&gt;Fixture&lt;/b&gt;', $page['message'] );
		$this->assertSame( 'pending', self::record( $share_id )->status );

		$_POST = $query;
		$this->assertStringContainsString( 'De deling is verwijderd.', self::page()['message'] );
		$this->assertSame( 'removed', self::record( $share_id )->status );
		$this->assertStringContainsString( 'al verwijderd of verlopen', self::page()['message'] );
	}

	/**
	 * A missing or altered token cannot remove a pending share.
	 *
	 * @return void
	 */
	public function test_invalid_token_and_unknown_share_are_rejected() {
		$share_id = $this->share();
		$_REQUEST = array(
			'share' => $share_id,
			'token' => 'incorrect',
		);
		$_POST    = array(
			'share' => $share_id,
			'token' => 'incorrect',
		);
		$this->assertSame( 403, self::page()['status'] );
		$this->assertSame( 'pending', self::record( $share_id )->status );
		$_REQUEST = array( 'share' => 0 );
		$this->assertSame( 403, self::page()['status'] );
	}

	/**
	 * A fixture with no remote folder, so confirmation cannot contact Drive.
	 *
	 * @return int
	 */
	private function share() {
		Photo_Tags_DB::create_tables();
		wp_set_current_user( 1 );
		$share_id = Photo_Shares_DB::insert(
			array(
				'conditions'  => '[]',
				'description' => '<b>Fixture</b>',
				'folder_id'   => '',
			)
		);
		$this->assertGreaterThan( 0, $share_id );

		return $share_id;
	}

	/**
	 * WordPress's test die-handler captures the page message and HTTP status.
	 *
	 * @return array{message: string, status: int}
	 */
	private static function page() {
		try {
			Photo_Shares_Removal::handle();
		} catch ( WPDieException $error ) {
			return array(
				'message' => $error->getMessage(),
				'status'  => $error->getCode(),
			);
		}

		self::fail( 'Removal handler must finish with a confirmation or error page.' );
	}

	/**
	 * Reads a required fixture share and fails if it disappeared.
	 *
	 * @param int $share_id Share ID.
	 * @return stdClass
	 */
	private static function record( $share_id ) {
		$share = Photo_Shares_DB::get( $share_id );

		if ( ! $share instanceof stdClass ) {
			self::fail( 'The fixture share must still exist.' );
		}

		return $share;
	}
}
