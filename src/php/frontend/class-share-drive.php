<?php
/**
 * Contains the Share_Drive class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Options;
use Avpvh\Vendor\Google\Client;
use Avpvh\Vendor\Google\Service\Drive;
use Avpvh\Vendor\Google\Service\Drive\DriveFile;
use Avpvh\Vendor\Google\Service\Drive\Permission;
use RuntimeException;
use Throwable;

/**
 * Drive calls that write, made as the Google service account set in the
 * plugin's settings rather than with the gallery's own (read-only) link:
 * shared photo selections (see Photo_Shares) and moving files between the
 * gallery's folders (see Move_Files_CLI). That account must be able to read
 * the gallery's photos and write in the folders concerned — typically as
 * content manager of the shared drive they are in.
 */
final class Share_Drive {

	/**
	 * Requests per batch (Google's limit is 100).
	 */
	private const BATCH_SIZE = 100;

	/**
	 * The Drive client, once made.
	 *
	 * @var Drive|null
	 */
	private static $drive = null;

	/**
	 * Whether a service account and selections folder are set.
	 *
	 * @return bool
	 */
	public static function configured() {
		return self::has_account() && '' !== self::parent_folder();
	}

	/**
	 * Whether a service account is set (enough for moving files).
	 *
	 * @return bool
	 */
	public static function has_account() {
		return array() !== Options::$share_service_account->credentials();
	}

	/**
	 * Creates a folder in the selections folder.
	 *
	 * @param string $name The folder's name.
	 *
	 * @return string The new folder's ID.
	 */
	public static function create_folder( $name ) {
		return self::create_folder_in( $name, self::parent_folder() );
	}

	/**
	 * Copies files into a folder, numbered in the given order
	 * ("001 PICT1346.JPG"), a batch at a time.
	 *
	 * @param array<string> $ids       Drive file IDs.
	 * @param string        $folder_id Target folder.
	 *
	 * @return void
	 *
	 * @throws RuntimeException A file couldn't be read or copied.
	 */
	public static function copy_into( array $ids, $folder_id ) {
		$ids   = array_values( $ids );
		$names = self::names( $ids );

		foreach ( array_chunk( array_keys( $ids ), self::BATCH_SIZE ) as $indexes ) {
			self::batch(
				$indexes,
				static function ( $index ) use ( $ids, $names, $folder_id ) {
					$file_id = $ids[ $index ];

					return self::drive()->files->copy(
						$file_id,
						new DriveFile(
							array(
								'name'    => sprintf( '%03d %s', $index + 1, $names[ $file_id ] ?? $file_id ),
								'parents' => array( $folder_id ),
							)
						),
						array(
							'fields'            => 'id',
							'supportsAllDrives' => true,
						)
					);
				}
			);
		}
	}

	/**
	 * The ID of the folder at a path below another folder, such as
	 * 03-Weekenden/2024 Meerveld, matching names exactly. When a part doesn't
	 * exist it is made, or, without create, an empty string is returned.
	 *
	 * @param string $parent_id The folder the path starts in.
	 * @param string $path      Folder names separated by "/".
	 * @param bool   $create    Whether to make missing folders.
	 *
	 * @return string
	 *
	 * @throws RuntimeException A name is ambiguous (more than one folder).
	 */
	public static function folder_at( $parent_id, $path, $create ) {
		foreach ( explode( '/', $path ) as $name ) {
			$found = self::drive()->files->listFiles(
				array(
					'fields'                    => 'files(id, name)',
					'includeItemsFromAllDrives' => true,
					'q'                         => self::folder_query( $parent_id, $name ),
					'supportsAllDrives'         => true,
				)
			)->getFiles();
			$exact = array_values(
				array_filter(
					$found,
					static function ( $folder ) use ( $name ) {
						return $folder->getName() === $name;
					}
				)
			);

			if ( 1 < count( $exact ) ) {
				throw new RuntimeException( esc_html( 'More than one folder named ' . $name ) );
			}

			if ( array() === $exact && ! $create ) {
				return '';
			}

			$parent_id = array() === $exact ? self::create_folder_in( $name, $parent_id ) : (string) $exact[0]->getId();
		}

		return $parent_id;
	}

	/**
	 * Each file's current parent folders.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, array<string>>
	 *
	 * @throws RuntimeException A file couldn't be read.
	 */
	public static function parents( array $ids ) {
		$parents = array();

		foreach ( array_chunk( array_values( $ids ), self::BATCH_SIZE ) as $chunk ) {
			$files = self::batch(
				$chunk,
				static function ( $file_id ) {
					return self::drive()->files->get(
						$file_id,
						array(
							'fields'            => 'id, parents',
							'supportsAllDrives' => true,
						)
					);
				}
			);

			foreach ( $files as $file ) {
				$parents[ (string) $file->getId() ] = (array) $file->getParents();
			}
		}

		return $parents;
	}

	/**
	 * Moves files from one folder to another, a batch at a time. Their IDs
	 * stay the same.
	 *
	 * @param array<array{file_id: string, from: string, to: string}> $moves The moves.
	 *
	 * @return void
	 *
	 * @throws RuntimeException A file couldn't be moved (the batch it was in may be partly done).
	 */
	public static function move( array $moves ) {
		foreach ( array_chunk( array_values( $moves ), self::BATCH_SIZE ) as $chunk ) {
			self::batch(
				$chunk,
				static function ( $move ) {
					return self::drive()->files->update(
						$move['file_id'],
						new DriveFile(),
						array(
							'addParents'        => $move['to'],
							'fields'            => 'id, parents',
							'removeParents'     => $move['from'],
							'supportsAllDrives' => true,
						)
					);
				}
			);
		}
	}

