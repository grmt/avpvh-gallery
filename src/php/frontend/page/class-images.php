<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- single-class file per project convention; grew with the new exclusion-filtering and orientation-correction merging logic.
/**
 * Contains the Images class.
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
use Avpvh\Vendor\GuzzleHttp\Promise\PromiseInterface;
use DateTime;

// phpcs:disable SlevomatCodingStandard.Classes.ClassLength.ClassTooLong -- see FileTooLong suppression above.
/**
 * Contains all the functions used to display images in a gallery.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
final class Images {

	/**
	 * Returns a list of images in a directory
	 *
	 * @param string            $parent_id A directory to list items of.
	 * @param Pagination_Helper $pagination_helper An initialized pagination helper.
	 * @param Options_Proxy     $options The configuration of the gallery.
	 *
	 * @return PromiseInterface A promise resolving to a list of images in the format `['id' => 'id', 'description' => 'description', 'image' => 'image', 'thumbnail' => 'thumbnail', 'width' => width, 'height' => height]`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function get( $parent_id, $pagination_helper, $options ) {
		if ( 'time' === $options->get_by( 'image_ordering' ) ) {
			$order_by = 'name';
			$fields   = new API_Fields(
				array(
					'id',
					'name',
					'thumbnailLink',
					'createdTime',
					'imageMediaMetadata' => array(
						'time',
						'width',
						'height',
						'rotation',
						'cameraMake',
						'cameraModel',
						'aperture',
						'exposureTime',
						'isoSpeed',
						'focalLength',
					),
					'description',
				)
			);
		} else {
			$order_by = $options->get( 'image_ordering' );
			$fields   = new API_Fields(
				array(
					'id',
					'name',
					'thumbnailLink',
					'imageMediaMetadata' => array(
						'width',
						'height',
						'rotation',
						'cameraMake',
						'cameraModel',
						'aperture',
						'exposureTime',
						'isoSpeed',
						'focalLength',
					),
					'description',
				)
			);
		}

		return API_Facade::list_images( $parent_id, $fields, $pagination_helper, $order_by )->then(
			static function ( $image_response ) use ( $options, $parent_id ) {
				$image_response = self::filter_excluded( $image_response );
				$images         = array_map(
					static function ( $image ) use ( $options ) {
						return self::format_image( $image, $options );
					},
					$image_response
				);

				$images = self::merge_corrections( $images, $parent_id, $options );

				$image_timestamps = array_map(
					static function ( $image ) use ( $options ) {
						return self::extract_timestamp( $image, $options );
					},
					$image_response
				);

				return self::order( $images, $image_timestamps, $options );
			}
		);
	}

	/**
	 * Normalizes a raw Google Drive image record into the gallery's image shape.
	 *
	 * @param array<string, mixed> $image The raw Google Drive image record.
	 * @param Options_Proxy        $options The configuration of the gallery.
	 *
	 * @return array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int} The normalized image record.
	 *
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 */
	private static function format_image( $image, $options ) {
		$metadata = array_key_exists( 'imageMediaMetadata', $image ) && is_array( $image['imageMediaMetadata'] )
			? $image['imageMediaMetadata']
			: array();
		$width    = array_key_exists( 'width', $metadata ) && is_numeric( $metadata['width'] )
			? intval( $metadata['width'] )
			: 0;
		$height   = array_key_exists( 'height', $metadata ) && is_numeric( $metadata['height'] )
			? intval( $metadata['height'] )
			: 0;

		return array(
			'description' => array_key_exists( 'description', $image ) ? esc_attr( $image['description'] ) : '',
			'exif'        => self::format_exif( $metadata ),
			'height'      => $height,
			'id'          => $image['id'],
			'image'       => substr( $image['thumbnailLink'], 0, -3 ) . $options->get( 'preview_size' ),
			'name'        => array_key_exists( 'name', $image ) ? $image['name'] : '',
			'rotation'    => array_key_exists( 'rotation', $metadata ) && is_int( $metadata['rotation'] )
				? $metadata['rotation']
				: 0,
			'thumbnail'   => substr( $image['thumbnailLink'], 0, -4 ) .
				'h' .
				floor( 1.25 * $options->get( 'grid_height' ) ),
			'width'       => $width,
		);
	}

	/**
	 * Builds the display-ready EXIF summary for an image, dropping any fields that weren't present.
	 *
	 * @param array<string, mixed> $metadata The image's `imageMediaMetadata` fields (possibly empty).
	 *
	 * @return array<string, mixed> The non-null EXIF fields.
	 */
	private static function format_exif( $metadata ) {
		return array_filter(
			array(
				'aperture' => self::numeric_metadata_value(
					$metadata,
					'aperture',
					static function ( $value ) {
						return round( floatval( $value ), 1 );
					}
				),
				'exposure' => self::numeric_metadata_value( $metadata, 'exposureTime', 'floatval' ),
				'focal'    => self::numeric_metadata_value(
					$metadata,
					'focalLength',
					static function ( $value ) {
						return round( floatval( $value ) );
					}
				),
				'iso'      => self::numeric_metadata_value( $metadata, 'isoSpeed', 'intval' ),
				'make'     => array_key_exists( 'cameraMake', $metadata ) ? $metadata['cameraMake'] : null,
				'model'    => array_key_exists( 'cameraModel', $metadata ) ? $metadata['cameraModel'] : null,
				'time'     => array_key_exists( 'time', $metadata ) ? $metadata['time'] : null,
			),
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Reads and transforms a numeric EXIF metadata value, if present.
	 *
	 * @param array<string, mixed> $metadata The image's `imageMediaMetadata` fields.
	 * @param string               $key The metadata key to read.
	 * @param callable             $transform Applied to the raw value before it's returned.
	 *
	 * @return mixed The transformed value, or null if the key is missing or not numeric.
	 */
	private static function numeric_metadata_value( $metadata, $key, $transform ) {
		if ( ! array_key_exists( $key, $metadata ) || ! is_numeric( $metadata[ $key ] ) ) {
			return null;
		}

		return call_user_func( $transform, $metadata[ $key ] );
	}

	/**
	 * Removes photos excluded by an administrator before gallery rendering.
	 *
	 * @param array<array<string, mixed>> $images Raw Google Drive image records.
	 *
	 * @return array<array<string, mixed>> Visible image records.
	 */
	private static function filter_excluded( array $images ) {
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

		$excluded_lookup = array_fill_keys( $excluded, true );

		return array_values(
			array_filter(
				$images,
				static function ( $image ) use ( $excluded_lookup ) {
					return ! isset( $excluded_lookup[ $image['id'] ] );
				}
			)
		);
	}

	/**
	 * Merges stored orientation corrections into a list of images.
	 *
	 * @param array<array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int}> $images A list of mapped image arrays.
	 * @param string                                                                                                                                                            $folder_id Current Google Drive folder ID.
	 * @param Options_Proxy                                                                                                                                                     $options Gallery options, including the active lightbox size.
	 *
	 * @return array<array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int, thumb_rotation: int, thumb_h_flip: bool, thumb_v_flip: bool, light_rotation: int, light_h_flip: bool, light_v_flip: bool, light_has_correction: bool}> Images with thumb_rotation and light_rotation added.
	 */
	private static function merge_corrections( array $images, $folder_id, $options ) {
		if ( array() === $images ) {
			return $images;
		}

		global $wpdb;
		$ids          = array_column( $images, 'id' );
		$table        = $wpdb->prefix . 'agallery_photo_corrections';
		$folder_table = $wpdb->prefix . 'agallery_folder_corrections';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		$preview_key  = 's' . intval( $options->get( 'preview_size' ) );
		$args         = array_merge( array( 'grid', 'lightbox', $preview_key ), $ids );

		$corrections_sql = 'SELECT image_id, size_key, rotation, h_flip, v_flip FROM ' . $table .
			' WHERE size_key IN (%s, %s, %s) AND image_id IN (' . $placeholders . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$rows_result = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table/$placeholders are built above (not user-supplied); placeholder count is dynamic (3 static + one per ID), $args holds all of them in order, passed as a single array per $wpdb->prepare()'s supported calling convention.
			$wpdb->prepare( $corrections_sql, $args ),
			ARRAY_A
		);
		$corrections = self::index_corrections_by_image( is_array( $rows_result ) ? $rows_result : array() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$folder_rows_result = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $folder_table is concatenated (not user-supplied); values are parameterized via %s.
				'SELECT size_key, rotation, h_flip, v_flip FROM ' . $folder_table .
					' WHERE folder_id = %s AND size_key IN (%s, %s)',
				$folder_id,
				'grid',
				'lightbox'
			),
			ARRAY_A
		);
		$folder_rows        = is_array( $folder_rows_result ) ? $folder_rows_result : array();
		$folder_corrections = array();

		foreach ( $folder_rows as $row ) {
			$folder_corrections[ $row['size_key'] ] = array(
				'h_flip'   => (bool) intval( $row['h_flip'] ),
				'rotation' => intval( $row['rotation'] ),
				'v_flip'   => (bool) intval( $row['v_flip'] ),
			);
		}

		return array_map(
			static function ( $image ) use ( $corrections, $folder_corrections, $preview_key ) {
				return self::apply_correction( $image, $corrections, $folder_corrections, $preview_key );
			},
			$images
		);
	}

	/**
	 * Indexes raw photo-corrections rows by image ID and size key.
	 *
	 * @param array<array<string, mixed>> $rows Raw rows from the photo corrections table.
	 *
	 * @return array<string, array<string, array{rotation: int, h_flip: bool, v_flip: bool}>> Corrections indexed by image ID, then size key.
	 */
	private static function index_corrections_by_image( $rows ) {
		$corrections = array();

		foreach ( $rows as $row ) {
			$corrections[ $row['image_id'] ][ $row['size_key'] ] = array(
				'h_flip'   => (bool) intval( $row['h_flip'] ),
				'rotation' => intval( $row['rotation'] ),
				'v_flip'   => (bool) intval( $row['v_flip'] ),
			);
		}

		return $corrections;
	}

	/**
	 * Applies stored thumb/lightbox orientation corrections to a single image.
	 *
	 * @param array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int} $image The mapped image array.
	 * @param array<string, array<string, array<string, mixed>>>                                                                                                         $corrections Per-photo corrections, indexed by image ID then size key.
	 * @param array<string, array<string, mixed>>                                                                                                                        $folder_corrections Folder-level corrections, indexed by size key.
	 * @param string                                                                                                                                                     $preview_key The size key used for the configured lightbox preview size.
	 *
	 * @return array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int, thumb_rotation: int, thumb_h_flip: bool, thumb_v_flip: bool, light_rotation: int, light_h_flip: bool, light_v_flip: bool, light_has_correction: bool} The image with correction fields added.
	 *
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 */
	private static function apply_correction( $image, $corrections, $folder_corrections, $preview_key ) {
		$photo = isset( $corrections[ $image['id'] ] ) ? $corrections[ $image['id'] ] : array();
		$grid  = self::resolve_size_correction( $photo, $folder_corrections, 'grid' );
		$light = self::resolve_size_correction( $photo, $folder_corrections, 'lightbox', $preview_key );

		$image['thumb_rotation']       = isset( $grid['rotation'] ) ? $grid['rotation'] : 0;
		$image['thumb_h_flip']         = isset( $grid['h_flip'] ) ? $grid['h_flip'] : false;
		$image['thumb_v_flip']         = isset( $grid['v_flip'] ) ? $grid['v_flip'] : false;
		$image['light_rotation']       = isset( $light['rotation'] ) ? $light['rotation'] : 0;
		$image['light_h_flip']         = isset( $light['h_flip'] ) ? $light['h_flip'] : false;
		$image['light_v_flip']         = isset( $light['v_flip'] ) ? $light['v_flip'] : false;
		$image['light_has_correction'] = array_key_exists( 'lightbox', $photo ) ||
			array_key_exists( $preview_key, $photo ) ||
			array_key_exists( 'lightbox', $folder_corrections );

		return self::swap_dimensions_if_rotated( $image );
	}

	/**
	 * Resolves the correction to apply for one size, falling back from the per-photo
	 * override to an alternate per-photo size key, then to the folder-level default.
	 *
	 * @param array<string, array<string, mixed>> $photo Per-photo corrections, indexed by size key.
	 * @param array<string, array<string, mixed>> $folder_corrections Folder-level corrections, indexed by size key.
	 * @param string                              $size_key The size key to resolve (`grid` or `lightbox`).
	 * @param string|null                         $fallback_photo_key An alternate per-photo size key to try before falling back to the folder default.
	 *
	 * @return array<string, mixed> The resolved correction (rotation/h_flip/v_flip), or an empty array if none apply.
	 */
	private static function resolve_size_correction(
		$photo,
		$folder_corrections,
		$size_key,
		$fallback_photo_key = null
	) {
		if ( array_key_exists( $size_key, $photo ) ) {
			return $photo[ $size_key ];
		}

		if ( null !== $fallback_photo_key && isset( $photo[ $fallback_photo_key ] ) ) {
			return $photo[ $fallback_photo_key ];
		}

		return isset( $folder_corrections[ $size_key ] ) ? $folder_corrections[ $size_key ] : array();
	}

	/**
	 * Swaps an image's width/height when its lightbox rotation is a quarter turn.
	 *
	 * Google's 1920px derivative has no dependable EXIF orientation and may or may
	 * not already have rotated pixels. This estimate is replaced client-side by the
	 * derivative's actual natural dimensions after load.
	 *
	 * @param array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int, thumb_rotation: int, thumb_h_flip: bool, thumb_v_flip: bool, light_rotation: int, light_h_flip: bool, light_v_flip: bool, light_has_correction: bool} $image The mapped image array, with `light_rotation` already set.
	 *
	 * @return array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int, thumb_rotation: int, thumb_h_flip: bool, thumb_v_flip: bool, light_rotation: int, light_h_flip: bool, light_v_flip: bool, light_has_correction: bool} The image, with `width`/`height` swapped if needed.
	 */
	private static function swap_dimensions_if_rotated( $image ) {
		$effective_rotation = $image['light_rotation'];

		if ( 90 !== $effective_rotation && 270 !== $effective_rotation ) {
			return $image;
		}

		$raw_width       = $image['width'];
		$image['width']  = $image['height'];
		$image['height'] = $raw_width;

		return $image;
	}

	/**
	 * Extracts a timestamp from an image
	 *
	 * @param array<string, mixed> $image An image.
	 * @param Options_Proxy        $options The configuration of the gallery.
	 *
	 * @return int The timestamp.
	 */
	private static function extract_timestamp( $image, $options ) {
		if ( 'time' !== $options->get_by( 'image_ordering' ) ) {
			return time();
		}

		$timestamp = array_key_exists( 'imageMediaMetadata', $image ) &&
			array_key_exists( 'time', $image['imageMediaMetadata'] )
			? DateTime::createFromFormat( 'Y:m:d H:i:s', $image['imageMediaMetadata']['time'] )
			: ( array_key_exists( 'createdTime', $image )
			? DateTime::createFromFormat( 'Y-m-d\TH:i:s.uP', $image['createdTime'] )
			: false );

		return false !== $timestamp ? intval( $timestamp->format( 'U' ) ) : time();
	}

	/**
	 * Orders images.
	 *
	 * @param array<array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int, thumb_rotation: int, thumb_h_flip: bool, thumb_v_flip: bool, light_rotation: int, light_h_flip: bool, light_v_flip: bool, light_has_correction: bool}> $images A list of images.
	 * @param array<int>                                                                                                                                                                                                                                                                                                              $image_timestamps The timestamps for each image.
	 * @param Options_Proxy                                                                                                                                                                                                                                                                                                           $options The configuration of the gallery.
	 *
	 * @return array<array{description: string, exif: array<string, mixed>, height: int, id: string, image: string, name: string, rotation: int, thumbnail: string, width: int, thumb_rotation: int, thumb_h_flip: bool, thumb_v_flip: bool, light_rotation: int, light_h_flip: bool, light_v_flip: bool, light_has_correction: bool}> An ordered list of images.
	 */
	private static function order( $images, $image_timestamps, $options ) {
		if ( 'time' === $options->get_by( 'image_ordering' ) ) {
			uksort(
				$images,
				static function ( $first_index, $second_index ) use ( $image_timestamps, $options ) {
					$asc = $image_timestamps[ $first_index ] - $image_timestamps[ $second_index ];

					return 'ascending' === $options->get_order( 'image_ordering' ) ? $asc : -$asc;
				}
			);
		}

		return array_values( $images );
	}
}
