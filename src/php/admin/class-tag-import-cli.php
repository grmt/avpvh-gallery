<?php
/**
 * Contains the Tag_Import_CLI class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Paging_Pagination_Helper;
use Avpvh\Frontend\Subject_Tag_Tree;
use Avpvh\Options;
use Avpvh\Tag_Log;
use RuntimeException;
use Throwable;
use WP_CLI;

/**
 * WP-CLI commands that bulk-import a reviewed CSV of subject-tag votes, and
 * undo such an import.
 *
 * Every CSV row is one person's vote for one tag on one photo, given by its
 * path below the gallery root. Paths are resolved folder by folder with exact
 * (case- and accent-sensitive) name matching; nothing is ever matched on the
 * file name alone. A run only writes when every row resolves, and then only
 * the votes that aren't there yet — existing votes keep their date. Each
 * written run gets a batch ID whose receipt lists exactly the rows it
 * inserted, so `undo-tag-import` can take back that run and nothing else.
 */
final class Tag_Import_CLI {

	/**
	 * Option name prefix for the receipts of applied imports.
	 */
	private const RECEIPT_PREFIX = 'avpvh_tag_import_';

	/**
	 * Folder path → folder ID, for the folders resolved so far.
	 *
	 * @var array<string, string>
	 */
	private $folders = array();

	/**
	 * Folder ID → name → IDs, of the subfolders and images listed so far.
	 *
	 * @var array<string, array{0: array<string, array<string>>, 1: array<string, array<string>>}>
	 */
	private $listings = array();

	/**
	 * Imports subject-tag votes from a CSV (columns source,user_id,tag_key,path).
	 *
	 * Without --apply this is a dry run: it resolves every row and reports what
	 * would be written, but writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The CSV, or - to read it from standard input.
	 *
	 * [--dry-run]
	 * : Only report (the default).
	 *
	 * [--apply]
	 * : Write the new votes. Refused while any row is unresolved.
	 *
	 * [--format=<format>]
	 * : Row report format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - none
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp avpvh-gallery import-tags selections.csv --dry-run
	 *     wp avpvh-gallery import-tags selections.csv --apply
	 *
	 * @subcommand import-tags
	 *
	 * @param array<int, string>    $args       File.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function import_tags( $args, $assoc_args ) {
		$apply = isset( $assoc_args['apply'] );

		if ( $apply && isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::error( 'Pass either --dry-run or --apply, not both.' );
		}

		$root_id = self::gallery_root_id();

		if ( '' === $root_id ) {
			WP_CLI::error( 'No gallery root configured.' );
		}

		$rows  = self::read_manifest( $args[0] );
		$rows  = $this->resolve_rows( $rows, $root_id );
		$votes = self::plan_votes( $rows );

		self::report( $rows, $votes, $assoc_args['format'] ?? 'table' );

		$errors = count( array_filter( array_column( $rows, 'status' ), array( self::class, 'is_error' ) ) )
			+ count( array_filter( array_column( $votes, 'status' ), array( self::class, 'is_error' ) ) );

		if ( 0 < $errors ) {
			WP_CLI::error( sprintf( '%d errors; nothing written.', $errors ) );
		}

		if ( ! $apply ) {
			WP_CLI::success( 'Dry run; nothing written.' );

			return;
		}

		self::apply( $votes );
	}

	/**
	 * Takes back the votes one import-tags run added, and nothing else.
	 *
	 * Votes that have since been removed are skipped.
	 *
	 * ## OPTIONS
	 *
	 * [<batch>]
	 * : The batch ID that import-tags printed. Omit to list the batches.
	 *
	 * [--dry-run]
	 * : Only report what would be removed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp avpvh-gallery undo-tag-import
	 *     wp avpvh-gallery undo-tag-import 20261004-120000-ab12 --dry-run
	 *
	 * @subcommand undo-tag-import
	 *
	 * @param array<int, string>    $args       Batch ID.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function undo_tag_import( $args, $assoc_args ) {
		if ( ! isset( $args[0] ) ) {
			self::list_batches();

			return;
		}

		$receipt = get_option( self::RECEIPT_PREFIX . $args[0] );

		if ( ! is_array( $receipt ) ) {
			WP_CLI::error( 'No receipt for batch ' . $args[0] . '.' );
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( sprintf( 'Dry run; would remove up to %d votes.', count( $receipt['rows'] ) ) );

			return;
		}

		self::undo( $args[0], $receipt['rows'] );
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
	 * Reads and checks the manifest CSV.
	 *
	 * @param string $file Path, or - for standard input.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function read_manifest( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI input file.
		$handle = fopen( '-' === $file ? 'php://stdin' : $file, 'rb' );

		if ( false === $handle ) {
			WP_CLI::error( 'Cannot read ' . $file . '.' );
		}

		$columns = array( 'source', 'user_id', 'tag_key', 'path' );
		$header  = fgetcsv( $handle, null, ',', '"', '' );

		if ( false !== $header ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		}

		if ( $columns !== $header ) {
			WP_CLI::error( 'The header must be: ' . implode( ',', $columns ) );
		}

		$rows = array();
		$line = 1;

		while ( false !== ( $values = fgetcsv( $handle, null, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;

			if ( array( null ) === $values ) {
				continue;
			}

			$row           = count( $values ) === count( $columns )
				? array_combine( $columns, array_map( 'trim', $values ) )
				: array_fill_keys( $columns, '' );
			$row['line']   = (string) $line;
			$row['status'] = count( $values ) === count( $columns ) ? '' : 'error: wrong number of columns';
			$rows[]        = $row;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI input file.
		fclose( $handle );

		return $rows;
	}

	/**
	 * Checks each row's user and tag and finds its photo's Drive ID.
	 *
	 * @param array<int, array<string, string>> $rows    Manifest rows.
	 * @param string                            $root_id Gallery root folder ID.
	 *
	 * @return array<int, array<string, string>> The rows with `image_id`, `tag_label` and `status` filled in.
	 */
	private function resolve_rows( array $rows, $root_id ) {
		foreach ( $rows as $index => $row ) {
			$row['image_id']  = '';
			$row['tag_label'] = '';

			if ( '' === $row['status'] ) {
				try {
					$row = $this->resolve_row( $row, $root_id );
				} catch ( Throwable $e ) {
					$row['status'] = 'error: ' . $e->getMessage();
				}
			}

			$rows[ $index ] = $row;
		}

		return $rows;
	}

