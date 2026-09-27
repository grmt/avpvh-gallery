<?php
/**
 * Contains the Exif_Dates_CLI class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Admin\Exif_Inspector\Exif_Data_REST;
use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Paging_Pagination_Helper;
use Avpvh\Options;
use Throwable;
use WP_CLI;
use WP_Error;

/**
 * WP-CLI command that pre-fills the EXIF DateTimeOriginal cache
 * (`agallery_photo_exif_dates`) for every photo under the gallery root.
 *
 * The public lightbox only ever reads that cache (see Frontend\Exif_Date_REST),
 * and normally it's only filled when an admin opens a photo in the EXIF
 * Inspector. This is the one-off, admin-run way to fill it for all the photos
 * nobody has inspected yet — it downloads the same 128 KB EXIF header the
 * inspector does, once per photo. Inspecting a photo afterwards still
 * overwrites its cached value, as before.
 */
final class Exif_Dates_CLI {

	/**
	 * Fills the EXIF capture-date cache for photos that aren't in it yet.
	 *
	 * ## OPTIONS
	 *
	 * [--folder=<id>]
	 * : Drive folder ID to start from. Defaults to the configured gallery root.
	 *
	 * [--force]
	 * : Re-read photos that are already cached, too.
	 *
	 * [--limit=<n>]
	 * : Stop after downloading this many photos.
	 *
	 * [--dry-run]
	 * : Only walk the folders and report how many photos would be read.
	 *
	 * ## EXAMPLES
	 *
	 *     wp avpvh-gallery backfill-exif-dates --dry-run
	 *     wp avpvh-gallery backfill-exif-dates --limit=100
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 *
	 * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
	 * @phan-suppress PhanUnusedPublicFinalMethodParameter, PhanPluginPossiblyStaticPublicMethod -- WP-CLI calls subcommands on an instance.
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
	 */
	public function backfill_exif_dates( $args, $assoc_args ) {
		$root_id = isset( $assoc_args['folder'] ) ? $assoc_args['folder'] : self::gallery_root_id();

		if ( '' === $root_id ) {
			WP_CLI::error( 'No gallery root configured; pass --folder=<id>.' );
		}

		WP_CLI::log( 'Walking folders under ' . $root_id . ' ...' );
		$photos = self::collect_photos( $root_id );
		$todo   = self::photos_to_read( $photos, isset( $assoc_args['force'] ) );

		if ( isset( $assoc_args['limit'] ) ) {
			$todo = array_slice( $todo, 0, max( 0, intval( $assoc_args['limit'] ) ), true );
		}

		WP_CLI::log( sprintf( '%d photos found, %d to read.', count( $photos ), count( $todo ) ) );

		if ( isset( $assoc_args['dry-run'] ) ) {
			return;
		}

		self::read_photos( $todo );
	}

	/**
	 * Returns the configured gallery root folder ID, or '' if there is none.
	 *
	 * @return string
	 */
	private static function gallery_root_id() {
		$root_path = Options::$root_path->get();
		$root_id   = is_array( $root_path ) && array() !== $root_path ? end( $root_path ) : '';

		return is_string( $root_id ) ? $root_id : '';
	}

	/**
	 * Walks the folder tree breadth-first and collects every EXIF-readable photo.
	 *
	 * @param string $root_id The folder to start from.
	 *
	 * @return array<string, string> Photo ID (as the gallery knows it) → ID to download from.
	 */
	private static function collect_photos( $root_id ) {
		$queue   = array( $root_id );
		$visited = array( $root_id => true );
		$photos  = array();

		while ( array() !== $queue ) {
			$folder_id = (string) array_shift( $queue );

			try {
				list( $directories, $images ) = self::fetch_folder_contents( $folder_id );
			} catch ( Throwable $e ) {
				WP_CLI::warning( 'Skipping folder ' . $folder_id . ': ' . $e->getMessage() );

				continue;
			}

			foreach ( self::child_folder_ids( $directories ) as $child_id ) {
				if ( isset( $visited[ $child_id ] ) ) {
					continue;
				}

				$visited[ $child_id ] = true;
				$queue[]              = $child_id;
			}

			$photos = array_merge( $photos, self::readable_photos( $images ) );
		}

		return $photos;
	}

	/**
	 * Lists one folder's immediate subfolders and images.
	 *
	 * @param string $folder_id The folder to list.
	 *
	 * @return array{0: array<array<string, mixed>>, 1: array<array<string, mixed>>} Directories, then images.
	 */
	private static function fetch_folder_contents( $folder_id ) {
		$all_items = static function () {
			return ( new Paging_Pagination_Helper() )->withValues( 0, 1000000 );
		};
		$fields    = static function () {
			return new API_Fields(
				array(
					'id',
					'mimeType',
					'shortcutDetails' => array( 'targetId', 'targetMimeType' ),
				)
			);
		};

		$results = API_Client::execute(
			array(
				API_Facade::list_directories( $folder_id, $fields(), $all_items(), 'name' ),
				API_Facade::list_images( $folder_id, $fields(), $all_items(), 'name' ),
			)
		);

		return array( $results[0], $results[1] );
	}

