<?php
/**
 * Contains the Move_Files_CLI class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Frontend\Share_Drive;
use Avpvh\Options;
use Throwable;
use WP_CLI;
use function WP_CLI\Utils\format_items;

/**
 * WP-CLI commands that move files between the gallery's folders as planned
 * in a reviewed CSV (e.g. sorting a "WhatsApp" folder into the activities
 * the photos are from), and move them back.
 *
 * Moving keeps a file's Drive ID, so its tags, likes, marks, comments and
 * cached EXIF date stay with it. The moves are made by the service account
 * set in the plugin's settings (see Share_Drive). Each applied run gets a
 * batch ID whose receipt lists the moves made, so `undo-move-files` can put
 * exactly those files back.
 */
final class Move_Files_CLI {

	/**
	 * Option name prefix for the receipts of applied runs.
	 */
	private const RECEIPT_PREFIX = 'avpvh_move_files_';

	/**
	 * Transients that remember which folder a photo is in (see
	 * Photo_Filter_Scope and Photo_Date_Order); stale after moving.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const FOLDER_CACHES = array( 'avpvh_filter_parents', 'avpvh_folder_years', 'avpvh_photo_record_dates' );

	/**
	 * Moves files as planned in a CSV with (at least) the columns file_id and
	 * target, a folder path below the gallery root such as 03-Weekenden/2024
	 * Meerveld. Every file must now be in the --from folder.
	 *
	 * Without --apply this is a dry run: it checks every file and reports
	 * which folders would be made, but changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The CSV, or - to read it from standard input.
	 *
	 * --from=<folder-id>
	 * : The folder the files are in now.
	 *
	 * [--apply]
	 * : Make missing folders and move the files. Refused while any row is wrong.
	 *
	 * ## EXAMPLES
	 *
	 *     wp avpvh-gallery move-files plan.csv --from=1tKa...
	 *     wp avpvh-gallery move-files plan.csv --from=1tKa... --apply
	 *
	 * @param array<int, string>    $args       File.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 *
	 * @phan-suppress PhanPluginPossiblyStaticPublicMethod -- WP-CLI calls commands on an instance.
	 */
	public function move_files( $args, $assoc_args ) {
		$root = self::gallery_root_id();
		$from = (string) ( $assoc_args['from'] ?? '' );

		if ( ! Share_Drive::has_account() ) {
			WP_CLI::error( 'No service account set (Advanced settings › Sharing selections via Google Drive).' );
		}

		if ( '' === $root || '' === $from ) {
			WP_CLI::error( 'Needs a configured gallery root and --from.' );
		}

		$rows   = self::read_plan( $args[0] );
		$errors = self::check_sources( $rows, $from );

		self::report( $rows, self::resolve_targets( $rows, $root, false ), $errors );

		if ( 0 < $errors ) {
			WP_CLI::error( sprintf( '%d files are not in the --from folder; nothing moved.', $errors ) );
		}

		if ( ! isset( $assoc_args['apply'] ) ) {
			WP_CLI::success( 'Dry run; nothing moved.' );

			return;
		}

		self::apply( $rows, self::resolve_targets( $rows, $root, true ), $from );
	}