	/**
	 * Checks one row's user and tag and finds its photo's Drive ID.
	 *
	 * @param array<string, string> $row     Manifest row.
	 * @param string                $root_id Gallery root folder ID.
	 *
	 * @return array<string, string>
	 *
	 * @throws RuntimeException The row doesn't resolve.
	 */
	private function resolve_row( array $row, $root_id ) {
		if ( 1 !== preg_match( '/^[1-9]\d*$/', $row['user_id'] ) || false === get_userdata( (int) $row['user_id'] ) ) {
			throw new RuntimeException( 'unknown user ' . esc_html( $row['user_id'] ) );
		}

		$tag = Subject_Tag_Tree::tag( $row['tag_key'] );

		if ( null === $tag ) {
			throw new RuntimeException( 'unknown or untaggable tag ' . esc_html( $row['tag_key'] ) );
		}

		$row['tag_label'] = (string) $tag['label'];
		$row['image_id']  = $this->resolve_path( $root_id, $row['path'] );
		$row['status']    = 'resolved';

		return $row;
	}

	/**
	 * Finds a photo's Drive ID by walking its path from the gallery root.
	 *
	 * @param string $root_id Gallery root folder ID.
	 * @param string $path    Path below the root, e.g. "01-Opgravingen/1990 Ename/PICT1346.JPG".
	 *
	 * @return string
	 *
	 * @throws RuntimeException The path doesn't lead to exactly one photo.
	 */
	private function resolve_path( $root_id, $path ) {
		$parts = explode( '/', $path );

		if ( count( $parts ) < 2 || in_array( '', $parts, true ) ) {
			throw new RuntimeException( 'invalid path' );
		}

		$file_name = (string) array_pop( $parts );
		$folder_id = $root_id;
		$walked    = '';

		foreach ( $parts as $part ) {
			$walked = '' === $walked ? $part : $walked . '/' . $part;

			if ( ! isset( $this->folders[ $walked ] ) ) {
				$this->folders[ $walked ] = self::only_match( $this->listing( $folder_id )[0], $part, 'folder ' . $walked );
			}

			$folder_id = $this->folders[ $walked ];
		}

		return self::only_match( $this->listing( $folder_id )[1], $file_name, 'photo' );
	}