	/**
	 * Resolves subfolder records (including folder shortcuts) to the IDs to descend into.
	 *
	 * @param array<array<string, mixed>> $directories Subfolder records.
	 *
	 * @return array<string>
	 */
	private static function child_folder_ids( array $directories ) {
		$ids = array();

		foreach ( $directories as $directory ) {
			$folder_id = isset( $directory['shortcutDetails']['targetId'] )
				? (string) $directory['shortcutDetails']['targetId']
				: ( isset( $directory['id'] ) ? (string) $directory['id'] : '' );

			if ( '' !== $folder_id ) {
				$ids[] = $folder_id;
			}
		}

		return $ids;
	}

	/**
	 * Keeps only images whose format carries a readable EXIF header.
	 *
	 * @param array<array<string, mixed>> $images Image records (possibly shortcuts).
	 *
	 * @return array<string, string> Photo ID → ID to download from.
	 */
	private static function readable_photos( array $images ) {
		$photos = array();

		foreach ( $images as $image ) {
			if ( ! isset( $image['id'] ) || ! self::has_exif_header( self::photo_mime_type( $image ) ) ) {
				continue;
			}

			$photos[ (string) $image['id'] ] = isset( $image['shortcutDetails']['targetId'] )
				? (string) $image['shortcutDetails']['targetId']
				: (string) $image['id'];
		}

		return $photos;
	}

	/**
	 * Returns an image's own mimeType, or its target's if it's a shortcut.
	 *
	 * @param array<string, mixed> $image Image record.
	 *
	 * @return string
	 */
	private static function photo_mime_type( array $image ) {
		if ( isset( $image['shortcutDetails']['targetId'] ) ) {
			return isset( $image['shortcutDetails']['targetMimeType'] )
				? (string) $image['shortcutDetails']['targetMimeType']
				: '';
		}

		return isset( $image['mimeType'] ) ? (string) $image['mimeType'] : '';
	}

	/**
	 * Whether exif_read_data() can read an EXIF header from files of this mimeType.
	 *
	 * @param string $mime_type Drive mimeType.
	 *
	 * @return bool
	 */
	private static function has_exif_header( $mime_type ) {
		return in_array( $mime_type, array( 'image/jpeg', 'image/pjpeg', 'image/tiff' ), true );
	}

	/**
	 * Drops photos that are already cached, unless forced.
	 *
	 * @param array<string, string> $photos Photo ID → download ID.
	 * @param bool                  $force  Whether to keep already-cached photos.
	 *
	 * @return array<string, string>
	 */
	private static function photos_to_read( array $photos, $force ) {
		if ( $force ) {
			return $photos;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_exif_dates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table, fixed name.
		$cached = $wpdb->get_col( "SELECT image_id FROM {$table}" );

		return array_diff_key( $photos, array_fill_keys( $cached, true ) );
	}

	/**
	 * Downloads each photo's EXIF header and caches its capture date.
	 *
	 * @param array<string, string> $photos Photo ID → download ID.
	 *
	 * @return void
	 */
	private static function read_photos( array $photos ) {
		$total  = count( $photos );
		$done   = 0;
		$dated  = 0;
		$failed = 0;

		foreach ( $photos as $file_id => $download_id ) {
			++$done;
			$result = self::read_one( (string) $file_id, $download_id );

			if ( $result instanceof WP_Error ) {
				++$failed;
				WP_CLI::warning( sprintf( '[%d/%d] %s: %s', $done, $total, $file_id, $result->get_error_message() ) );

				continue;
			}

			if ( null !== $result ) {
				++$dated;
			}

			WP_CLI::log( sprintf( '[%d/%d] %s: %s', $done, $total, $file_id, $result ?? 'no DateTimeOriginal' ) );
		}

		WP_CLI::success( sprintf( '%d read, %d with a capture date, %d failed.', $done - $failed, $dated, $failed ) );
	}

	/**
	 * Reads and caches one photo, turning unexpected exceptions into errors so
	 * one bad file doesn't stop the whole run.
	 *
	 * @param string $file_id     Photo ID (cache key).
	 * @param string $download_id ID to download from.
	 *
	 * @return string|WP_Error|null
	 */
	private static function read_one( $file_id, $download_id ) {
		try {
			return Exif_Data_REST::refresh_original_datetime( $file_id, $download_id );
		} catch ( Throwable $e ) {
			return new WP_Error( 'exif_backfill_failed', $e->getMessage() );
		}
	}
}
