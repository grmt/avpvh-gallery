<?php
/**
 * Photo selection preserves filter operators and legacy folder scope inputs.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Photo_Filter;
use Avpvh\Photo_Corrections_DB;
use Avpvh\Photo_Tags_DB;

/** Tests actual SQL selection using synthetic records and cached folder parents. */
final class Photo_Filter_Test extends WP_UnitTestCase {

	/**
	 * Creates the plugin tables and one tagged photo.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Photo_Tags_DB::create_tables();
		Photo_Corrections_DB::create_tables();
		wp_set_current_user( 1 );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- synthetic tag fixture.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'agallery_photo_places',
			array(
				'created_by' => 1,
				'image_id'   => 'fixture-photo',
				'place'      => 'Weert',
			)
		);
		$this->assertSame( 1, $inserted );
		set_transient(
			'avpvh_filter_parents',
			array(
				'child'         => 'root',
				'fixture-photo' => 'child',
				'other'         => '',
				'root'          => '',
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Both legacy single-folder and multi-folder selections return the same photo.
	 *
	 * @return void
	 */
	public function test_folder_scope_accepts_strings_and_arrays() {
		$conditions = Photo_Filter::valid_conditions( '[{"kind":"place","value":"Weert"}]' );

		foreach ( array( '', array(), 'root', array( 'root' ), array( 'root', '!other' ) ) as $folders ) {
			$this->assertSame( array( 'fixture-photo' ), Photo_Filter::all_matching_ids( $conditions, $folders ) );
		}

		$this->assertSame( array(), Photo_Filter::all_matching_ids( $conditions, 'other' ) );
		$this->assertSame( array(), Photo_Filter::all_matching_ids( $conditions, array( 'root', '!child' ) ) );
	}

	/**
	 * Exclusions and conditions with no positive clause cannot expose unwanted photos.
	 *
	 * @return void
	 */
	public function test_negative_only_and_nonmatching_conditions_return_nothing() {
		$this->assertSame(
			array(),
			Photo_Filter::all_matching_ids(
				Photo_Filter::valid_conditions( '[{"kind":"place","value":"Weert","op":"not"}]' ),
				''
			)
		);
		$this->assertSame(
			array(),
			Photo_Filter::all_matching_ids(
				Photo_Filter::valid_conditions( '[{"kind":"place","value":"Elsewhere"}]' ),
				''
			)
		);
	}

	/**
	 * Unknown kinds, empty values and unsupported operators are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_conditions_are_removed() {
		$this->assertSame( array(), Photo_Filter::valid_conditions( 'invalid JSON' ) );

		foreach (
			array(
				null,
				array(
					'kind'  => 'unknown',
					'value' => 'fixture',
				),
				array(
					'kind'  => 'place',
					'value' => '',
				),
				array(
					'kind'  => 'place',
					'op'    => 'xor',
					'value' => 'Weert',
				),
				array(
					'kind'  => 'marked',
					'value' => '0',
				),
			) as $condition
		) {
			$this->assertNull( Photo_Filter::valid_condition( $condition ) );
		}
	}
}