	/**
	 * Moves the files of an applied move-files run back where they came from.
	 *
	 * ## OPTIONS
	 *
	 * [<batch>]
	 * : The batch ID move-files printed. Omit to list the batches.
	 *
	 * @param array<int, string> $args Batch ID.
	 *
	 * @return void
	 *
	 * @phan-suppress PhanPluginPossiblyStaticPublicMethod -- WP-CLI calls commands on an instance.
	 */
	public function undo_move_files( $args ) {
		if ( ! isset( $args[0] ) ) {
			array_map( array( WP_CLI::class, 'log' ), self::batches() );

			return;
		}

		$moves = get_option( self::RECEIPT_PREFIX . $args[0] );

		if ( ! is_array( $moves ) ) {
			WP_CLI::error( 'No receipt for batch ' . $args[0] . '.' );
		}

		Share_Drive::move(
			array_map(
				static function ( $move ) {
					return array(
						'file_id' => $move['file_id'],
						'from'    => $move['to'],
						'to'      => $move['from'],
					);
				},
				$moves
			)
		);
		delete_option( self::RECEIPT_PREFIX . $args[0] );
		self::forget_folders();
		WP_CLI::success( sprintf( '%d files moved back.', count( $moves ) ) );
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
	 * The batch IDs that can be undone.
	 *
	 * @return array<string>
	 */
	private static function batches() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this command's own receipts.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$wpdb->esc_like( self::RECEIPT_PREFIX ) . '%'
			)
		);

		return array_map(
			static function ( $name ) {
				return substr( $name, strlen( self::RECEIPT_PREFIX ) );
			},
			$names
		);
	}

	/**
	 * The plan's rows: file_id and target, with the line number.
	 *
	 * @param string $file Path, or - for standard input.
	 *
	 * @return array<int, array{line: int, file_id: string, target: string}>
	 */
	private static function read_plan( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI input file.
		$handle = fopen( '-' === $file ? 'php://stdin' : $file, 'rb' );
		$header = false === $handle ? false : fgetcsv( $handle, null, ',', '"', '' );

		if (
			false === $handle
			|| ! is_array( $header )
			|| array() !== array_diff( array( 'file_id', 'target' ), $header )
		) {
			WP_CLI::error( 'Needs a readable CSV with the columns file_id and target.' );
		}

		$rows   = array();
		$line   = 1;
		$values = fgetcsv( $handle, null, ',', '"', '' );

		while ( false !== $values ) {
			++$line;

			if ( count( $values ) === count( $header ) ) {
				$row    = array_combine( $header, $values );
				$rows[] = array(
					'file_id' => trim( (string) $row['file_id'] ),
					'line'    => $line,
					'target'  => trim( (string) $row['target'], " /\t" ),
				);
			}

			$values = fgetcsv( $handle, null, ',', '"', '' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI input file.
		fclose( $handle );

		return $rows;
	}

	/**
	 * Warns about each file that isn't in the source folder now.
	 *
	 * @param array<int, array{line: int, file_id: string, target: string}> $rows Plan rows.
	 * @param string                                                        $from Source folder ID.
	 *
	 * @return int How many.
	 */
	private static function check_sources( array $rows, $from ) {
		$parents = Share_Drive::parents( array_column( $rows, 'file_id' ) );
		$errors  = 0;

		foreach ( $rows as $row ) {
			if ( in_array( $from, $parents[ $row['file_id'] ] ?? array(), true ) ) {
				continue;
			}

			++$errors;
			WP_CLI::warning( sprintf( 'Line %d: %s is not in the --from folder.', $row['line'], $row['file_id'] ) );
		}

		return $errors;
	}

	/**
	 * Each target path's folder ID ('' while it doesn't exist and $create is
	 * false).
	 *
	 * @param array<int, array{line: int, file_id: string, target: string}> $rows   Plan rows.
	 * @param string                                                        $root   Gallery root folder ID.
	 * @param bool                                                          $create Whether to make missing folders.
	 *
	 * @return array<string, string>
	 */
	private static function resolve_targets( array $rows, $root, $create ) {
		$folders = array();

		foreach ( array_unique( array_column( $rows, 'target' ) ) as $target ) {
			$folders[ $target ] = Share_Drive::folder_at( $root, $target, $create );
		}

		return $folders;
	}

	/**
	 * Prints how many files go to each folder and which folders are new.
	 *
	 * @param array<int, array{line: int, file_id: string, target: string}> $rows    Plan rows.
	 * @param array<string, string>                                         $folders Target path => folder ID ('' = new).
	 * @param int                                                           $errors  Files not in the source folder.
	 *
	 * @return void
	 */
	private static function report( array $rows, array $folders, $errors ) {
		$counts = array_count_values( array_column( $rows, 'target' ) );
		ksort( $counts );
		$table = array();

		foreach ( $counts as $target => $count ) {
			$table[] = array(
				'files'  => $count,
				'folder' => $target,
				'state'  => '' === $folders[ $target ] ? 'new' : 'exists',
			);
		}

		format_items( 'table', $table, array( 'folder', 'state', 'files' ) );
		$new = count( array_keys( $folders, '', true ) );
		WP_CLI::log(
			sprintf( '%d files, %d folders (%d new), %d errors.', count( $rows ), count( $folders ), $new, $errors )
		);
	}

	/**
	 * Moves the files, a batch at a time, keeping a receipt of every batch
	 * that was moved — also when a later one fails.
	 *
	 * @param array<int, array{line: int, file_id: string, target: string}> $rows    Plan rows.
	 * @param array<string, string>                                         $folders Target path => folder ID.
	 * @param string                                                        $from    Source folder ID.
	 *
	 * @return void
	 */
	private static function apply( array $rows, array $folders, $from ) {
		$batch = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false );
		$moved = array();

		try {
			foreach ( array_chunk( $rows, 100 ) as $chunk ) {
				$moves = array_map(
					static function ( $row ) use ( $folders, $from ) {
						return array(
							'file_id' => $row['file_id'],
							'from'    => $from,
							'to'      => $folders[ $row['target'] ],
						);
					},
					$chunk
				);
				Share_Drive::move( $moves );
				$moved = array_merge( $moved, $moves );
				WP_CLI::log( sprintf( '%d of %d moved', count( $moved ), count( $rows ) ) );
			}
		} catch ( Throwable $e ) {
			WP_CLI::warning( 'Stopped: ' . $e->getMessage() );
		}

		add_option( self::RECEIPT_PREFIX . $batch, $moved, '', false );
		self::forget_folders();

		$message = sprintf(
			'%d of %d files moved. Batch: %s (undo: wp avpvh-gallery undo-move-files %s)',
			count( $moved ),
			count( $rows ),
			$batch,
			$batch
		);

		if ( count( $moved ) < count( $rows ) ) {
			WP_CLI::error( $message );
		}

		WP_CLI::success( $message );
	}

	/**
	 * Drops the caches of which folder photos are in.
	 *
	 * @return void
	 */
	private static function forget_folders() {
		foreach ( self::FOLDER_CACHES as $transient ) {
			delete_transient( $transient );
		}
	}
}
