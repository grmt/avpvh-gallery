<?php
/**
 * Contains the Folder_Date_Correction class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * A folder's correction for its photos' camera clock, set in the EXIF
 * Inspector (agallery_folder_date_corrections).
 *
 * Some devices have no working clock: a film scanner that starts at
 * 2022-05-01 12:00 every time it's switched on writes that into every scan,
 * so the photos' EXIF date says nothing and ordering by it shuffles the scan
 * sessions together. For such a folder the EXIF date can be ignored, or, for
 * a camera whose clock was merely set wrong, shifted by a fixed offset.
 *
 * Covers both EXIF dates the gallery uses: the cached DateTimeOriginal and
 * the capture time Google Drive read (imageMediaMetadata.time). Ignored, the
 * photos fall back to the date in their file name, or else to name order —
 * not to when they were uploaded, which is just as arbitrary.
 *
 * Applies to the photos directly in the folder, like the folder mirroring.
 */
final class Folder_Date_Correction {

	/**
	 * Mode: don't use the EXIF date at all.
	 */
	public const IGNORE = 'ignore';

	/**
	 * Mode: add offset_seconds to the EXIF date.
	 */
	public const SHIFT = 'shift';

	/**
	 * Loaded corrections, by folder ID (null when a folder has none).
	 *
	 * @var array<string, array{mode: string, offset: int}|null>
	 */
	private static $loaded = array();

	/**
	 * The corrections of these folders; folders without one are left out.
	 *
	 * @param array<string> $folder_ids Drive folder IDs.
	 *
	 * @return array<string, array{mode: string, offset: int}>
	 */
	public static function for_folders( array $folder_ids ) {
		global $wpdb;
		$wanted  = array_unique( array_filter( $folder_ids ) );
		$missing = array_values( array_diff( $wanted, array_keys( self::$loaded ) ) );

		foreach ( array_chunk( $missing, 500 ) as $chunk ) {
			self::$loaded += array_fill_keys( $chunk, null );
			$placeholders  = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- plugin table, fixed name; the IDs are prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT folder_id, mode, offset_seconds FROM {$wpdb->prefix}agallery_folder_date_corrections
					 WHERE folder_id IN ({$placeholders})",
					$chunk
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				self::$loaded[ (string) $row->folder_id ] = array(
					'mode'   => (string) $row->mode,
					'offset' => (int) $row->offset_seconds,
				);
			}
		}

		return array_filter( array_intersect_key( self::$loaded, array_flip( $folder_ids ) ) );
	}

	/**
	 * One folder's correction, or null.
	 *
	 * @param string $folder_id Drive folder ID.
	 *
	 * @return array{mode: string, offset: int}|null
	 */
	public static function for_folder( $folder_id ) {
		return self::for_folders( array( $folder_id ) )[ $folder_id ] ?? null;
	}

	/**
	 * The correction of each photo's folder, by photo ID; photos whose
	 * folder has none are left out.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, array{mode: string, offset: int}>
	 */
	public static function for_photos( array $ids ) {
		$parents = Photo_Filter_Scope::parents( $ids );
		$fixes   = self::for_folders( array_values( $parents ) );
		$by_id   = array();

		foreach ( $parents as $file_id => $folder_id ) {
			if ( isset( $fixes[ $folder_id ] ) ) {
				$by_id[ (string) $file_id ] = $fixes[ $folder_id ];
			}
		}

		return $by_id;
	}

	/**
	 * Saves a folder's correction; mode '' removes it.
	 *
	 * @param string $folder_id Drive folder ID.
	 * @param string $mode      IGNORE, SHIFT or ''.
	 * @param int    $offset    Seconds to add, for SHIFT.
	 *
	 * @return void
	 */
	public static function save( $folder_id, $mode, $offset ) {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_folder_date_corrections';

		if ( self::IGNORE === $mode || self::SHIFT === $mode ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->replace(
				$table,
				array(
					'folder_id'      => $folder_id,
					'mode'           => $mode,
					'offset_seconds' => self::SHIFT === $mode ? $offset : 0,
					'updated_by'     => get_current_user_id(),
				),
				array( '%s', '%s', '%d', '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->delete( $table, array( 'folder_id' => $folder_id ), array( '%s' ) );
		}

		unset( self::$loaded[ $folder_id ] );
	}

	/**
	 * A 'Y-m-d H:i:s' EXIF date as corrected: '' when ignored, shifted, or
	 * unchanged without a correction.
	 *
	 * @param string                                $datetime   'Y-m-d H:i:s' (or '').
	 * @param array{mode: string, offset: int}|null $correction The folder's correction.
	 *
	 * @return string
	 */
	public static function apply( $datetime, $correction ) {
		if ( null === $correction || '' === $datetime ) {
			return $datetime;
		}

		if ( self::IGNORE === $correction['mode'] ) {
			return '';
		}

		$time = strtotime( $datetime . ' UTC' );

		return false === $time ? $datetime : gmdate( 'Y-m-d H:i:s', $time + $correction['offset'] );
	}

	/**
	 * Drive image records from one folder with its correction applied to
	 * the capture time Drive read (imageMediaMetadata.time, "Y:m:d H:i:s").
	 * Ignored, the upload time (createdTime) goes too, so nothing orders the
	 * photos by it instead of by name.
	 *
	 * @param array<array<string, mixed>> $records   Parsed Drive records.
	 * @param string                      $folder_id The folder they're in.
	 *
	 * @return array<array<string, mixed>>
	 */
	public static function apply_to_records( array $records, $folder_id ) {
		$correction = self::for_folder( $folder_id );

		if ( null === $correction ) {
			return $records;
		}

		return array_map(
			static function ( $record ) use ( $correction ) {
				return self::apply_to_record( $record, $correction );
			},
			$records
		);
	}

	/**
	 * One Drive record with a correction applied (see apply_to_records()).
	 *
	 * @param array<string, mixed>             $record     Parsed Drive record.
	 * @param array{mode: string, offset: int} $correction The folder's correction.
	 *
	 * @return array<string, mixed>
	 */
	public static function apply_to_record( array $record, array $correction ) {
		if ( self::IGNORE === $correction['mode'] ) {
			unset( $record['imageMediaMetadata']['time'], $record['createdTime'] );

			return $record;
		}

		$taken = (string) ( $record['imageMediaMetadata']['time'] ?? '' );

		if (
			1 === preg_match( '/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})/', $taken, $parts )
			&& '0000' !== $parts[1]
		) {
			$shifted = self::apply( "{$parts[1]}-{$parts[2]}-{$parts[3]} {$parts[4]}", $correction );

			$record['imageMediaMetadata']['time'] = str_replace( '-', ':', $shifted );
		}

		return $record;
	}
}
