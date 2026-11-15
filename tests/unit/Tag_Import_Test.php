<?php
/**
 * Tag imports validate rows, detect conflicts, and keep undo receipts accurate.
 *
 * @package avpvh-gallery
 */

use Avpvh\Admin\Tag_Import_Plan;
use Avpvh\Admin\Tag_Import_Votes;
use Avpvh\Frontend\Subject_Tag_Tree;
use Avpvh\Photo_Tags_DB;
use Avpvh\Tag_Log;

require_once dirname( __DIR__, 2 ) . '/dist/admin/class-drive-path-resolver.php';
require_once dirname( __DIR__, 2 ) . '/dist/admin/class-tag-import-plan.php';
require_once dirname( __DIR__, 2 ) . '/dist/admin/class-tag-import-votes.php';

/** Tests the import's public APIs with isolated synthetic database rows. */
final class Tag_Import_Test extends WP_UnitTestCase {

	/**
	 * Unknown users and tags become row errors before any Drive request.
	 *
	 * @return void
	 */
	public function test_invalid_users_and_tags_are_reported() {
		$unknown_user            = self::row( 't900101', '1' );
		$unknown_user['user_id'] = '0';
		$unknown_tag             = self::row( 'missing-tag', '2' );
		$unknown_user['status']  = '';
		$unknown_tag['status']   = '';
		$resolved                = Tag_Import_Plan::resolve( array( $unknown_user, $unknown_tag ), 'fixture-root' );
		$this->assertStringContainsString( 'unknown user 0', $resolved[0]['status'] );
		$this->assertStringContainsString( 'unknown or untaggable tag', $resolved[1]['status'] );
		$this->assertSame( '', $resolved[0]['image_id'] );
		$this->assertSame( '', $resolved[1]['image_id'] );
	}

	/**
	 * Repeated selections count once, while existing votes are never reinserted.
	 *
	 * @return void
	 */
	public function test_duplicates_and_existing_votes() {
		self::nodes();
		list( $rows, $votes ) = Tag_Import_Plan::votes(
			array( self::row( 't900101', '1' ), self::row( 't900101', '2' ) )
		);
		$this->assertSame( 'duplicate of line 1', $rows[1]['status'] );
		$this->assertCount( 2, $votes );
		$this->assertSame( 'new', $votes['fixture-import|t900101|1']['status'] );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- synthetic existing vote.
		$wpdb->insert(
			$wpdb->prefix . 'agallery_photo_tags',
			array(
				'category'   => 'subject',
				'created_by' => 1,
				'image_id'   => 'fixture-import',
				'tag_key'    => 't900101',
			)
		);
		list( , $votes ) = Tag_Import_Plan::votes( array( self::row( 't900101', '1' ) ) );
		$this->assertSame( 'exists', $votes['fixture-import|t900101|1']['status'] );
		$this->assertSame( 'new', $votes['fixture-import|t900100|1']['status'] );
	}

	/**
	 * Two different tags from a one-per-photo group are both marked as conflicts.
	 *
	 * @return void
	 */
	public function test_single_group_conflicts_are_reported() {
		self::nodes();
		list( $rows, $votes ) = Tag_Import_Plan::votes(
			array( self::row( 't900101', '1' ), self::row( 't900102', '2' ) )
		);
		$this->assertTrue( Tag_Import_Plan::is_error( $rows[0]['status'] ) );
		$this->assertTrue( Tag_Import_Plan::is_error( $rows[1]['status'] ) );
		$this->assertStringContainsString( 't900102', $votes['fixture-import|t900101|1']['status'] );
	}

	/**
	 * Audit-log IDs differ from vote IDs; undo must remove the vote, not the log row.
	 *
	 * @return void
	 */
	public function test_import_undo_uses_the_inserted_vote_id() {
		wp_set_current_user( 1 );

		for ( $index = 0; $index < 3; ++$index ) {
			Tag_Log::record( 'fixture-import-log', 'subject', 'fixture', 'Fixture', 'add' );
		}

		$vote                    = self::row( 'fixture-import-tag', '1' );
		$vote['image_id']        = 'fixture-import-' . wp_generate_password( 12, false );
		$vote['tag_label']       = 'Fixture tag';
		$vote['status']          = 'new';
		list( $batch, $written ) = Tag_Import_Votes::write( array( 'fixture-vote' => $vote ) );

		try {
			$this->assertSame( 1, $written );
			$this->assertSame( array( 1, 1 ), Tag_Import_Votes::undo( $batch ) );
			$this->assertSame( array(), Tag_Import_Votes::existing( array( $vote['image_id'] ) ) );
		} finally {
			global $wpdb;
			// write() commits its own transaction; remove only these test fixtures explicitly.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup of synthetic rows committed by the import API.
			$wpdb->delete( $wpdb->prefix . 'agallery_photo_tags', array( 'image_id' => $vote['image_id'] ) );
			$wpdb->delete( $wpdb->prefix . 'agallery_tag_log', array( 'image_id' => $vote['image_id'] ) );
			$wpdb->delete( $wpdb->prefix . 'agallery_tag_log', array( 'image_id' => 'fixture-import-log' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			delete_option( Tag_Import_Votes::RECEIPT_PREFIX . $batch );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write() commits its insert, so commit fixture cleanup rather than letting the test framework roll it back.
			$wpdb->query( 'COMMIT' );
		}
	}

	/**
	 * One resolved manifest row, without relying on any real Drive photo.
	 *
	 * @param string $tag_key Tag key.
	 * @param string $line Manifest line number.
	 * @return array<string, string>
	 */
	private static function row( $tag_key, $line ) {
		return array(
			'image_id' => 'fixture-import',
			'line'     => $line,
			'path'     => 'Album/Photo.jpg',
			'source'   => 'fixture',
			'status'   => 'resolved',
			'tag_key'  => $tag_key,
			'user_id'  => '1',
		);
	}

	/**
	 * A taggable, exclusive group and two tags, rolled back by WordPress per test.
	 *
	 * @return void
	 */
	private static function nodes() {
		Photo_Tags_DB::create_tables();
		global $wpdb;

		foreach ( array( 900100, 900101, 900102 ) as $node_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- synthetic tag-tree fixtures.
			$wpdb->insert(
				$wpdb->prefix . 'agallery_tag_nodes',
				array(
					'id'        => $node_id,
					'label'     => 'Fixture ' . $node_id,
					'parent_id' => 900100 === $node_id ? null : 900100,
					'single'    => 1,
					'taggable'  => 1,
					'type'      => 900100 === $node_id ? 'group' : 'tag',
				)
			);
		}

		Subject_Tag_Tree::forget();
	}
}
