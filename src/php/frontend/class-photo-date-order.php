<?php
/**
 * Contains the Photo_Date_Order class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Client;
use Avpvh\API_Facade;

/**
 * The date a photo is shown and sorted by, and date order.
 *
 * A photo's date, in this order of preference: its EXIF capture date (the
 * agallery_photo_exif_dates cache, what the lightbox caption shows first);
 * the capture time Google Drive read from it (the caption's EXIF line); the
 * date in its file name ("IMG-20210804-WA0014.jpg", "20240729_153949.jpg"),
 * which the caption shows too when there is no other; and, only for
 * ordering, when the file was added to Drive.
 *
 * Photos from all over the gallery (a filter's matches) are ordered first by
 * the year of the folder they're in ("1983 Grobbendonk"), then by that date;
 * a folder's photos by that date alone. Undated ones come last.
 *
 * Folder years and the Drive-side dates are cached for a week, so ordering
 * the same matches again needs no Drive requests.
 */
final class Photo_Date_Order {

	/**
	 * Transient: folder ID => year (0 when the folder has none).
	 */
	private const YEARS_KEY = 'avpvh_folder_years';

	/**
	 * Transient: folder ID => name.
	 */
	private const NAMES_KEY = 'avpvh_folder_names';

	/**
	 * How many folders up a year is looked for (as Photo_Folder_Context).
	 */
	private const YEAR_DEPTH = 4;

	/**
	 * Transient: photo ID => its date without the EXIF cache ('Y-m-d
	 * H:i:s', '' when unknown; see record_date()).
	 */
	private const DRIVE_DATES_KEY = 'avpvh_photo_record_dates';

	/**
	 * The photos in date order: by folder year, then by date.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string>
	 */
	public static function sort( array $ids ) {
		if ( count( $ids ) < 2 ) {
			return array_values( $ids );
		}

		$years = self::years( $ids );
		$exif  = self::exif_dates( $ids );
		$other = self::record_dates( array_values( array_diff( $ids, array_keys( $exif ) ) ) );
		$keys  = array();

		foreach ( $ids as $file_id ) {
			$year             = $years[ $file_id ] ?? 0;
			$date             = $exif[ $file_id ] ?? ( $other[ $file_id ] ?? '' );
			$keys[ $file_id ] = array( 0 === $year ? 1 : 0, $year, '' === $date ? 1 : 0, $date, $file_id );
		}

		asort( $keys );

		return array_map( 'strval', array_keys( $keys ) );
	}

	/**
	 * Drive image records from one folder in date order, then by name.
	 *
	 * @param array<array<string, mixed>> $records Parsed Drive records with id, name, createdTime and imageMediaMetadata.time.
	 *
	 * @return array<array<string, mixed>>
	 */
	public static function sort_records( array $records ) {
		$records = array_values( $records );
		$exif    = self::exif_dates( array_map( 'strval', array_column( $records, 'id' ) ) );
		$keys    = array();

		foreach ( $records as $index => $record ) {
			$date           = $exif[ (string) $record['id'] ] ?? self::record_date( $record );
			$keys[ $index ] = array( '' === $date ? 1 : 0, $date, (string) ( $record['name'] ?? '' ) );
		}

		asort( $keys );

		return array_map(
			static function ( $index ) use ( $records ) {
				return $records[ $index ];
			},
			array_keys( $keys )
		);
	}

