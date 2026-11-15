<?php
/**
 * The members plugin narrows like visibility to the household and IT role.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Like_Visibility;
use Avpvh\Tests\Members_Fixture;

require_once dirname( __DIR__ ) . '/fixtures/class-members-fixture.php';

/** Tests the optional integration without leaking fixture classes to other tests. */
final class Like_Household_Test extends WP_UnitTestCase {

	/**
	 * A club member sees household likes, while only the IT administrator sees all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_household_and_it_role_boundaries() {
		class_alias( Members_Fixture::class, 'AVPVH_DB' );
		class_alias( Members_Fixture::class, 'AVPVH_Roles' );
		wp_set_current_user( 1 );
		update_user_meta( 1, 'fixture_member', (object) array( 'id' => 10 ) );
		update_option(
			'fixture_households',
			array(
				10 => array(
					(object) array( 'wp_user_id' => 1 ),
					(object) array( 'wp_user_id' => 20 ),
					(object) array( 'wp_user_id' => '30' ),
					(object) array( 'wp_user_id' => 20 ),
					(object) array( 'wp_user_id' => null ),
					(object) array( 'wp_user_id' => 0 ),
					'invalid entry',
				),
			)
		);
		delete_transient( 'avpvh_like_visible_1' );
		$this->assertFalse( Like_Visibility::sees_all() );
		$this->assertSame( array( 1, 20, 30 ), Like_Visibility::visible_user_ids() );
		$this->assertTrue( Like_Visibility::can_see( 20 ) );
		$this->assertFalse( Like_Visibility::can_see( 99 ) );
		$this->assertSame( array( 1, 20, 30 ), Like_Visibility::visible_user_ids() );

		delete_user_meta( 1, 'fixture_member' );
		delete_transient( 'avpvh_like_visible_1' );
		$this->assertSame( array( 1 ), Like_Visibility::visible_user_ids() );

		update_option( 'fixture_it_admin', true );
		$this->assertTrue( Like_Visibility::sees_all() );
		$this->assertNull( Like_Visibility::visible_user_ids() );
		$this->assertTrue( Like_Visibility::can_see( 99 ) );
	}
}
