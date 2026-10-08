<?php
/**
 * Contains the Share_Orientation class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Options;

/**
 * The orientation corrections the lightbox applies to photos (see
 * Images::merge_corrections), for Share_Image to bake into shared copies:
 * the photo's own lightbox correction, else its correction for the preview
 * size, else its folder's lightbox correction.
 */
final class Share_Orientation {

	/**
	 * No correction.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	public const NONE = array(
		'h_flip'   => false,
		'rotation' => 0,
		'v_flip'   => false,
	);

	/**
	 * Each photo's correction (only for photos that have one).
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, array{h_flip: bool, rotation: int, v_flip: bool}>
	 */
	public static function for_photos( array $ids ) {
		$preview_key = 's' . (int) Options::$preview_size->get();
		$photos      = self::rows(
			'agallery_photo_corrections',
			'image_id',
			$ids,
			array( 'lightbox', $preview_key )
		);
		$parents     = Photo_Filter_Scope::parents( $ids );
		$folders     = self::rows(
			'agallery_folder_corrections',
			'folder_id',
			array_unique( $parents ),
			array( 'lightbox' )
		);
		$corrections = array();

		foreach ( $ids as $file_id ) {
			$correction = $photos[ $file_id ]['lightbox']
				?? $photos[ $file_id ][ $preview_key ]
				?? $folders[ $parents[ $file_id ] ?? '' ]['lightbox']
				?? null;

			if ( null !== $correction ) {
				$corrections[ $file_id ] = $correction;
			}
		}

		return $corrections;
	}

	/**
	 * Stored corrections by ID and size key.
	 *
	 * @param string        $table     Table name without prefix.
	 * @param string        $id_column The ID column (image_id or folder_id).
	 * @param array<string> $ids       IDs.
	 * @param array<string> $sizes     Size keys.
	 *
	 * @return array<string, array<string, array{h_flip: bool, rotation: int, v_flip: bool}>>
	 */
	private static function rows( $table, $id_column, array $ids, array $sizes ) {
		global $wpdb;
		$found = array();

		foreach ( array_chunk( array_values( array_filter( $ids ) ), 500 ) as $chunk ) {
			$id_marks   = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$size_marks = implode( ', ', array_fill( 0, count( $sizes ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- plugin tables, fixed names; the values are prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$id_column} AS id, size_key, rotation, h_flip, v_flip FROM {$wpdb->prefix}{$table}
					 WHERE size_key IN ({$size_marks}) AND {$id_column} IN ({$id_marks})",
					array_merge( $sizes, $chunk )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$found[ (string) $row->id ][ (string) $row->size_key ] = array(
					'h_flip'   => (bool) (int) $row->h_flip,
					'rotation' => (int) $row->rotation,
					'v_flip'   => (bool) (int) $row->v_flip,
				);
			}
		}

		return $found;
	}
}
