<?php
/**
 * Contains the Drive_Path_Resolver class.
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
use Normalizer;
use RuntimeException;

/**
 * Finds photos by their path below a folder ("01-Opgravingen/1990
 * Ename/PICT1346.JPG"), folder by folder, matching every name exactly (case
 * and accents count) — never on the file name alone. Listings are cached for
 * the resolver's lifetime. Used by Tag_Import_Plan.
 */
final class Drive_Path_Resolver {

	/**
	 * The folder paths start in.
	 *
	 * @var string
	 */
	private $root_id;

	/**
	 * Folder path → folder ID, for the folders resolved so far.
	 *
	 * @var array<string, string>
	 */
	private $folders = array();

	/**
	 * Folder ID → [subfolders, images], each name → IDs, listed so far.
	 *
	 * @var array<string, array{0: array<string, array<string>>, 1: array<string, array<string>>}>
	 */
	private $listings = array();

	/**
	 * A resolver for paths below a folder.
	 *
	 * @param string $root_id The folder paths start in.
	 */
	public function __construct( $root_id ) {
		$this->root_id = $root_id;
	}

	/**
	 * The Drive ID of the photo at a path.
	 *
	 * @param string $path Path below the root folder.
	 *
	 * @return string
	 *
	 * @throws RuntimeException The path doesn't lead to exactly one photo.
	 */
	public function photo_id( $path ) {
		$parts = explode( '/', $path );

		if ( count( $parts ) < 2 || in_array( '', $parts, true ) ) {
			throw new RuntimeException( 'invalid path' );
		}

		$file_name = (string) array_pop( $parts );

		return self::only_match( $this->listing( $this->folder_id( $parts ) )[1], $file_name, 'photo' );
	}

	/**
	 * The ID of the folder at a path (its names).
	 *
	 * @param array<string> $parts Folder names from the root down.
	 *
	 * @return string
	 *
	 * @throws RuntimeException A folder isn't there exactly once.
	 */
	private function folder_id( array $parts ) {
		$folder_id = $this->root_id;
		$walked    = '';

		foreach ( $parts as $part ) {
			$walked = '' === $walked ? $part : $walked . '/' . $part;

			if ( ! isset( $this->folders[ $walked ] ) ) {
				$folders                  = $this->listing( $folder_id )[0];
				$this->folders[ $walked ] = self::only_match( $folders, $part, 'folder ' . $walked );
			}

			$folder_id = $this->folders[ $walked ];
		}

		return $folder_id;
	}

	/**
	 * A folder's subfolders and images, by name (cached).
	 *
	 * @param string $folder_id Drive folder ID.
	 *
	 * @return array{0: array<string, array<string>>, 1: array<string, array<string>>} Subfolders, then images.
	 */
	private function listing( $folder_id ) {
		if ( isset( $this->listings[ $folder_id ] ) ) {
			return $this->listings[ $folder_id ];
		}

		$fields = static function () {
			return new API_Fields(
				array(
					'id',
					'name',
					'mimeType',
					'shortcutDetails' => array( 'targetId', 'targetMimeType' ),
				)
			);
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

		return $this->listings[ $folder_id ];
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
		$hint = array() === $near ? '' : ' (differs only in case/accents from: ' . implode( ', ', $near ) . ')';

		throw new RuntimeException( esc_html( $what . ' not found' . $hint ) );
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
		$normalized = class_exists( 'Normalizer' ) ? Normalizer::normalize( $name, Normalizer::FORM_C ) : $name;

		return false === $normalized ? $name : $normalized;
	}

	/**
	 * Groups listed files by (normalized) name.
	 *
	 * @param array<array<string, mixed>> $files      Listed files.
	 * @param bool                        $is_folders Whether these are folders (shortcuts then lead to their target).
	 *
	 * @return array<string, array<string>>
	 */
	private static function by_name( array $files, $is_folders ) {
		$by_name = array();

		foreach ( $files as $file ) {
			$file_id = $is_folders && isset( $file['shortcutDetails']['targetId'] )
				? (string) $file['shortcutDetails']['targetId']
				: (string) $file['id'];

			$by_name[ self::normalize( (string) $file['name'] ) ][] = $file_id;
		}

		return $by_name;
	}
}
