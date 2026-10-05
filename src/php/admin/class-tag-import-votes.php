<?php
/**
 * Contains the Tag_Import_Votes class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Frontend\Subject_Tag_Tree;
use Avpvh\Tag_Log;
use RuntimeException;
use Throwable;

/**
 * Writing and taking back imported subject-tag votes (see Tag_Import_CLI):
 * each written vote is logged as added by its voter, and each run's receipt
 * (option RECEIPT_PREFIX + batch) lists exactly the rows it inserted.
 */
final class Tag_Import_Votes {

	/**
	 * Option name prefix for the receipts of applied imports.
	 */
	public const RECEIPT_PREFIX = 'avpvh_tag_import_';

	/**
	 * The subject votes already on these photos.
	 *
	 * @param array<string> $image_ids Drive file IDs.
	 *
	 * @return array<string, true> "image|tag|user" → true.
	 */
	public static function existing( array $image_ids ) {
		global $wpdb;
		$existing = array();

		foreach ( array_chunk( $image_ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom plugin table; the IDs are prepared.
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT image_id, tag_key, created_by FROM {$wpdb->prefix}agallery_photo_tags
					 WHERE category = 'subject' AND image_id IN ({$placeholders})",
					$chunk
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( $found as $vote ) {
				$existing[ $vote->image_id . '|' . $vote->tag_key . '|' . $vote->created_by ] = true;
			}
		}

		return $existing;
	}

	/**
	 * Writes the new votes in one transaction and stores the receipt.
	 *
	 * @param array<string, array<string, string>> $votes Planned votes (only the "new" ones are written).
	 *
	 * @return array{0: string, 1: int} The batch ID and how many votes were written.
	 *
	 * @throws Throwable Something failed; nothing was written.
	 */
	public static function write( array $votes ) {
		global $wpdb;
		$batch   = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false );
		$receipt = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction around custom plugin table writes.
		$wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( wp_list_filter( $votes, array( 'status' => 'new' ) ) as $vote ) {
				$receipt[] = self::insert( $vote );
			}

			$stored = array(
				'created_at' => current_time( 'mysql' ),
				'rows'       => $receipt,
			);
			add_option( self::RECEIPT_PREFIX . $batch, $stored, '', false );
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			throw $e;
		} finally {
			wp_set_current_user( 0 );
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array( $batch, count( $receipt ) );
	}

	/**
	 * Removes a batch's votes that are still there, logs it, and drops the
	 * receipt.
	 *
	 * @param string $batch Batch ID.
	 *
	 * @return array{0: int, 1: int} How many were removed, of how many.
	 */
	public static function undo( $batch ) {
		$receipt = get_option( self::RECEIPT_PREFIX . $batch );
		$rows    = is_array( $receipt ) ? (array) $receipt['rows'] : array();
		$removed = 0;

		foreach ( $rows as $row ) {
			$removed += self::remove( $row ) ? 1 : 0;
		}

		wp_set_current_user( 0 );
		delete_option( self::RECEIPT_PREFIX . $batch );

		return array( $removed, count( $rows ) );
	}

	/**
	 * The receipts that can be undone: batch, created_at, votes.
	 *
	 * @return array<array{batch: string, created_at: string, votes: int}>
	 */
	public static function batches() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this command's own receipts.
		$names   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$wpdb->esc_like( self::RECEIPT_PREFIX ) . '%'
			)
		);
		$batches = array();

		foreach ( $names as $name ) {
			$receipt   = get_option( $name );
			$batches[] = array(
				'batch'      => substr( $name, strlen( self::RECEIPT_PREFIX ) ),
				'created_at' => (string) ( $receipt['created_at'] ?? '' ),
				'votes'      => count( $receipt['rows'] ?? array() ),
			);
		}

		return $batches;
	}

	/**
	 * Inserts one vote and logs it as added by its voter.
	 *
	 * @param array<string, string> $vote Vote.
	 *
	 * @return array<string, int|string> Receipt row.
	 *
	 * @throws RuntimeException The insert failed.
	 */
	private static function insert( array $vote ) {
		global $wpdb;
		$user_id = (int) $vote['user_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'agallery_photo_tags',
			array(
				'category'   => 'subject',
				'created_at' => current_time( 'mysql' ),
				'created_by' => $user_id,
				'image_id'   => $vote['image_id'],
				'tag_key'    => $vote['tag_key'],
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		if ( 1 !== $inserted ) {
			throw new RuntimeException( esc_html( 'insert failed for ' . $vote['path'] . ': ' . $wpdb->last_error ) );
		}

		wp_set_current_user( $user_id );
		Tag_Log::record( $vote['image_id'], 'subject', $vote['tag_key'], $vote['tag_label'], 'add' );

		return array(
			'id'       => (int) $wpdb->insert_id,
			'image_id' => $vote['image_id'],
			'tag_key'  => $vote['tag_key'],
			'user_id'  => $user_id,
		);
	}

	/**
	 * Removes one receipt row's vote if it is still there, and logs that.
	 *
	 * @param array<string, int|string> $row Receipt row.
	 *
	 * @return bool Whether it was removed.
	 */
	private static function remove( array $row ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table.
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'agallery_photo_tags',
			array(
				'category'   => 'subject',
				'created_by' => $row['user_id'],
				'id'         => $row['id'],
				'image_id'   => $row['image_id'],
				'tag_key'    => $row['tag_key'],
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);

		if ( 1 !== $deleted ) {
			return false;
		}

		wp_set_current_user( (int) $row['user_id'] );
		$tag = Subject_Tag_Tree::tag( (string) $row['tag_key'] );
		Tag_Log::record(
			(string) $row['image_id'],
			'subject',
			(string) $row['tag_key'],
			null === $tag ? '' : (string) $tag['label'],
			'remove'
		);

		return true;
	}
}
