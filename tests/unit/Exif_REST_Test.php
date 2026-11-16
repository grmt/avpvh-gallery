<?php
/**
 * EXIF endpoints enforce permissions and validate requests before calling Drive.
 *
 * @package avpvh-gallery
 */

/** Runs real REST requests through the plugin's registered routes. */
final class Exif_REST_Test extends WP_UnitTestCase {

	/**
	 * Missing identifiers consistently return client errors.
	 *
	 * @dataProvider invalid_requests
	 * @param string $route Route below exif-inspector.
	 * @param string $method HTTP method.
	 * @param string $error Expected error code.
	 * @return void
	 */
	public function test_invalid_requests( $route, $method, $error ) {
		wp_set_current_user( 1 );
		$response = self::request( $route, $method );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $error, $response->get_data()['code'] );
	}

	/**
	 * A logged-in subscriber cannot read or alter administrator-only EXIF data.
	 *
	 * @return void
	 */
	public function test_subscriber_cannot_read_or_write_exif_data() {
		wp_set_current_user( 1 )->set_role( 'subscriber' );

		foreach ( array( 'file-data', 'corrections', 'folder-corrections' ) as $route ) {
			$response = self::request( $route, 'POST', array( 'file_id' => 'fixture' ) );
			$this->assertSame( 403, $response->get_status(), $route );
		}

		$this->assertSame( 403, self::request( 'model-index/status', 'GET' )->get_status() );
	}

	/**
	 * Browsing without OAuth authorization returns a clear 403, never a server error.
	 *
	 * @return void
	 */
	public function test_browse_without_google_authorization() {
		wp_set_current_user( 1 );
		delete_option( 'avpvh_access_token' );
		update_option( 'avpvh_root_path', array( 'fixture-root' ) );

		foreach ( array( 'list-folders', 'list-files', 'list-subfolders' ) as $route ) {
			$response = self::request(
				$route,
				'POST',
				array(
					'folder_name' => 'Fixture',
					'parent_id'   => 'fixture-root',
				)
			);
			$this->assertSame( 403, $response->get_status(), $route );
			$this->assertSame( 'not_authorized', $response->get_data()['code'] );
			$this->assertSame( 'Plugin not authorized', $response->get_data()['message'] );
		}

		$response = self::request(
			'search',
			'GET',
			array(
				'q'        => 'fixture',
				'scope_id' => 'fixture-root',
			)
		);
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_authorized', $response->get_data()['code'] );
	}

	/**
	 * Query validation rejects short searches and untrusted preview hosts.
	 *
	 * @return void
	 */
	public function test_search_and_preview_validation() {
		wp_set_current_user( 1 );
		$response = self::request( 'search', 'GET', array( 'q' => 'x' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$response = self::request( 'preview-exif', 'POST', array( 'url' => 'https://example.org/photo.jpg' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'URL must be from Google', $response->get_data()['message'] );
	}

	/**
	 * The model index reports an empty state without requiring Drive authorization.
	 *
	 * @return void
	 */
	public function test_empty_camera_index_is_readable_by_administrator() {
		wp_set_current_user( 1 );
		delete_option( 'avpvh_camera_model_index' );
		delete_option( 'avpvh_camera_model_scan' );
		$response = self::request( 'model-index/status', 'GET' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Request validation cases, including both read and write endpoints.
	 *
	 * @return array<array{0: string, 1: string, 2: string}>
	 */
	public static function invalid_requests() {
		return array(
			array( 'orientation', 'POST', 'invalid_file' ),
			array( 'file-data', 'POST', 'invalid_file' ),
			array( 'full-exif', 'POST', 'invalid_file' ),
			array( 'preview-exif', 'POST', 'invalid_url' ),
			array( 'corrections', 'POST', 'invalid_file' ),
			array( 'exclusion', 'GET', 'invalid_file' ),
			array( 'exclusion', 'POST', 'invalid_file' ),
			array( 'folder-corrections', 'GET', 'invalid_folder' ),
			array( 'folder-corrections', 'POST', 'invalid_folder' ),
			array( 'list-folders', 'POST', 'invalid_folder_name' ),
			array( 'list-files', 'POST', 'invalid_parent' ),
			array( 'list-subfolders', 'POST', 'invalid_parent' ),
			array( 'proxy-image', 'GET', 'rest_missing_callback_param' ),
			array( 'download-original', 'GET', 'rest_missing_callback_param' ),
			array( 'video-stream', 'GET', 'rest_missing_callback_param' ),
			array( 'model-index/models', 'GET', 'rest_missing_callback_param' ),
		);
	}

	/**
	 * Dispatches a real request without issuing an HTTP request to the site.
	 *
	 * @param string               $route Route below exif-inspector.
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $params Request parameters.
	 * @return WP_REST_Response
	 */
	private static function request( $route, $method, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/avpvh-gallery/v1/exif-inspector/' . $route );
		$request->set_query_params( $params );

		if ( 'POST' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}
}