	/**
	 * The date in a file name, as 'Y-m-d H:i:s' (00:00:00 without a time),
	 * or '': "IMG-20210804-WA0014.jpg", "20240729_153949.jpg",
	 * "Screenshot_20220811-172622_Camera.jpg", "202608021219_IMG_7714.jpg".
	 *
	 * @param string $name File name.
	 *
	 * @return string
	 */
	public static function name_date( $name ) {
		if (
			1 !== preg_match(
				'/(?<!\d)((?:19|20)\d{2})(\d{2})(\d{2})(?:[_-]?([01]\d|2[0-3])([0-5]\d)([0-5]\d)?)?(?!\d)/',
				$name,
				$parts
			)
			|| ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] )
		) {
			return '';
		}

		$seconds = $parts[6] ?? '';

		return sprintf(
			'%s-%s-%s %s:%s:%s',
			$parts[1],
			$parts[2],
			$parts[3],
			$parts[4] ?? '00',
			$parts[5] ?? '00',
			'' === $seconds ? '00' : $seconds
		);
	}

	/**
	 * Folder names (cached; one batch for those not known yet).
	 *
	 * @param array<string> $folder_ids Drive folder IDs.
	 *
	 * @return array<string, string>
	 */
	public static function folder_names( array $folder_ids ) {
		$cached   = get_transient( self::NAMES_KEY );
		$known    = is_array( $cached ) ? $cached : array();
		$promises = array();

		foreach ( array_diff( $folder_ids, array_keys( $known ) ) as $folder_id ) {
			$promises[ $folder_id ] = API_Facade::get_file_name( $folder_id )->then(
				null,
				static function () {
					return '';
				}
			);
		}

		if ( array() !== $promises ) {
			foreach ( API_Client::execute( $promises ) as $folder_id => $name ) {
				$known[ (string) $folder_id ] = is_string( $name ) ? $name : '';
			}

			set_transient( self::NAMES_KEY, $known, WEEK_IN_SECONDS );
		}

		return array_intersect_key( $known, array_flip( $folder_ids ) );
	}

	/**
	 * Each photo's folder year (0 when unknown).
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, int>
	 */
	private static function years( array $ids ) {
		$parents = Photo_Filter_Scope::parents( $ids );
		$cached  = get_transient( self::YEARS_KEY );
		$known   = is_array( $cached ) ? $cached : array();
		$missing = array_diff( array_unique( array_filter( $parents ) ), array_keys( $known ) );

		if ( array() !== $missing ) {
			$known += self::folder_years( array_values( $missing ) );
			set_transient( self::YEARS_KEY, $known, WEEK_IN_SECONDS );
		}

		$years = array();

		foreach ( $parents as $file_id => $folder_id ) {
			$years[ $file_id ] = (int) ( $known[ $folder_id ] ?? 0 );
		}

		return $years;
	}

	/**
	 * Each folder's year: from the first folder name, going up from it, that
	 * starts with one ("1983 Grobbendonk" above "Rob"), at most YEAR_DEPTH
	 * levels up; 0 if none. One batch of Drive requests per level.
	 *
	 * @param array<string> $folder_ids Drive folder IDs.
	 *
	 * @return array<string, int>
	 */
	private static function folder_years( array $folder_ids ) {
		$years   = array_fill_keys( $folder_ids, 0 );
		$looking = array_combine( $folder_ids, $folder_ids );

		for ( $level = 0; $level < self::YEAR_DEPTH && array() !== $looking; ++$level ) {
			$current = array_values( array_unique( $looking ) );
			$names   = self::folder_names( $current );
			$parents = Photo_Filter_Scope::parents( $current );

			foreach ( $looking as $folder_id => $at ) {
				if ( 1 === preg_match( '/^(\d{4})\b/', trim( $names[ $at ] ?? '' ), $matches ) ) {
					$years[ $folder_id ] = (int) $matches[1];
					unset( $looking[ $folder_id ] );
				} elseif ( '' === ( $parents[ $at ] ?? '' ) ) {
					unset( $looking[ $folder_id ] );
				} else {
					$looking[ $folder_id ] = $parents[ $at ];
				}
			}
		}

		return $years;
	}

	/**
	 * The EXIF capture dates the cache has for these photos.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, string> Photo ID => 'Y-m-d H:i:s'.
	 */
	private static function exif_dates( array $ids ) {
		global $wpdb;
		$dates = array();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- plugin table, fixed name; the IDs are prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT image_id, original_datetime FROM {$wpdb->prefix}agallery_photo_exif_dates
					 WHERE original_datetime IS NOT NULL AND image_id IN ({$placeholders})",
					$chunk
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$dates[ (string) $row->image_id ] = (string) $row->original_datetime;
			}
		}

		return $dates;
	}

	/**
	 * Each photo's date apart from the EXIF cache (see record_date()),
	 * fetched from Drive once and cached.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, string> Photo ID => 'Y-m-d H:i:s' or ''.
	 */
	private static function record_dates( array $ids ) {
		$cached  = get_transient( self::DRIVE_DATES_KEY );
		$known   = is_array( $cached ) ? $cached : array();
		$missing = array_values( array_diff( $ids, array_keys( $known ) ) );

		if ( array() !== $missing ) {
			$promises = array();

			foreach ( $missing as $file_id ) {
				$promises[ $file_id ] = API_Facade::get_file(
					$file_id,
					array(
						'id',
						'name',
						'createdTime',
						'imageMediaMetadata' => array( 'time' ),
					)
				)->then(
					null,
					static function () {
						return array();
					}
				);
			}

			foreach ( API_Client::execute( $promises ) as $file_id => $file ) {
				$known[ (string) $file_id ] = self::record_date( is_array( $file ) ? $file : array() );
			}

			set_transient( self::DRIVE_DATES_KEY, $known, WEEK_IN_SECONDS );
		}

		return array_intersect_key( $known, array_flip( $ids ) );
	}

	/**
	 * A photo's date from its Drive record, as 'Y-m-d H:i:s': the capture
	 * time Drive read from it ("2019:05:04 12:00:00"), else the date in its
	 * name, else when it was added to Drive; '' if none.
	 *
	 * @param array<string, mixed> $file Parsed Drive fields.
	 *
	 * @return string
	 */
	private static function record_date( array $file ) {
		$taken = (string) ( $file['imageMediaMetadata']['time'] ?? '' );

		if (
			1 === preg_match(
				'/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})/',
				$taken,
				$parts
			)
			&& '0000' !== $parts[1]
		) {
			return "{$parts[1]}-{$parts[2]}-{$parts[3]} {$parts[4]}";
		}

		$named = self::name_date( (string) ( $file['name'] ?? '' ) );

		if ( '' !== $named ) {
			return $named;
		}

		$created = strtotime( (string) ( $file['createdTime'] ?? '' ) );

		return false === $created ? '' : gmdate( 'Y-m-d H:i:s', $created );
	}
}
