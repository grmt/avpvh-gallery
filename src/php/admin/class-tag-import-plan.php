<?php
/**
 * Contains the Tag_Import_Plan class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Frontend\Subject_Tag_Tree;
use RuntimeException;
use Throwable;
use WP_CLI;

/**
 * What an import-tags run would write (see Tag_Import_CLI): the manifest's
 * rows checked (user, tag, the photo's Drive ID via Drive_Path_Resolver) and
 * turned into votes. Repeats within one person's selection count once; like
 * tagging in the gallery, a vote for a tag in a taggable group is also a vote
 * for that group; votes already there are marked, and votes that would break
 * a one-per-photo group are errors.
 */
final class Tag_Import_Plan {

	/**
	 * The manifest's columns.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const COLUMNS = array( 'source', 'user_id', 'tag_key', 'path' );

	/**
	 * Reads and checks the manifest CSV.
	 *
	 * @param string $file Path, or - for standard input.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function read_manifest( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI input file.
		$handle = fopen( '-' === $file ? 'php://stdin' : $file, 'rb' );

		if ( false === $handle ) {
			WP_CLI::error( 'Cannot read ' . $file . '.' );
		}

		$header = fgetcsv( $handle, 0, ',', '"', '' );

		if ( false === $header || self::COLUMNS !== self::without_bom( $header ) ) {
			WP_CLI::error( 'The header must be: ' . implode( ',', self::COLUMNS ) );
		}

		$rows   = array();
		$line   = 1;
		$values = fgetcsv( $handle, 0, ',', '"', '' );

		while ( false !== $values ) {
			++$line;

			if ( array( null ) !== $values ) {
				$rows[] = self::row( $values, $line );
			}

			$values = fgetcsv( $handle, 0, ',', '"', '' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI input file.
		fclose( $handle );

		return $rows;
	}

	/**
	 * Checks each row's user and tag and finds its photo's Drive ID; adds
	 * image_id, tag_label and status ("resolved" or "error: …").
	 *
	 * @param array<int, array<string, string>> $rows    Manifest rows.
	 * @param string                            $root_id Gallery root folder ID.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function resolve( array $rows, $root_id ) {
		$paths = new Drive_Path_Resolver( $root_id );

		foreach ( $rows as $index => $row ) {
			$row['image_id']  = '';
			$row['tag_label'] = '';

			if ( '' === $row['status'] ) {
				try {
					$row = self::resolve_row( $row, $paths );
				} catch ( Throwable $e ) {
					$row['status'] = 'error: ' . $e->getMessage();
				}
			}

			$rows[ $index ] = $row;
		}

		return $rows;
	}

	/**
	 * The votes to write, and the rows with duplicates and conflicts marked.
	 *
	 * @param array<int, array<string, string>> $rows Resolved rows.
	 *
	 * @return array{0: array<int, array<string, string>>, 1: array<string, array<string, string>>} Rows, then
	 *         votes (key "image|tag|user" → vote with status new/exists/error).
	 */
	public static function votes( array $rows ) {
		$votes = array();

		foreach ( $rows as $index => $row ) {
			if ( 'resolved' !== $row['status'] ) {
				continue;
			}

			$first = $votes[ self::key( $row['image_id'], $row['tag_key'], $row['user_id'] ) ]['line'] ?? '';

			if ( '' !== $first ) {
				$rows[ $index ]['status'] = 'duplicate of line ' . $first;

				continue;
			}

			$votes = self::with_row_votes( $votes, $row );
		}

		return self::checked( $rows, $votes );
	}

	/**
	 * Whether a row or vote status is an error.
	 *
	 * @param string $status Status.
	 *
	 * @return bool
	 */
	public static function is_error( $status ) {
		return 0 === strpos( $status, 'error' );
	}

	/**
	 * The header without a UTF-8 byte order mark.
	 *
	 * @param array<int, string|null> $header CSV header cells.
	 *
	 * @return array<int, string|null>
	 */
	private static function without_bom( array $header ) {
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );

