<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- single-class file per project convention; grew with the new exclusion-counting/filtering helpers.
/**
 * Contains the Directories class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend\Page;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Facade;
use Avpvh\Exceptions\Internal_Exception;
use Avpvh\Exceptions\Plugin_Not_Authorized_Exception;
use Avpvh\Exceptions\Unsupported_Value_Exception;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Options_Proxy;
use Avpvh\Frontend\Pagination_Helper;
use Avpvh\Frontend\Paging_Pagination_Helper;
use Avpvh\Frontend\Single_Page_Pagination_Helper;
use Avpvh\Vendor\GuzzleHttp\Promise\PromiseInterface;
use Avpvh\Vendor\GuzzleHttp\Promise\Utils;

/**
 * Contains all the functions used to display directories in a gallery.
 */
final class Directories {

	/**
	 * Returns a list of subdirectories in a directory.
	 *
	 * @param string            $parent_id A directory to list items of.
	 * @param Pagination_Helper $pagination_helper An initialized pagination helper.
	 * @param Options_Proxy     $options The configuration of the gallery.
	 *
	 * @return PromiseInterface A promise resolving to a list of directories in the format `['id' =>, 'id', 'name' => 'name', 'thumbnail' => 'thumbnail', 'mediacount' => 1, 'dircount' => 1, 'imagecount' => 1, 'videocount' => 1]`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function get( $parent_id, $pagination_helper, $options ) {
		return API_Facade::list_directories(
			$parent_id,
			new API_Fields( array( 'id', 'name' ) ),
			$pagination_helper,
			$options->get( 'dir_ordering' )
		)->then(
			static function ( $files ) use ( $options ) {
				$files = array_map(
					static function ( $file ) use ( $options ) {
						if ( '' !== $options->get( 'dir_prefix' ) ) {
							$pos          = mb_strpos( $file['name'], $options->get( 'dir_prefix' ) );
							$file['name'] = mb_substr( $file['name'], false !== $pos ? $pos + 1 : 0 );
						}

						return $file;
					},
					$files
				);
				$ids   = array_column( $files, 'id' );

				return Utils::all(
					array( $files, self::thumbnail_images( $ids, $options ), self::item_counts( $ids, $options ) )
				);
			}
		)->then(
			static function ( $tuple ) use ( $options ) {
				list( $files, $images, $counts ) = $tuple;
				$count                           = count( $files );
				$excluded_by_dir                 = self::exclusion_counts( array_column( $files, 'id' ) );

				for ( $i = 0; $i < $count; ++$i ) {
					$excluded                   = isset( $excluded_by_dir[ $files[ $i ]['id'] ] )
						? $excluded_by_dir[ $files[ $i ]['id'] ]
						: array(
							'image' => 0,
							'video' => 0,
						);
					$counts[ $i ]['imagecount'] = max( 0, $counts[ $i ]['imagecount'] - $excluded['image'] );
					$counts[ $i ]['videocount'] = max( 0, $counts[ $i ]['videocount'] - $excluded['video'] );
					$files[ $i ]['thumbnail']   = $images[ $i ];
					$files[ $i ]['subdirs']     = $counts[ $i ]['subdirs'];
					// The lightbox needs this total when it advances into the folder,
					// independently of whether directory counts are shown in the grid.
					$files[ $i ]['mediacount'] =
						$counts[ $i ]['imagecount'] + $counts[ $i ]['videocount'];

					if ( 'true' === $options->get( 'dir_counts' ) ) {
						$files[ $i ]['dircount']   = $counts[ $i ]['dircount'];
						$files[ $i ]['imagecount'] = $counts[ $i ]['imagecount'];
						$files[ $i ]['videocount'] = $counts[ $i ]['videocount'];
					}

					if ( 0 === $counts[ $i ]['dircount'] + $counts[ $i ]['imagecount'] + $counts[ $i ]['videocount'] ) {
						unset( $files[ $i ] );
					}
				}

				// Needed because of the unset not re-indexing.
				return array_values( $files );
			}
		);
	}

	/**
	 * Counts excluded photos for each requested folder.
	 *
	 * @param array<string> $folder_ids Google Drive folder IDs.
	 *
	 * @return array<string, array{image: int, video: int}>
	 */
	private static function exclusion_counts( array $folder_ids ) {
		if ( array() === $folder_ids ) {
			return array();
		}

		global $wpdb;
		$table                = $wpdb->prefix . 'agallery_photo_exclusions';
		$placeholders         = implode( ', ', array_fill( 0, count( $folder_ids ), '%s' ) );
		$exclusion_counts_sql = 'SELECT folder_id, media_type, COUNT(*) AS excluded_count FROM ' . $table .
			' WHERE folder_id IN (' . $placeholders . ') GROUP BY folder_id, media_type';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table/$placeholders are built above (not user-supplied); $folder_ids values are parameterized via $wpdb->prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $exclusion_counts_sql, $folder_ids ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$counts = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $counts[ $row['folder_id'] ] ) ) {
				$counts[ $row['folder_id'] ] = array(
					'image' => 0,
					'video' => 0,
				);
			}

			$type                                 = 'video' === $row['media_type'] ? 'video' : 'image';
			$counts[ $row['folder_id'] ][ $type ] = intval( $row['excluded_count'] );
		}

		return $counts;
	}

	/**
	 * Creates API requests for directory thumbnails
	 *
	 * Takes a batch and adds to it a request for the first image in each directory.
	 *
	 * @param array<string> $dirs A list of directory IDs.
	 * @param Options_Proxy $options The configuration of the gallery.
	 *
	 * @return PromiseInterface A promise resolving to a list of directory images.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	private static function thumbnail_images( $dirs, $options ) {
		return Utils::all(
			array_map(
				static function ( $directory ) use ( $options ) {
					return API_Facade::list_images(
						$directory,
						new API_Fields(
							array(
								'id',
								'imageMediaMetadata' => array( 'width', 'height' ),
								'thumbnailLink',
							)
						),
						( new Paging_Pagination_Helper() )->withValues( 0, 100 ),
						$options->get( 'image_ordering' )
					)->then(
						static function ( $images ) use ( $options ) {
							$images = self::without_excluded_images( $images );

							if ( 0 === count( $images ) ) {
								return false;
							}

							$image_metadata = $images[0]['imageMediaMetadata'];
							$dimension      = $image_metadata['width'] > $image_metadata['height'] ? 'h' : 'w';

							return substr( $images[0]['thumbnailLink'], 0, -4 ) .
								$dimension .
								floor( 1.25 * $options->get( 'grid_height' ) );
						}
					);
				},
				$dirs
			)
		);
	}

	/**
	 * Removes excluded image records from a thumbnail candidate list.
	 *
	 * @param array<array<string, mixed>> $images Google Drive image records.
	 *
	 * @return array<array<string, mixed>> Visible image records.
	 */
	private static function without_excluded_images( array $images ) {
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

	/**
	 * Creates API requests for directory item counts and subfolder previews.
	 *
	 * Returns counts of subdirectories, images and videos for each directory,
	 * plus the names and small thumbnails of the first 5 subdirectories for
	 * display inside the folder card.
	 *
	 * @param array<string> $dirs    A list of directory IDs.
	 * @param Options_Proxy $options The configuration of the gallery.
	 *
	 * @return PromiseInterface A promise resolving to arrays with keys `dircount`, `imagecount`, `videocount`, `subdirs`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	private static function item_counts( $dirs, $options ) {
		return Utils::all(
			array_map(
				static function ( $dir ) use ( $options ) {
					return Utils::all(
						array(
							API_Facade::list_directories(
								$dir,
								new API_Fields( array( 'name' ) ),
								new Single_Page_Pagination_Helper(),
								'name'
							),
							API_Facade::list_images(
								$dir,
								new API_Fields( array( 'createdTime' ) ),
								new Single_Page_Pagination_Helper(),
								'name'
							),
							API_Facade::list_videos(
								$dir,
								new API_Fields( array( 'createdTime' ) ),
								new Single_Page_Pagination_Helper(),
								'name'
							),
						)
					)->then(
						static function ( $items ) use ( $options ) {
							$subdirs = $items[0];
							$prefix  = $options->get( 'dir_prefix' );

							if ( '' !== $prefix ) {
								$subdirs = array_map(
									static function ( $file ) use ( $prefix ) {
										$pos          = mb_strpos( $file['name'], $prefix );
										$file['name'] = mb_substr( $file['name'], false !== $pos ? $pos + 1 : 0 );

										return $file;
									},
									$subdirs
								);
							}

							$top5     = array_slice( $subdirs, 0, 7 );
							$top5_ids = array_column( $top5, 'id' );

							return Utils::all(
								array(
									count( $items[0] ),
									count( $items[1] ),
									count( $items[2] ),
									$top5,
									self::subdir_thumbnail_images( $top5_ids ),
								)
							);
						}
					)->then(
						static function ( $data ) {
							list( $dircount, $imagecount, $videocount, $top5, $thumbnails ) = $data;
							$subdirs = array();
							$count   = count( $top5 );

							for ( $i = 0; $i < $count; ++$i ) {
								$subdirs[] = array(
									'name'      => $top5[ $i ]['name'],
									'thumbnail' => $thumbnails[ $i ],
								);
							}

							return array(
								'dircount'   => $dircount,
								'imagecount' => $imagecount,
								'subdirs'    => $subdirs,
								'videocount' => $videocount,
							);
						}
					);
				},
				$dirs
			)
		);
	}

	/**
	 * Fetches small thumbnails for a list of directories (used in folder card sublists).
	 *
	 * @param array<string> $dirs A list of directory IDs.
	 *
	 * @return PromiseInterface A promise resolving to a list of thumbnail URLs (or false when empty).
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	private static function subdir_thumbnail_images( $dirs ) {
		return Utils::all(
			array_map(
				static function ( $directory ) {
					return API_Facade::list_images(
						$directory,
						new API_Fields(
							array(
								'imageMediaMetadata' => array( 'width', 'height' ),
								'thumbnailLink',
							)
						),
						( new Paging_Pagination_Helper() )->withValues( 0, 1 ),
						'name'
					)->then(
						static function ( $images ) {
							if ( 0 === count( $images ) ) {
								return false;
							}

							// 48 px tall — enough for crisp display at 24 px (2× retina)
							return substr( $images[0]['thumbnailLink'], 0, -4 ) . 'h48';
						}
					);
				},
				$dirs
			)
		);
	}
}
