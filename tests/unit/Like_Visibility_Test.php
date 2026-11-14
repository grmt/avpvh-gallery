<?php
/**
 * Likes stay private to the viewer unless an administrator is viewing them.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Like_Visibility;
use Avpvh\Frontend\Photo_Filter;

/** Exercises the fallback when the optional members plugin is absent. */
final class Like_Visibility_Test extends WP_UnitTestCase {

	/**
	 * Administrators may see and filter everyone's likes.
	 *
	 * @return void
	 */
	public function test_administrator_can_see_all_likes() {
		wp_set_current_user( 1 );
		$this->assertTrue( Like_Visibility::sees_all() );
		$this->assertNull( Like_Visibility::visible_user_ids() );
		$this->assertTrue( Like_Visibility::can_see( 12345 ) );
		$this->assertNotNull(
			Photo_Filter::valid_condition(
				array(
					'kind'  => 'liked_by',
					'value' => '12345',
				)
			)
		);
	}

	/**
	 * A non-admin sees their own likes, including after the cache is populated.
	 *
	 * @return void
	 */
	public function test_subscriber_cannot_see_or_filter_another_users_likes() {
		wp_set_current_user( 1 )->set_role( 'subscriber' );
		delete_transient( 'avpvh_like_visible_1' );
		$this->assertFalse( Like_Visibility::sees_all() );
		$this->assertSame( array( 1 ), Like_Visibility::visible_user_ids() );
		$this->assertTrue( Like_Visibility::can_see( 1 ) );
		$this->assertFalse( Like_Visibility::can_see( 12345 ) );
		$this->assertNull(
			Photo_Filter::valid_condition(
				array(
					'kind'  => 'liked_by',
					'value' => '12345',
				)
			)
		);
		$this->assertSame(
			array(
				'kind'  => 'liked_by',
				'op'    => 'and',
				'value' => 1,
			),
			Photo_Filter::valid_condition(
				array(
					'kind'  => 'liked_by',
					'value' => '1',
				)
			)
		);
	}

	/**
	 * Anonymous visitors cannot inspect a logged-in person's likes.
	 *
	 * @return void
	 */
	public function test_anonymous_viewer_cannot_see_member_likes() {
		wp_set_current_user( 0 );
		$this->assertFalse( Like_Visibility::sees_all() );
		$this->assertFalse( Like_Visibility::can_see( 1 ) );
	}
}