	/**
	 * The one ID listed under exactly this name.
	 *
	 * @param array<string, array<string>> $by_name Name → IDs.
	 * @param string                       $name    The name to look for.
	 * @param string                       $what    What is being looked for, for the error.
	 *
	 * @return string
	 *
	 * @throws RuntimeException Not exactly one match.
	 */
	private static function only_match( array $by_name, $name, $what ) {
		$ids = $by_name[ self::normalize( $name ) ] ?? array();

		if ( 1 === count( $ids ) ) {
			return $ids[0];
		}

		if ( 1 < count( $ids ) ) {
			throw new RuntimeException( esc_html( sprintf( 'ambiguous %s (%d matches)', $what, count( $ids ) ) ) );
		}

		$near = array_filter(
			array_keys( $by_name ),
			static function ( $candidate ) use ( $name ) {
				return 0 === strcasecmp( remove_accents( $candidate ), remove_accents( $name ) );
			}
		);

		throw new RuntimeException( esc_html( $what . ' not found' . ( array() === $near ? '' : ' (differs only in case/accents from: ' . implode( ', ', $near ) . ')' ) ) );
	}

	/**
	 * Unicode-normalizes a name, so that an "é" typed one way matches one
	 * stored the other way. Case and accents still count.
	 *
	 * @param string $name A file or folder name.
	 *
	 * @return string
	 */
	private static function normalize( $name ) {
		$normalized = class_exists( 'Normalizer' ) ? \Normalizer::normalize( $name, \Normalizer::FORM_C ) : $name;

		return false === $normalized ? $name : $normalized;
	}

	/**
	 * A folder's subfolders and images, by name (cached).
	 *
	 * @param string $folder_id Drive folder ID.
	 *
	 * @return array{0: array<string, array<string>>, 1: array<string, array<string>>} Subfolders, then images.
	 */
	private function listing( $folder_id ) {
		if ( ! isset( $this->listings[ $folder_id ] ) ) {
			$fields = static function () {
				return new API_Fields( array( 'id', 'name', 'mimeType', 'shortcutDetails' => array( 'targetId', 'targetMimeType' ) ) );
			};
			$all    = static function () {
				return ( new Paging_Pagination_Helper() )->withValues( 0, 1000000 );
			};

			list( $directories, $images ) = API_Client::execute(
				array(
					API_Facade::list_directories( $folder_id, $fields(), $all(), 'name' ),
					API_Facade::list_images( $folder_id, $fields(), $all(), 'name' ),
				)
			);

			$this->listings[ $folder_id ] = array( self::by_name( $directories, true ), self::by_name( $images, false ) );
		}

		return $this->listings[ $folder_id ];
	}

	/**
	 * Groups listed files by (normalized) name.
	 *
	 * @param array<array<string, mixed>> $files       Listed files.
	 * @param bool                        $is_folders  Whether these are folders (shortcuts then lead to their target).
	 *
	 * @return array<string, array<string>>
	 */
	private static function by_name( array $files, $is_folders ) {
		$by_name = array();

		foreach ( $files as $file ) {
			$id = $is_folders && isset( $file['shortcutDetails']['targetId'] )
				? (string) $file['shortcutDetails']['targetId']
				: (string) $file['id'];

			$by_name[ self::normalize( (string) $file['name'] ) ][] = $id;
		}

		return $by_name;
	}

	/**
	 * Turns resolved rows into the votes to write.
	 *
	 * Repeats of a vote within one person's selection count once, and like
	 * tagging in the gallery, a vote for a tag in a taggable group is also a
	 * vote for that group. Votes already in the database are marked as such.
	 *
	 * @param array<int, array<string, string>> $rows Resolved rows (marked as duplicates in place).
	 *
	 * @return array<string, array<string, string>> Vote key → vote, with `status` new/exists/error.
	 */
	private static function plan_votes( array &$rows ) {
		$votes = array();

		foreach ( $rows as $index => $row ) {
			if ( 'resolved' !== $row['status'] ) {
				continue;
			}

			$group = Subject_Tag_Tree::group_key( $row['tag_key'] );

			foreach ( array_filter( array( $row['tag_key'], $group ) ) as $tag_key ) {
				$key = $row['image_id'] . '|' . $tag_key . '|' . $row['user_id'];

				if ( $tag_key === $row['tag_key'] && isset( $votes[ $key ] ) && '' !== $votes[ $key ]['line'] ) {
					$rows[ $index ]['status'] = 'duplicate of line ' . $votes[ $key ]['line'];
					continue;
				}

				$votes[ $key ] = array(
					'image_id' => $row['image_id'],
					'line'     => $tag_key === $row['tag_key'] ? $row['line'] : ( $votes[ $key ]['line'] ?? '' ),
					'path'     => $row['path'],
					'source'   => $row['source'],
					'tag_key'  => $tag_key,
					'user_id'  => $row['user_id'],
				);
			}
		}

		return self::mark_existing( $votes, $rows );
	}