	/**
	 * Lets one Google account view a folder, without Google's own e-mail.
	 *
	 * @param string $folder_id The folder.
	 * @param string $email     The Google account's address.
	 *
	 * @return void
	 */
	public static function share_with( $folder_id, $email ) {
		self::drive()->permissions->create(
			$folder_id,
			new Permission(
				array(
					'emailAddress' => $email,
					'role'         => 'reader',
					'type'         => 'user',
				)
			),
			array(
				'sendNotificationEmail' => false,
				'supportsAllDrives'     => true,
			)
		);
	}

	/**
	 * Removes a folder with everything in it: deleted outright or, when the
	 * account may only move things to the bin (a shared drive's content
	 * managers), binned — which ends access just as well.
	 *
	 * @param string $folder_id The folder.
	 *
	 * @return void
	 */
	public static function remove( $folder_id ) {
		try {
			self::drive()->files->delete( $folder_id, array( 'supportsAllDrives' => true ) );
		} catch ( Throwable $e ) {
			// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- not allowed to delete: bin it instead.
			self::drive()->files->update(
				$folder_id,
				new DriveFile( array( 'trashed' => true ) ),
				array( 'supportsAllDrives' => true )
			);
		}
	}

	/**
	 * The files' names, media types, sizes (bytes) and thumbnail links, by ID.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, array{name: string, mime: string, size: int, thumb: string}>
	 *
	 * @throws RuntimeException A file couldn't be read.
	 */
	public static function details( array $ids ) {
		$details = array();

		foreach ( array_chunk( array_values( $ids ), self::BATCH_SIZE ) as $chunk ) {
			$files = self::batch(
				$chunk,
				static function ( $file_id ) {
					return self::drive()->files->get(
						$file_id,
						array(
							'fields'            => 'id, name, mimeType, size, thumbnailLink',
							'supportsAllDrives' => true,
						)
					);
				}
			);

			foreach ( $files as $file ) {
				$details[ (string) $file->getId() ] = array(
					'mime'  => (string) $file->getMimeType(),
					'name'  => (string) $file->getName(),
					'size'  => (int) $file->getSize(),
					'thumb' => (string) $file->getThumbnailLink(),
				);
			}
		}

		return $details;
	}

	/**
	 * The service account's Drive client. Requests made through it are
	 * deferred for batching (see batch()) except where executed directly.
	 * Also used by Share_Drive_Files.
	 *
	 * @return Drive
	 */
	public static function drive() {
		$drive = self::$drive;

		if ( null === $drive ) {
			$client = new Client();
			$client->setAuthConfig( Options::$share_service_account->credentials() );
			$client->addScope( Drive::DRIVE );
			$drive       = new Drive( $client );
			self::$drive = $drive;
		}

		return $drive;
	}

	/**
	 * The Drive query for the folders with a name in a folder.
	 *
	 * @param string $parent_id The folder to look in.
	 * @param string $name      The name.
	 *
	 * @return string
	 */
	private static function folder_query( $parent_id, $name ) {
		$quoted = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $name );

		return "'{$parent_id}' in parents and mimeType = 'application/vnd.google-apps.folder'"
			. " and trashed = false and name = '{$quoted}'";
	}

	/**
	 * Creates a folder in a given folder.
	 *
	 * @param string $name      The folder's name.
	 * @param string $parent_id The folder to make it in.
	 *
	 * @return string The new folder's ID.
	 */
	private static function create_folder_in( $name, $parent_id ) {
		$folder = self::drive()->files->create(
			new DriveFile(
				array(
					'mimeType' => 'application/vnd.google-apps.folder',
					'name'     => $name,
					'parents'  => array( $parent_id ),
				)
			),
			array(
				'fields'            => 'id',
				'supportsAllDrives' => true,
			)
		);

		return (string) $folder->getId();
	}

	/**
	 * The files' names, by ID.
	 *
	 * @param array<string> $ids Drive file IDs.
	 *
	 * @return array<string, string>
	 *
	 * @throws RuntimeException A file couldn't be read.
	 */
	private static function names( array $ids ) {
		return array_map(
			static function ( $file ) {
				return $file['name'];
			},
			self::details( $ids )
		);
	}

	/**
	 * Runs one request per item as a single batch: the requests are made
	 * while the client is in batch mode, so they're deferred, not sent.
	 *
	 * @param array<int, mixed> $items Whatever $make needs per request.
	 * @param callable          $make  Makes the request for an item.
	 *
	 * @return array<int, mixed> The responses, in order.
	 *
	 * @throws RuntimeException One of them failed.
	 */
	private static function batch( array $items, callable $make ) {
		$client   = self::drive()->getClient();
		$requests = array();
		$client->setUseBatch( true );

		try {
			$requests = array_map( $make, array_values( $items ) );
		} finally {
			$client->setUseBatch( false );
		}

		$batch = self::drive()->createBatch();

		foreach ( $requests as $index => $request ) {
			$batch->add( $request, 'r' . $index );
		}

		$results   = $batch->execute();
		$responses = array();

		foreach ( array_keys( $requests ) as $index ) {
			$response = $results[ 'response-r' . $index ] ?? null;

			if ( null === $response || $response instanceof Throwable ) {
				$message = null === $response ? 'No response from Drive' : $response->getMessage();

				throw new RuntimeException( esc_html( $message ) );
			}

			$responses[] = $response;
		}

		return $responses;
	}

	/**
	 * The selections folder's ID.
	 *
	 * @return string
	 */
	private static function parent_folder() {
		return trim( (string) Options::$share_folder->get() );
	}
}
