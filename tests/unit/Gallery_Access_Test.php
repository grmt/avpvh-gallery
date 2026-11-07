<?php
/**
 * Only those avpvh-members lets see everything may use the gallery.
 *
 * @package avpvh-gallery
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals

use Avpvh\Frontend\Gallery_Access;

if ( ! class_exists( 'AVPVH_Archive_Access' ) ) {
	/** Stands in for avpvh-members' class: the scope is set by the test. */
	final class AVPVH_Archive_Access {
		const ALL  = 'all';
		const NONE = 'none';

		/**
		 * The scope current_scope() answers.
		 *
		 * @var string|array<string, int>
		 */
		public static $scope = self::NONE;

		/**
		 * The current user's scope.
		 *
		 * @return string|array<string, int>
		 */
		public static function current_scope() {
			return self::$scope;
		}
	}
}

/** Tests the gallery's access gate. */
final class Gallery_Access_Test extends WP_UnitTestCase {

	/**
	 * Logs out after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		AVPVH_Archive_Access::$scope = AVPVH_Archive_Access::NONE;
		parent::tear_down();
	}

	/**
	 * Who may, and who may not.
	 *
	 * @return void
	 */
	public function test_check() {
		$this->assertFalse( Gallery_Access::check(), 'a visitor' );
		$this->assertSame( 401, Gallery_Access::refusal_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( Gallery_Access::check(), 'a login avpvh-members gives nothing' );
		$this->assertSame( 403, Gallery_Access::refusal_status() );

		AVPVH_Archive_Access::$scope = array(
			'from' => 2010,
			'to'   => 2015,
		);
		$this->assertFalse( Gallery_Access::check(), 'an ex-member with a period: not yet' );

		AVPVH_Archive_Access::$scope = AVPVH_Archive_Access::ALL;
		$this->assertTrue( Gallery_Access::check(), 'an active member' );

		AVPVH_Archive_Access::$scope = AVPVH_Archive_Access::NONE;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( Gallery_Access::check(), 'an administrator' );
	}

	/**
	 * The REST gate covers the gallery's front-end routes only.
	 *
	 * @return void
	 */
	public function test_gate_rest() {
		$server = rest_get_server();
		foreach ( array( '/avpvh/v1/members/for-tagging', '/avpvh-gallery/v1/subject-tags', '/avpvh-gallery/v1/photo-place', '/avpvh-gallery/v1/exif-date' ) as $route ) {
			$result = Gallery_Access::gate_rest( null, $server, new WP_REST_Request( 'GET', $route ) );
			$this->assertInstanceOf( WP_Error::class, $result, $route );
			$this->assertSame( 401, $result->get_error_data()['status'], $route );
		}
		$this->assertNull( Gallery_Access::gate_rest( null, $server, new WP_REST_Request( 'GET', '/wp/v2/pages' ) ) );
		$this->assertNull( Gallery_Access::gate_rest( null, $server, new WP_REST_Request( 'GET', '/avpvh-gallery/v1/subject-tags-x' ) ) );

		AVPVH_Archive_Access::$scope = AVPVH_Archive_Access::ALL;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertNull( Gallery_Access::gate_rest( null, $server, new WP_REST_Request( 'GET', '/avpvh/v1/members/for-tagging' ) ) );
	}
}