	/**
	 * Marks votes that are already in the database, and flags votes that would
	 * break a one-per-photo group.
	 *
	 * @param array<string, array<string, string>> $votes Planned votes.
	 * @param array<int, array<string, string>>    $rows  Rows (errors are marked in place).
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function mark_existing( array $votes, array &$rows ) {
		$existing = self::existing_votes( array_unique( array_column( $votes, 'image_id' ) ) );
		$planned  = array_map(
			static function ( $vote ) {
				return $vote['image_id'] . '|' . $vote['tag_key'] . '|' . $vote['user_id'];
			},
			$votes
		);
		$all      = array_unique( array_merge( array_keys( $existing ), $planned ) );

		foreach ( $votes as $key => $vote ) {
			$votes[ $key ]['status']    = isset( $existing[ $key ] ) ? 'exists' : 'new';
			$votes[ $key ]['tag_label'] = (string) ( Subject_Tag_Tree::tag( $vote['tag_key'] )['label'] ?? '' );
			$conflict                   = self::single_conflict( $vote, $all );

			if ( null !== $conflict ) {
				$votes[ $key ]['status'] = 'error: one-per-photo group already has ' . $conflict;
				self::mark_row( $rows, $vote['line'], $votes[ $key ]['status'] );
			}
		}

		return $votes;
	}

	/**
	 * Another tag in the same one-per-photo group that this person has (or
	 * would get) on the same photo, if any.
	 *
	 * @param array<string, string> $vote A vote.
	 * @param array<string>         $all  Keys of all existing and planned votes.
	 *
	 * @return string|null The other tag's key.
	 */
	private static function single_conflict( array $vote, array $all ) {
		if ( ! Subject_Tag_Tree::single( $vote['tag_key'] ) ) {
			return null;
		}

		foreach ( Subject_Tag_Tree::siblings( $vote['tag_key'] ) as $sibling ) {
			if ( $sibling !== $vote['tag_key'] && in_array( $vote['image_id'] . '|' . $sibling . '|' . $vote['user_id'], $all, true ) ) {
				return $sibling;
			}
		}

		return null;
	}

	/**
	 * Sets the status of the manifest row on a given line.
	 *
	 * @param array<int, array<string, string>> $rows   Rows.
	 * @param string                            $line   Line number.
	 * @param string                            $status New status.
	 *
	 * @return void
	 */
	private static function mark_row( array &$rows, $line, $status ) {
		foreach ( $rows as $index => $row ) {
			if ( $row['line'] === $line ) {
				$rows[ $index ]['status'] = $status;
			}
		}
	}

