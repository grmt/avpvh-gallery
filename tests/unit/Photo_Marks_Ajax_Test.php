<?php
/**
 * The gallery_marks AJAX endpoint takes the photo ids from POST (and GET).
 *
 * @package avpvh-gallery
 */

use Avpvh\Photo_Tags_DB;

/**
 * The client posts the ids because a long GET URL is refused before WordPress
 * (Authelia gets the URL as a header and answers 431).
 */
final class Photo_Marks_Ajax_Test extends WP_Ajax_UnitTestCase {

	private const IMAGE_ID = 'fixture-marked-photo';

	// The test install's admin.
	private const ADMIN_ID = 1;

	/**
	 * A logged-in admin with two stars on the fixture photo.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Photo_Tags_DB::create_tables();
		wp_set_current_user( self::ADMIN_ID );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture in the plugin table.
		$wpdb->insert(
			$wpdb->prefix . 'agallery_photo_marks',
			array(
				'circle'     => 'votes',
				'image_id'   => self::IMAGE_ID,
				'level'      => 2,
				'owner'      => self::ADMIN_ID,
				'updated_by' => self::ADMIN_ID,
			)
		);
	}

	/**
	 * Ids in the POST body come back with their tally; unmarked ones are left out.
	 *
	 * @return void
	 */
	public function test_ids_from_post() {
		$_POST['ids'] = self::IMAGE_ID . ',fixture-unmarked-photo';

		$marks = $this->marks();

		$this->assertSame( array( self::IMAGE_ID ), array_keys( $marks ) );
		$this->assertSame( 2, $marks[ self::IMAGE_ID ]['mine'] );
		$this->assertSame( 0, $marks[ self::IMAGE_ID ]['others'] );
	}

	/**
	 * A short list in the query string still works.
	 *
	 * @return void
	 */
	public function test_ids_from_get() {
		$_GET['ids'] = self::IMAGE_ID;

		$this->assertSame( 2, $this->marks()[ self::IMAGE_ID ]['mine'] );
	}

	/**
	 * Runs gallery_marks and returns its marks.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function marks() {
		// wp_send_json_success() ends the request with this exception.
		$ended = false;

		try {
			$this->_handleAjax( 'gallery_marks' );
		} catch ( WPAjaxDieContinueException ) {
			$ended = true;
		}

		$this->assertSame( true, $ended );

		$response = json_decode( $this->_last_response, true );
		$this->assertSame( true, $response['success'] ?? false );

		return $response['data']['marks'];
	}
}
