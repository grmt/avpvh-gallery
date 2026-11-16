<?php
/**
 * Remembered gallery filters reject invalid input and retain sanitized choices.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Filter_Memory;

/** Tests sanitization and user-meta round trips through the public API. */
final class Filter_Memory_Test extends WP_UnitTestCase {

	/**
	 * Empty or malformed filters must never be remembered as active filters.
	 *
	 * @return void
	 */
	public function test_invalid_and_empty_states_are_discarded() {
		foreach ( array( null, 'invalid', array(), array( 'conditions' => 'invalid' ) ) as $state ) {
			$this->assertNull( Filter_Memory::sanitize_state( $state ) );
		}

		$this->assertNull( Filter_Memory::sanitize_state( array( 'conditions' => array() ) ) );
		$this->assertNull(
			Filter_Memory::sanitize_state(
				array(
					'conditions' => array(
						array(
							'kind'  => 'unknown',
							'value' => 'fixture',
						),
					),
				)
			)
		);
	}

	/**
	 * HTML is stripped, malformed folders are dropped, and flags are strict booleans.
	 *
	 * @return void
	 */
	public function test_conditions_and_folder_scope_are_sanitized() {
		$state = Filter_Memory::sanitize_state(
			array(
				'conditions' => array(
					array(
						'kind'  => 'place',
						'label' => '<i>Plaats</i>',
						'value' => '<b>Weert</b>',
					),
					array(
						'kind'  => 'unknown',
						'value' => 'discard',
					),
				),
				'folders'    => array(
					null,
					array( 'id' => '' ),
					array(
						'exclude' => true,
						'id'      => 'folder-a',
						'name'    => '<b>Map</b>',
						'path'    => 'root/folder-a',
					),
				),
				'here'       => 'true',
				'sort'       => 'date_desc',
			)
		);
		$this->assertEquals(
			array(
				'conditions' => array(
					array(
						'kind'  => 'place',
						'label' => 'Plaats',
						'op'    => 'and',
						'value' => 'Weert',
					),
				),
				'folders'    => array(
					array(
						'exclude' => true,
						'id'      => 'folder-a',
						'name'    => 'Map',
						'path'    => 'root/folder-a',
					),
				),
				'here'       => false,
				'sort'       => 'date_desc',
			),
			$state
		);
	}

	/**
	 * A remembered filter survives reload, with unsupported sort values normalized.
	 *
	 * @return void
	 */
	public function test_saved_state_round_trip_and_sort_default() {
		wp_set_current_user( 1 );
		$state         = self::state();
		$state['sort'] = 'unsupported';
		update_user_meta( 1, 'avpvh_gallery_filter', $state );
		$state['sort'] = 'date';
		$this->assertEquals( $state, Filter_Memory::saved_state() );
		delete_user_meta( 1, 'avpvh_gallery_filter' );
		$this->assertNull( Filter_Memory::saved_state() );
	}

	/**
	 * Conditions, folder lists and labels are bounded before being stored.
	 *
	 * @return void
	 */
	public function test_input_limits() {
		$state               = self::state();
		$state['conditions'] = array_fill(
			0,
			25,
			array(
				'kind'  => 'place',
				'label' => str_repeat( 'é', 250 ),
				'value' => 'Weert',
			)
		);
		$state['folders']    = array_fill(
			0,
			60,
			array(
				'id'   => 'folder',
				'name' => 'Map',
			)
		);
		$clean               = Filter_Memory::sanitize_state( $state );
		$this->assertNotNull( $clean );
		$this->assertCount( 20, $clean['conditions'] ?? array() );
		$this->assertCount( 50, $clean['folders'] ?? array() );
		$this->assertSame( str_repeat( 'é', 200 ), $clean['conditions'][0]['label'] ?? '' );
	}

	/**
	 * A complete remembered filter.
	 *
	 * @return array<string, mixed>
	 */
	private static function state() {
		return array(
			'conditions' => array(
				array(
					'kind'  => 'place',
					'label' => 'Weert',
					'op'    => 'and',
					'value' => 'Weert',
				),
			),
			'folders'    => array(),
			'here'       => true,
			'sort'       => 'date_desc',
		);
	}
}
