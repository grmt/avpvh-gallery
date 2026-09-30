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

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Options_Proxy;
use Avpvh\Frontend\Paging_Pagination_Helper;
use Avpvh\Vendor\GuzzleHttp\Promise\PromiseInterface;
use Avpvh\Vendor\GuzzleHttp\Promise\Utils;

/**
 * The cover photo on a folder tile: the folder's first (non-excluded)
 * photo, shown with the same grid orientation correction it gets inside
 * the folder — per photo, or the folder's default. Folders without photos of
 * their own get one from a subfolder afterwards (fill_missing()).
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
	 * Gives folders without photos of their own (only subfolders — e.g. a
	 * dig split per photographer) the cover of their first subfolder that
	 * has one, looking up to two levels down.
	 *
	 * Runs after the page itself has been fetched, as separate rounds of
	 * Drive requests: queuing these from inside the page's own requests
	 * (as a fallback inside cover()) left requests unsent on large pages.
	 *
	 * @param array<array<string, mixed>> $directories The page's folders.
	 * @param Options_Proxy               $options     The configuration of the gallery.
	 *
	 * @return array<array<string, mixed>> The folders, with borrowed covers.
	 */
	public static function fill_missing( array $directories, $options ) {
		$searching = array();

		foreach ( $directories as $index => $directory ) {
			if ( false === ( $directory['thumbnail'] ?? false ) && isset( $directory['id'] ) ) {
				$searching[ $index ] = array( (string) $directory['id'] );
			}
		}

		for ( $depth = 1; $depth <= 2 && array() !== $searching; ++$depth ) {
			list( $directories, $searching ) = self::borrow_round( $directories, $searching, $options );
		}

		return $directories;
	}

	/**
	 * One level of fill_missing(): looks for covers in the subfolders of the
	 * folders still searching.
	 *
	 * @param array<array<string, mixed>>      $directories The page's folders.
	 * @param array<int|string, array<string>> $searching   Per page folder still without a cover: where to look.
	 * @param Options_Proxy                    $options     The configuration of the gallery.
	 *
	 * @return array{0: array<array<string, mixed>>, 1: array<int|string, array<string>>} The folders, and those still searching (one level deeper).
	 */
	private static function borrow_round( array $directories, array $searching, $options ) {
		$subfolders = self::first_subfolders( $searching );
		$covers     = self::covers_of( $subfolders, $options );
		$searching  = array();

		foreach ( $subfolders as $index => $ids ) {
			$cover = self::first_found( $ids, $covers );

			if ( false !== $cover ) {
				$directories[ $index ]['thumbnail'] = $cover['url'];
				$directories[ $index ]['cover']     = $cover['orientation'];
			} elseif ( array() !== $ids ) {
				$searching[ $index ] = $ids;
			}
		}

		return array( $directories, $searching );
	}

	/**
	 * The first few subfolders of each of the given folders (one round).
	 *
	 * @param array<int|string, array<string>> $folders Per page folder: the folders to look in.
	 *
	 * @return array<int|string, array<string>> Per page folder: their subfolders, in name order.
	 */
	private static function first_subfolders( array $folders ) {
		$promises = array();

		foreach ( $folders as $index => $ids ) {
			foreach ( array_slice( $ids, 0, 3 ) as $position => $folder_id ) {
				$promises[ $index . '|' . $position ] = API_Facade::list_directories(
					$folder_id,
					new API_Fields( array( 'id' ) ),
					( new Paging_Pagination_Helper() )->withValues( 0, 3 ),
					'name'
				);
			}
		}

		$listed     = API_Client::execute( $promises );
		$subfolders = array();

		foreach ( $listed as $key => $list ) {
			$index                  = explode( '|', (string) $key )[0];
			$subfolders[ $index ] ??= array();
			$found                  = array_column( is_array( $list ) ? $list : array(), 'id' );
			$subfolders[ $index ]   = array_merge( $subfolders[ $index ], $found );
		}

		return $subfolders;
	}

	/**
	 * The covers of all the given folders (one round).
	 *
	 * @param array<int|string, array<string>> $subfolders Per page folder: candidate folders.
	 * @param Options_Proxy                    $options    The configuration of the gallery.
	 *
	 * @return array<string, array{url: string, orientation: array{rotation: int, h_flip: bool, v_flip: bool}}|false> Folder ID => cover.
	 */
	private static function covers_of( array $subfolders, $options ) {
		$ids = array_values( array_unique( array_merge( array(), ...array_values( $subfolders ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		list( $covers ) = API_Client::execute( array( self::for_folders( $ids, $options ) ) );

		return array_combine( $ids, $covers );
	}

	/**
	 * The first cover found among some folders, in their order.
	 *
	 * @param array<string>                                                                                          $ids    Folder IDs.
	 * @param array<string, array{url: string, orientation: array{rotation: int, h_flip: bool, v_flip: bool}}|false> $covers Folder ID => cover.
	 *
	 * @return array{url: string, orientation: array{rotation: int, h_flip: bool, v_flip: bool}}|false
	 */
	private static function first_found( array $ids, array $covers ) {
		foreach ( $ids as $folder_id ) {
			if ( false !== ( $covers[ $folder_id ] ?? false ) ) {
				return $covers[ $folder_id ];
			}
		}

		return false;
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
