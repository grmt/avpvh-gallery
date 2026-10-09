<?php
/**
 * Contains the Share_Drive_Files class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Vendor\Google\Http\MediaFileUpload;
use Avpvh\Vendor\Google\Service\Drive\DriveFile;
use Avpvh\Vendor\Google\Service\Exception as Drive_Exception;
use Avpvh\Vendor\Psr\Http\Message\RequestInterface;
use Avpvh\Vendor\Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Single-file Drive transfers as the service account (see Share_Drive):
 * downloading a photo, uploading an edited one and copying one as is. Used
 * by Share_Image for shares with captions.
 */
final class Share_Drive_Files {

	/**
	 * Upload chunk size (a multiple of 256 KiB, as Drive requires).
	 */
	private const CHUNK = 8 * 1024 * 1024;

	/**
	 * Whether a folder is gone: in the bin, or deleted. False when Drive
	 * can't be asked (then nothing is concluded).
	 *
	 * @param string $folder_id Drive folder ID.
	 *
	 * @return bool
	 */
	public static function folder_gone( $folder_id ) {
		try {
			$folder = Share_Drive::drive()->files->get(
				$folder_id,
				array(
					'fields'            => 'trashed',
					'supportsAllDrives' => true,
				)
			);
		} catch ( Drive_Exception $e ) {
			return 404 === $e->getCode();
		}

		return true === $folder->getTrashed();
	}

	/**
	 * A file's contents.
	 *
	 * @param string $file_id Drive file ID.
	 *
	 * @return string
	 *
	 * @throws RuntimeException Drive didn't return the contents.
	 */
	public static function download( $file_id ) {
		$response = Share_Drive::drive()->files->get(
			$file_id,
			array(
				'alt'               => 'media',
				'supportsAllDrives' => true,
			)
		);

		if ( ! $response instanceof ResponseInterface ) {
			throw new RuntimeException( 'No contents for ' . esc_html( $file_id ) );
		}

		return (string) $response->getBody();
	}

	/**
	 * A large JPEG rendering Google made of an image (from its thumbnail
	 * link, asked for at the given size): for formats Imagick here can't
	 * read (HEIF) and for scans too big to edit in memory.
	 *
	 * @param string $thumbnail_link The file's thumbnailLink.
	 * @param int    $size           Longest side wanted, in pixels.
	 *
	 * @return string '' when there is none.
	 */
	public static function rendering( $thumbnail_link, $size ) {
		if ( '' === $thumbnail_link ) {
			return '';
		}

		$url = (string) preg_replace( '/=s\d+$/', '', $thumbnail_link ) . '=s' . $size;

		try {
			$response = Share_Drive::drive()->getClient()->authorize()->request( 'GET', $url );
		} catch ( Throwable $e ) {
			// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- no rendering: the photo is left out.
			return '';
		}

		return 200 === $response->getStatusCode() ? (string) $response->getBody() : '';
	}

	/**
	 * Uploads contents as a new file in a folder (resumable, a chunk at a
	 * time, so large photos are fine).
	 *
	 * @param string $folder_id Target folder.
	 * @param string $name      The file's name.
	 * @param string $mime      Its media type.
	 * @param string $bytes     Its contents.
	 *
	 * @return void
	 */
	public static function upload( $folder_id, $name, $mime, $bytes ) {
		self::send(
			$folder_id,
			array( $name, $mime, strlen( $bytes ) ),
			static function ( $offset ) use ( $bytes ) {
				return substr( $bytes, $offset, self::CHUNK );
			}
		);
	}

	/**
	 * Uploads a file from disk as a new file in a folder, read a chunk at
	 * a time (for the share's PDF, which can be hundreds of megabytes).
	 *
	 * @param string $folder_id Target folder.
	 * @param string $name      The file's name.
	 * @param string $mime      Its media type.
	 * @param string $path      The file on disk.
	 *
	 * @return void
	 *
	 * @throws RuntimeException The file can't be read.
	 */
	public static function upload_file( $folder_id, $name, $mime, $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read in chunks: too big for memory.
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new RuntimeException( 'Could not read ' . esc_html( $name ) );
		}

		try {
			self::send(
				$folder_id,
				array( $name, $mime, (int) filesize( $path ) ),
				static function ( $offset ) use ( $handle ) {
					fseek( $handle, $offset );

					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
					return (string) fread( $handle, self::CHUNK );
				}
			);
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			fclose( $handle );
		}
	}

	/**
	 * Copies a file into a folder under a new name.
	 *
	 * @param string $file_id   Drive file ID.
	 * @param string $name      The copy's name.
	 * @param string $folder_id Target folder.
	 *
	 * @return void
	 */
	public static function copy( $file_id, $name, $folder_id ) {
		Share_Drive::drive()->files->copy(
			$file_id,
			new DriveFile(
				array(
					'name'    => $name,
					'parents' => array( $folder_id ),
				)
			),
			array(
				'fields'            => 'id',
				'supportsAllDrives' => true,
			)
		);
	}

	/**
	 * A resumable upload, a chunk at a time.
	 *
	 * @param string                              $folder_id Target folder.
	 * @param array{0: string, 1: string, 2: int} $file Name, media type and size.
	 * @param callable(int): string               $chunk     The chunk at an offset.
	 *
	 * @return void
	 *
	 * @throws RuntimeException The upload couldn't be started.
	 */
	private static function send( $folder_id, array $file, callable $chunk ) {
		list( $name, $mime, $size ) = $file;
		$drive                      = Share_Drive::drive();
		$client                     = $drive->getClient();
		$request                    = null;
		$client->setDefer( true );

		try {
			$request = $drive->files->create(
				new DriveFile(
					array(
						'name'    => $name,
						'parents' => array( $folder_id ),
					)
				),
				array(
					'fields'            => 'id',
					'supportsAllDrives' => true,
				)
			);
		} finally {
			$client->setDefer( false );
		}

		if ( ! $request instanceof RequestInterface ) {
			throw new RuntimeException( 'Could not start the upload of ' . esc_html( $name ) );
		}

		$upload = new MediaFileUpload( $client, $request, $mime, '', true, self::CHUNK );
		$upload->setFileSize( $size );
		$done = false;

		for ( $offset = 0; false === $done; $offset += self::CHUNK ) {
			$done = $upload->nextChunk( $chunk( $offset ) );
		}
	}
}