		return $header;
	}

	/**
	 * One manifest row with its line number and status.
	 *
	 * @param array<int, string|null> $values CSV cells.
	 * @param int                     $line   Line number.
	 *
	 * @return array<string, string>
	 */
	private static function row( array $values, $line ) {
		$complete      = count( $values ) === count( self::COLUMNS );
		$row           = $complete
			? array_combine( self::COLUMNS, array_map( 'trim', array_map( 'strval', $values ) ) )
			: array_fill_keys( self::COLUMNS, '' );
		$row['line']   = (string) $line;
		$row['status'] = $complete ? '' : 'error: wrong number of columns';

		return $row;
	}

	/**
	 * Checks one row's user and tag and finds its photo's Drive ID.
	 *
	 * @param array<string, string> $row   Manifest row.
	 * @param Drive_Path_Resolver   $paths Path resolver.
	 *
	 * @return array<string, string>
	 *
	 * @throws RuntimeException The row doesn't resolve.
	 */
	private static function resolve_row( array $row, Drive_Path_Resolver $paths ) {
		if ( 1 !== preg_match( '/^[1-9]\d*$/', $row['user_id'] ) || false === get_userdata( (int) $row['user_id'] ) ) {
			throw new RuntimeException( 'unknown user ' . esc_html( $row['user_id'] ) );
		}

		$tag = Subject_Tag_Tree::tag( $row['tag_key'] );

		if ( null === $tag ) {
			throw new RuntimeException( 'unknown or untaggable tag ' . esc_html( $row['tag_key'] ) );
		}

		$row['tag_label'] = (string) $tag['label'];
		$row['image_id']  = $paths->photo_id( $row['path'] );
		$row['status']    = 'resolved';

		return $row;
	}

	/**
	 * The votes with a row's added: for its tag, and for the tag's taggable
	 * group (which keeps the line of the row voting for it directly, if any).
	 *
	 * @param array<string, array<string, string>> $votes Votes so far.
	 * @param array<string, string>                $row   A resolved row.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function with_row_votes( array $votes, array $row ) {
		$group = Subject_Tag_Tree::group_key( $row['tag_key'] );

		foreach ( array_filter( array( $row['tag_key'], $group ) ) as $tag_key ) {
			$key           = self::key( $row['image_id'], $tag_key, $row['user_id'] );
			$votes[ $key ] = array(
				'image_id' => $row['image_id'],
				'line'     => $tag_key === $row['tag_key'] ? $row['line'] : ( $votes[ $key ]['line'] ?? '' ),
				'path'     => $row['path'],
				'source'   => $row['source'],
				'tag_key'  => $tag_key,
				'user_id'  => $row['user_id'],
			);
		}

		return $votes;
	}

	/**
	 * Marks votes already in the database, and votes (and their rows) that
	 * would break a one-per-photo group.
	 *
	 * @param array<int, array<string, string>>    $rows  Rows.
	 * @param array<string, array<string, string>> $votes Planned votes.
	 *
	 * @return array{0: array<int, array<string, string>>, 1: array<string, array<string, string>>}
	 */
	private static function checked( array $rows, array $votes ) {
		$existing = Tag_Import_Votes::existing( array_unique( array_column( $votes, 'image_id' ) ) );
		$all      = array_unique( array_merge( array_keys( $existing ), array_keys( $votes ) ) );
		$errors   = array();

		foreach ( $votes as $key => $vote ) {
			$conflict                   = self::single_conflict( $vote, $all );
			$votes[ $key ]['tag_label'] = (string) ( Subject_Tag_Tree::tag( $vote['tag_key'] )['label'] ?? '' );
			$votes[ $key ]['status']    = null === $conflict
				? ( isset( $existing[ $key ] ) ? 'exists' : 'new' )
				: 'error: one-per-photo group already has ' . $conflict;

			if ( null !== $conflict ) {
				$errors[ $vote['line'] ] = $votes[ $key ]['status'];
			}
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['status'] = $errors[ $row['line'] ] ?? $row['status'];
		}

		return array( $rows, $votes );
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
			$key = self::key( $vote['image_id'], $sibling, $vote['user_id'] );

			if ( $sibling !== $vote['tag_key'] && in_array( $key, $all, true ) ) {
				return $sibling;
			}
		}

		return null;
	}

	/**
	 * A vote's key.
	 *
	 * @param string $image_id Drive file ID.
	 * @param string $tag_key  Tag key.
	 * @param string $user_id  Voter.
	 *
	 * @return string
	 */
	private static function key( $image_id, $tag_key, $user_id ) {
		return $image_id . '|' . $tag_key . '|' . $user_id;
	}
}
