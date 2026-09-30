<?php
/**
 * Contains the Folder_Covers class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend\Page;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Facade;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Options_Proxy;
use Avpvh\Frontend\Paging_Pagination_Helper;
use Avpvh\Vendor\GuzzleHttp\Promise\PromiseInterface;
use Avpvh\Vendor\GuzzleHttp\Promise\Utils;

/**
 * The cover photo on a folder tile: the folder's first (non-excluded)
 * photo, shown with the same grid orientation correction it gets inside
 * the folder — per photo, or the folder's default.
 */
final class Folder_Covers {

	/**
	 * Covers for a list of folders.
	 *
	 * @param array<string> $folder_ids Drive folder IDs.
	 * @param Options_Proxy $options    The configuration of the gallery.
	 *
	 * @return PromiseInterface Resolving to, per folder, false or {url, orientation: {rotation, h_flip, v_flip}}.
	 */
	public static function for_folders( array $folder_ids, $options ) {
		return Utils::all(
			array_map(
				static function ( $folder_id ) use ( $options ) {
					return self::cover( (string) $folder_id, $options );
				},
				$folder_ids
			)
		);
	}

	/**
	 * One folder's cover.
	 *
	 * @param string        $folder_id Drive folder ID.
	 * @param Options_Proxy $options   The configuration of the gallery.
	 *
	 * @return PromiseInterface Resolving to false or a cover.
	 */
	private static function cover( $folder_id, $options ) {
		$ordering = (string) $options->get( 'image_ordering' );

		return API_Facade::list_images(
			$folder_id,
			new API_Fields(
				array(
					'id',
					'imageMediaMetadata' => array( 'width', 'height' ),
					'thumbnailLink',
				)
			),
			( new Paging_Pagination_Helper() )->withValues( 0, 100 ),
			$ordering
		)->then(
			static function ( $images ) use ( $folder_id, $options ) {
				$images = self::without_excluded( $images );

				return array() !== $images ? self::cover_from( $images[0], $folder_id, $options ) : false;
			}
		);
	}

	/**
	 * A cover from a folder's first photo, with that photo's grid correction.
	 *
	 * @param array<string, mixed> $image     Drive image record.
	 * @param string               $folder_id The folder it's in.
	 * @param Options_Proxy        $options   The configuration of the gallery.
	 *
	 * @return array{url: string, orientation: array{rotation: int, h_flip: bool, v_flip: bool}}
	 */
	private static function cover_from( array $image, $folder_id, $options ) {
		$corrected = Images::from_records( array( $image ), $folder_id, $options );
		$rotation  = (int) ( $corrected[0]['thumb_rotation'] ?? 0 );
		$metadata  = is_array( $image['imageMediaMetadata'] ?? null ) ? $image['imageMediaMetadata'] : array();
		// Ask Drive for enough pixels along what ends up as the tile's shorter
		// side, once the correction is applied.
		$wide    = (int) ( $metadata['width'] ?? 0 ) > (int) ( $metadata['height'] ?? 0 );
		$quarter = 90 === $rotation || 270 === $rotation;

		return array(
			'orientation' => array(
				'h_flip'   => (bool) ( $corrected[0]['thumb_h_flip'] ?? false ),
				'rotation' => $rotation,
				'v_flip'   => (bool) ( $corrected[0]['thumb_v_flip'] ?? false ),
			),
			'url'         => substr( (string) $image['thumbnailLink'], 0, -4 ) .
				( $wide !== $quarter ? 'h' : 'w' ) .
				floor( 1.25 * $options->get( 'grid_height' ) ),
		);
	}

	/**
	 * Removes excluded image records from a thumbnail candidate list.
	 *
	 * @param array<array<string, mixed>> $images Google Drive image records.
	 *
	 * @return array<array<string, mixed>> Visible image records.
	 */
	private static function without_excluded( array $images ) {
		if ( array() === $images ) {
			return $images;
		}

		global $wpdb;
		$ids          = array_column( $images, 'id' );
		$table        = $wpdb->prefix . 'agallery_photo_exclusions';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$excluded_col = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder count is dynamic, built above via array_fill().
				"SELECT image_id FROM {$table} WHERE image_id IN ({$placeholders})",
				$ids
			)
		);
		$excluded = is_array( $excluded_col ) ? $excluded_col : array();

		if ( array() === $excluded ) {
			return $images;
		}

		$lookup = array_fill_keys( $excluded, true );

		return array_values(
			array_filter(
				$images,
				static function ( $image ) use ( $lookup ) {
					return ! isset( $lookup[ $image['id'] ] );
				}
			)
		);
	}
}