	/**
	 * The subject votes already on these photos.
	 *
	 * @param array<string> $image_ids Drive file IDs.
	 *
	 * @return array<string, true> "image|tag|user" → true.
	 */
	private static function existing_votes( array $image_ids ) {
		global $wpdb;
		$existing = array();

		foreach ( array_chunk( $image_ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom plugin table.
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT image_id, tag_key, created_by FROM {$wpdb->prefix}agallery_photo_tags WHERE category = 'subject' AND image_id IN ({$placeholders})", $chunk ) );

			foreach ( $found as $vote ) {
				$existing[ $vote->image_id . '|' . $vote->tag_key . '|' . $vote->created_by ] = true;
			}
		}

		return $existing;
	}

	/**
	 * Whether a row/vote status is an error.
	 *
	 * @param string $status Status.
	 *
	 * @return bool
	 */
	private static function is_error( $status ) {
		return 0 === strpos( $status, 'error' );
	}

	/**
	 * Prints the per-row report and the totals.
	 *
	 * @param array<int, array<string, string>>    $rows   Rows.
	 * @param array<string, array<string, string>> $votes  Planned votes.
	 * @param string                               $format Row report format.
	 *
	 * @return void
	 */
	private static function report( array $rows, array $votes, $format ) {
		if ( 'none' !== $format ) {
			\WP_CLI\Utils\format_items( $format, $rows, array( 'line', 'source', 'user_id', 'tag_key', 'tag_label', 'path', 'image_id', 'status' ) );
		}

		$totals = array();

		foreach ( $votes as $vote ) {
			$key = $vote['source'] . '|' . $vote['tag_label'] . ' (' . $vote['tag_key'] . ')';

			$totals[ $key ] ??= array(
				'source' => $vote['source'],
				'tag'    => $vote['tag_label'] . ' (' . $vote['tag_key'] . ')',
				'new'    => 0,
				'exists' => 0,
				'error'  => 0,
			);
			++$totals[ $key ][ self::is_error( $vote['status'] ) ? 'error' : $vote['status'] ];
		}

		ksort( $totals );
		WP_CLI::log( '' );
		\WP_CLI\Utils\format_items( 'table', array_values( $totals ), array( 'source', 'tag', 'new', 'exists', 'error' ) );

		$statuses = array_count_values(
			array_map(
				static function ( $status ) {
					return preg_replace( '/^(error|duplicate).*/', '$1', $status );
				},
				array_column( $rows, 'status' )
			)
		);
		ksort( $statuses );
		WP_CLI::log( sprintf( '%d rows: %s.', count( $rows ), http_build_query( $statuses, '', ', ' ) ) );
		WP_CLI::log( sprintf( '%d votes, %d new, %d already there.', count( $votes ), count( wp_list_filter( $votes, array( 'status' => 'new' ) ) ), count( wp_list_filter( $votes, array( 'status' => 'exists' ) ) ) ) );
	}

	/**
	 * Writes the new votes in one transaction, logs them, and stores the receipt.
	 *
	 * @param array<string, array<string, string>> $votes Planned votes.
	 *
	 * @return void
	 */
	private static function apply( array $votes ) {
		global $wpdb;
		$batch   = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false );
		$receipt = array();
		$table   = $wpdb->prefix . 'agallery_photo_tags';

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			foreach ( wp_list_filter( $votes, array( 'status' => 'new' ) ) as $vote ) {
				$receipt[] = self::insert_vote( $table, $vote );
			}

			add_option( self::RECEIPT_PREFIX . $batch, array( 'created_at' => current_time( 'mysql' ), 'rows' => $receipt ), '', false );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_set_current_user( 0 );
			WP_CLI::error( 'Rolled back, nothing written: ' . $e->getMessage() );
		}

		wp_set_current_user( 0 );
		WP_CLI::success( sprintf( '%d new votes written. Batch: %s (undo: wp avpvh-gallery undo-tag-import %s)', count( $receipt ), $batch, $batch ) );
	}

	/**
	 * Inserts one vote and logs it as added by its voter.
	 *
	 * @param string                $table Tags table.
	 * @param array<string, string> $vote  Vote.
	 *
	 * @return array<string, int|string> Receipt row.
	 *
	 * @throws RuntimeException The insert failed.
	 */
	private static function insert_vote( $table, array $vote ) {
		global $wpdb;
		$user_id = (int) $vote['user_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table.
		$inserted = $wpdb->insert(
			$table,
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
	 * Removes a batch's votes that are still there, logs it, and drops the receipt.
	 *
	 * @param string                           $batch Batch ID.
	 * @param array<array<string, int|string>> $rows  Receipt rows.
	 *
	 * @return void
	 */
	private static function undo( $batch, array $rows ) {
		global $wpdb;
		$removed = 0;

		foreach ( $rows as $row ) {
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

			if ( 1 === $deleted ) {
				++$removed;
				wp_set_current_user( (int) $row['user_id'] );
				$tag = Subject_Tag_Tree::tag( (string) $row['tag_key'] );
				Tag_Log::record( (string) $row['image_id'], 'subject', (string) $row['tag_key'], null === $tag ? '' : (string) $tag['label'], 'remove' );
			}
		}

		wp_set_current_user( 0 );
		delete_option( self::RECEIPT_PREFIX . $batch );
		WP_CLI::success( sprintf( '%d of %d votes removed (the rest were already gone).', $removed, count( $rows ) ) );
	}

	/**
	 * Lists the batches that can be undone.
	 *
	 * @return void
	 */
	private static function list_batches() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names   = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like( self::RECEIPT_PREFIX ) . '%' ) );
		$batches = array();

		foreach ( $names as $name ) {
			$receipt   = get_option( $name );
			$batches[] = array(
				'batch'      => substr( $name, strlen( self::RECEIPT_PREFIX ) ),
				'created_at' => $receipt['created_at'] ?? '',
				'votes'      => count( $receipt['rows'] ?? array() ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $batches, array( 'batch', 'created_at', 'votes' ) );
	}
}
