<?php
/**
 * Repeated schema initialization preserves data without duplicate primary keys.
 *
 * @package avpvh-gallery
 */

use Avpvh\Photo_Tags_DB;
use Avpvh\Tag_Log;

/** Tests upgrading existing tables as well as their initial creation. */
final class Schema_Migration_Test extends WP_UnitTestCase {

	/**
	 * Schema initialization must be safe to repeat after the tables already exist.
	 *
	 * @return void
	 */
	public function test_repeated_initialization_preserves_existing_rows() {
		global $wpdb, $EZSQL_ERROR;
		$image_id = 'fixture-schema-' . wp_generate_password( 12, false );
		Tag_Log::record( $image_id, 'subject', 'fixture', 'Fixture', 'add' );
		$errors = count( (array) $EZSQL_ERROR );

		try {
			for ( $repeat = 0; $repeat < 2; ++$repeat ) {
				Photo_Tags_DB::create_tables();
				$this->assertSame(
					$errors,
					count( (array) $EZSQL_ERROR ),
					'Schema initialization must not produce SQL errors.'
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verify the synthetic row survives schema initialization.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}agallery_tag_log WHERE image_id = %s",
					$image_id
				)
			);
			$this->assertSame( '1', $count );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL may commit the transaction, so clean up the synthetic row explicitly.
			$wpdb->delete( $wpdb->prefix . 'agallery_tag_log', array( 'image_id' => $image_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL committed the fixture insert; commit its cleanup too.
			$wpdb->query( 'COMMIT' );
		}
	}
}
