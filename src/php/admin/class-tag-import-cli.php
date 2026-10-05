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

use Avpvh\Options;
use Throwable;
use WP_CLI;
use function WP_CLI\Utils\format_items;

/**
 * WP-CLI commands that bulk-import a reviewed CSV of subject-tag votes, and
 * undo such an import.
 *
 * Every CSV row is one person's vote for one tag on one photo, given by its
 * path below the gallery root. Paths are resolved folder by folder with exact
 * (case- and accent-sensitive) name matching; nothing is ever matched on the
 * file name alone (see Tag_Import_Plan). A run only writes when every row
 * resolves, and then only the votes that aren't there yet — existing votes
 * keep their date. Each written run gets a batch ID whose receipt lists
 * exactly the rows it inserted, so `undo-tag-import` can take back that run
 * and nothing else (see Tag_Import_Votes).
 */
final class Tag_Import_CLI {

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
	 * @param array<int, string>    $args       File.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 *
	 * @phan-suppress PhanPluginPossiblyStaticPublicMethod -- WP-CLI calls commands on an instance.
	 */
	public function import_tags( $args, $assoc_args ) {
		$apply   = isset( $assoc_args['apply'] );
		$root_id = self::gallery_root_id();

		if ( $apply && isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::error( 'Pass either --dry-run or --apply, not both.' );
		}

		if ( '' === $root_id ) {
			WP_CLI::error( 'No gallery root configured.' );
		}

		$rows                 = Tag_Import_Plan::resolve( Tag_Import_Plan::read_manifest( $args[0] ), $root_id );
		list( $rows, $votes ) = Tag_Import_Plan::votes( $rows );

		self::report( $rows, $votes, $assoc_args['format'] ?? 'table' );

		$errors = self::errors( $rows ) + self::errors( $votes );

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
	 * @param array<int, string>    $args       Batch ID.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 *
	 * @phan-suppress PhanPluginPossiblyStaticPublicMethod -- WP-CLI calls commands on an instance.
	 */
	public function undo_tag_import( $args, $assoc_args ) {
		if ( ! isset( $args[0] ) ) {
			format_items( 'table', Tag_Import_Votes::batches(), array( 'batch', 'created_at', 'votes' ) );

			return;
		}

		$receipt = get_option( Tag_Import_Votes::RECEIPT_PREFIX . $args[0] );

		if ( ! is_array( $receipt ) ) {
			WP_CLI::error( 'No receipt for batch ' . $args[0] . '.' );
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( sprintf( 'Dry run; would remove up to %d votes.', count( $receipt['rows'] ) ) );

			return;
		}

		list( $removed, $total ) = Tag_Import_Votes::undo( $args[0] );
		WP_CLI::success( sprintf( '%d of %d votes removed (the rest were already gone).', $removed, $total ) );
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
	 * How many rows or votes have an error status.
	 *
	 * @param array<array<string, string>> $items Rows or votes.
	 *
	 * @return int
	 */
	private static function errors( array $items ) {
		return count( array_filter( array_column( $items, 'status' ), array( Tag_Import_Plan::class, 'is_error' ) ) );
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
			$columns = array( 'line', 'source', 'user_id', 'tag_key', 'tag_label', 'path', 'image_id', 'status' );
			format_items( $format, $rows, $columns );
		}

		WP_CLI::log( '' );
		format_items( 'table', self::totals( $votes ), array( 'source', 'tag', 'new', 'exists', 'error' ) );

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
		WP_CLI::log(
			sprintf(
				'%d votes, %d new, %d already there.',
				count( $votes ),
				count( wp_list_filter( $votes, array( 'status' => 'new' ) ) ),
				count( wp_list_filter( $votes, array( 'status' => 'exists' ) ) )
			)
		);
	}

	/**
	 * Per person and tag: how many votes are new, already there, or wrong.
	 *
	 * @param array<string, array<string, string>> $votes Planned votes.
	 *
	 * @return array<array<string, int|string>>
	 */
	private static function totals( array $votes ) {
		$totals = array();

		foreach ( $votes as $vote ) {
			$tag = $vote['tag_label'] . ' (' . $vote['tag_key'] . ')';
			$key = $vote['source'] . '|' . $tag;

			$totals[ $key ] ??= array(
				'error'  => 0,
				'exists' => 0,
				'new'    => 0,
				'source' => $vote['source'],
				'tag'    => $tag,
			);
			++$totals[ $key ][ Tag_Import_Plan::is_error( $vote['status'] ) ? 'error' : $vote['status'] ];
		}

		ksort( $totals );

		return array_values( $totals );
	}

	/**
	 * Writes the new votes and prints the batch ID to undo them with.
	 *
	 * @param array<string, array<string, string>> $votes Planned votes.
	 *
	 * @return void
	 */
	private static function apply( array $votes ) {
		try {
			list( $batch, $written ) = Tag_Import_Votes::write( $votes );
		} catch ( Throwable $e ) {
			WP_CLI::error( 'Rolled back, nothing written: ' . $e->getMessage() );
		}

		WP_CLI::success(
			sprintf(
				'%d new votes written. Batch: %s (undo: wp avpvh-gallery undo-tag-import %s)',
				$written,
				$batch,
				$batch
			)
		);
	}
}
