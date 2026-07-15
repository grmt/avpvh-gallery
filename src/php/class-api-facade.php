<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- single facade class per CLAUDE.md; the EXIF inspector's search/get-file methods were added here to keep all Drive API access behind one facade.
/**
 * Contains the API_Facade class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

use Avpvh\API_Client;
use Avpvh\Exceptions\Directory_Not_Found_Exception;
use Avpvh\Exceptions\Drive_Not_Found_Exception;
use Avpvh\Exceptions\File_Not_Found_Exception;
use Avpvh\Exceptions\Internal_Exception;
use Avpvh\Exceptions\Not_Found_Exception;
use Avpvh\Exceptions\Plugin_Not_Authorized_Exception;
use Avpvh\Exceptions\Unsupported_Value_Exception;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Pagination_Helper;
use Avpvh\Frontend\Single_Page_Pagination_Helper;
use Avpvh\Vendor\GuzzleHttp\Promise\PromiseInterface;
use Avpvh\Vendor\GuzzleHttp\Promise\RejectedPromise;

// phpcs:disable SlevomatCodingStandard.Classes.ClassLength.ClassTooLong -- single facade class per CLAUDE.md; the EXIF inspector's search/get-file methods were added here to keep all Drive API access behind one facade.
/**
 * API call facade
 */
final class API_Facade {

	/**
	 * Searches for a directory ID by its parent and its name
	 *
	 * @param string $parent_id The ID of the directory to search in.
	 * @param string $name The name of the directory.
	 *
	 * @return PromiseInterface A promise resolving to the ID of the directory.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	public static function get_directory_id( $parent_id, $name ) {
		$params = array(
			'fields'                    => 'files(id, name, mimeType, shortcutDetails(targetId))',
			'includeItemsFromAllDrives' => true,
			'pageSize'                  => 2,
			// phpcs:ignore SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
			'q'                         => '"' .
				$parent_id .
				'" in parents and name = "' .
				str_replace( '"', '\\"', $name ) .
				'" and (mimeType = "application/vnd.google-apps.folder" or ' .
				'(mimeType = "application/vnd.google-apps.shortcut" and ' .
				'shortcutDetails.targetMimeType = "application/vnd.google-apps.folder")) and trashed = false',
			'supportsAllDrives'         => true,
		);

		/**
		 * `$transform` transforms the raw Google API response into the structured response this function returns.
		 *
		 * @throws Directory_Not_Found_Exception The directory wasn't found.
		 */
		return API_Client::async_request(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			API_Client::get_drive_client()->files->listFiles( $params ),
			/**
			 * Directory resolver callback.
			 *
			 * @throws Directory_Not_Found_Exception When directory not found.
			 */
			static function ( $response ) use ( $name ) {
				if ( 1 !== count( $response->getFiles() ) ) {
					throw new Directory_Not_Found_Exception( esc_html( $name ) );
				}

				$file = $response->getFiles()[0];

				return 'application/vnd.google-apps.shortcut' === $file->getMimeType()
					? $file->getShortcutDetails()->getTargetId()
					: $file->getId();
			}
		);
	}

	/**
	 * Searches for a drive name by its ID
	 *
	 * @param string $id The of the drive.
	 *
	 * @return PromiseInterface A promise resolving to the name of the drive.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	public static function get_drive_name( $id ) {
		return API_Client::async_request(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			API_Client::get_drive_client()->drives->get(
				$id,
				array(
					'fields' => 'name',
				)
			),
			static function ( $response ) {
				return $response->getName();
			},
			static function ( $exception ) {
				if ( $exception instanceof Not_Found_Exception ) {
					$exception = new Drive_Not_Found_Exception();
				}

				return new RejectedPromise( $exception );
			}
		);
	}

	/**
	 * Searches for a file/directory name by its ID
	 *
	 * @param string $id The ID of the file/directory.
	 *
	 * @return PromiseInterface A promise resolving to the name of the directory.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	public static function get_file_name( $id ) {
		return API_Client::async_request(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			API_Client::get_drive_client()->files->get(
				$id,
				array(
					'fields'            => 'name, trashed',
					'supportsAllDrives' => true,
				)
			),
			/**
			 * `$transform` transforms the raw Google API response into the structured response this function returns.
			 *
			 * @throws File_Not_Found_Exception The file/directory wasn't found.
			 */
			static function ( $response ) {
				if ( $response->getTrashed() ) {
					throw new File_Not_Found_Exception();
				}

				return $response->getName();
			},
			static function ( $exception ) {
				if ( $exception instanceof Not_Found_Exception ) {
					$exception = new File_Not_Found_Exception();
				}

				return new RejectedPromise( $exception );
			}
		);
	}

	/**
	 * Checks whether an ID points to a valid directory inside another directory
	 *
	 * @param string $id The ID of the directory.
	 * @param string $parent_id The ID of the parent directory.
	 *
	 * @return PromiseInterface A promise resolving if the directory is valid.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	public static function check_directory_in_directory( $id, $parent_id ) {
		return self::list_directories(
			$parent_id,
			new API_Fields( array( 'id', 'trashed' ) ),
			new Single_Page_Pagination_Helper()
		)->then(
			/**
			 * `$transform` transforms the raw Google API response into the structured response this function returns.
			 *
			 * @throws Directory_Not_Found_Exception The directory wasn't found.
			 */
			static function ( $directories ) use ( $id ) {
				foreach ( $directories as $directory ) {
					if ( $directory['id'] === $id && ! boolval( $directory['trashed'] ) ) {
						return;
					}
				}

				throw new Directory_Not_Found_Exception();
			},
			static function ( $exception ) {
				if ( $exception instanceof Not_Found_Exception ) {
					$exception = new Directory_Not_Found_Exception();
				}

				return new RejectedPromise( $exception );
			}
		);
	}

	/**
	 * Lists all drives.
	 *
	 * @param Pagination_Helper $pagination_helper An initialized pagination helper. Optional.
	 *
	 * @return PromiseInterface A promise resolving to a list of drives in the format `[ 'id' => '', 'name' => '' ]`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	public static function list_drives( $pagination_helper ) {
		return API_Client::async_paginated_request(
			static function ( $page_token ) {
				return API_Client::get_drive_client()->drives->listDrives(
					array(
						'fields'    => 'nextPageToken, drives(id, name)',
						'pageSize'  => 100,
						'pageToken' => $page_token,
					)
				);
			},
			static function ( $response ) {
				return array_map(
					static function ( $drive ) {
						return array(
							'id'   => $drive->getId(),
							'name' => $drive->getName(),
						);
					},
					$response->getDrives()
				);
			},
			$pagination_helper
		);
	}

	/**
	 * Lists all directories inside a given directory.
	 *
	 * @param string            $parent_id The ID of the directory to list directories in.
	 * @param API_Fields        $fields The fields to list.
	 * @param Pagination_Helper $pagination_helper An initialized pagination helper. Optional.
	 * @param string            $order_by Sets the ordering of the results. Valid options are `createdTime`, `folder`, `modifiedByMeTime`, `modifiedTime`, `name`, `name_natural`, `quotaBytesUsed`, `recency`, `sharedWithMeTime`, `starred`, and `viewedByMeTime`. Default `name`.
	 *
	 * @return PromiseInterface A promise resolving to a list of directories in the format `[ 'id' => '', 'name' => '' ]`- the fields of each directory are givent by the parameter `$fields`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function list_directories( $parent_id, $fields, $pagination_helper, $order_by = 'name' ) {
		return self::list_files(
			$parent_id,
			$fields,
			$order_by,
			$pagination_helper,
			'application/vnd.google-apps.folder'
		);
	}

	/**
	 * Lists all images inside a given directory.
	 *
	 * @param string            $parent_id The ID of the directory to list directories in.
	 * @param API_Fields        $fields The fields to list.
	 * @param Pagination_Helper $pagination_helper An initialized pagination helper. Optional.
	 * @param string            $order_by Sets the ordering of the results. Valid options are `createdTime`, `folder`, `modifiedByMeTime`, `modifiedTime`, `name`, `name_natural`, `quotaBytesUsed`, `recency`, `sharedWithMeTime`, `starred`, and `viewedByMeTime`. Default `name`.
	 *
	 * @return PromiseInterface A promise resolving to a list of images in the format `[ 'id' => '', 'name' => '' ]`- the fields of each directory are givent by the parameter `$fields`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function list_images( $parent_id, $fields, $pagination_helper, $order_by = 'name' ) {
		return self::list_files( $parent_id, $fields, $order_by, $pagination_helper, 'image/' );
	}

	/**
	 * Lists all videos inside a given directory.
	 *
	 * @param string            $parent_id The ID of the directory to list directories in.
	 * @param API_Fields        $fields The fields to list.
	 * @param Pagination_Helper $pagination_helper An initialized pagination helper. Optional.
	 * @param string            $order_by Sets the ordering of the results. Valid options are `createdTime`, `folder`, `modifiedByMeTime`, `modifiedTime`, `name`, `name_natural`, `quotaBytesUsed`, `recency`, `sharedWithMeTime`, `starred`, and `viewedByMeTime`. Default `name`.
	 *
	 * @return PromiseInterface A promise resolving to a list of images in the format `[ 'id' => '', 'name' => '' ]`- the fields of each directory are givent by the parameter `$fields`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function list_videos( $parent_id, $fields, $pagination_helper, $order_by = 'name' ) {
		return self::list_files( $parent_id, $fields, $order_by, $pagination_helper, 'video/' );
	}

	/**
	 * Searches for folders by name fragment across all drives.
	 *
	 * @param string $query The filename fragment to search for.
	 *
	 * @return PromiseInterface A promise resolving to an array of file records with id, name, parents.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	public static function search_folders( $query ) {
		return self::search_files_by_name(
			$query,
			'mimeType = "application/vnd.google-apps.folder"',
			false
		);
	}

	/**
	 * Searches for image and video files by name fragment across all drives.
	 *
	 * @param string $query The filename fragment to search for.
	 *
	 * @return PromiseInterface A promise resolving to an array of file records with id, name, mimeType, parents.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	public static function search_media( $query ) {
		return self::search_files_by_name(
			$query,
			'(mimeType contains "image/" or mimeType contains "video/")',
			true
		);
	}

	/**
	 * Returns the parent directory IDs of a file.
	 *
	 * @param string $file_id The ID of the file.
	 *
	 * @return PromiseInterface A promise resolving to an array of parent directory IDs.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	public static function get_file_parents( $file_id ) {
		return self::get_file_by_id(
			$file_id,
			'id, parents',
			static function ( $file ) {
				$parents = $file->getParents();

				return is_array( $parents ) ? $parents : array();
			}
		);
	}

	/**
	 * Returns a single file's metadata.
	 *
	 * @param string                  $file_id The ID of the file.
	 * @param API_Fields|array<mixed> $fields The fields to request, either as an API_Fields instance or an array to build one from.
	 *
	 * @return PromiseInterface A promise resolving to the parsed file metadata.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function get_file( $file_id, $fields ) {
		if ( is_array( $fields ) ) {
			$fields = new API_Fields( $fields );
		}

		return self::get_file_by_id(
			$file_id,
			$fields->format(),
			static function ( $file ) use ( $fields ) {
				return $fields->parse_response( $file );
			}
		);
	}

	/**
	 * Lists all files of a given type inside a given directory.
	 *
	 * @param string            $parent_id The ID of the directory to list the files in.
	 * @param API_Fields        $fields The fields to list.
	 * @param string            $order_by Sets the ordering of the results. Valid options are `createdTime`, `folder`, `modifiedByMeTime`, `modifiedTime`, `name`, `name_natural`, `quotaBytesUsed`, `recency`, `sharedWithMeTime`, `starred`, and `viewedByMeTime`.
	 * @param Pagination_Helper $pagination_helper An initialized pagination helper.
	 * @param string            $mime_type_prefix The mimeType prefix to filter the files for.
	 *
	 * @return PromiseInterface A promise resolving to a list of files in the format `[ 'id' => '', 'name' => '' ]`- the fields of each file are given by the parameter `$fields`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	private static function list_files( $parent_id, $fields, $order_by, $pagination_helper, $mime_type_prefix ) {
		if ( ! $fields->check(
			array(
				'id',
				'name',
				'mimeType',
				'trashed',
				'size',
				'createdTime',
				'modifiedTime',
				'md5Checksum',
				'copyRequiresWriterPermission',
				'imageMediaMetadata' => array(
					'width',
					'height',
					'time',
					'rotation',
					'cameraMake',
					'cameraModel',
					'aperture',
					'exposureTime',
					'isoSpeed',
					'focalLength',
				),
				'videoMediaMetadata' => array( 'width', 'height', 'durationMillis' ),
				'webContentLink',
				'webViewLink',
				'thumbnailLink',
				'iconLink',
				'hasThumbnail',
				'description',
				'shortcutDetails'    => array( 'targetId', 'targetMimeType' ),
				'permissions'        => array( 'type', 'role' ),
			)
		) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Unsupported_Value_Exception( $fields, 'list_files' );
		}

		return API_Client::async_paginated_request(
			static function (
				$page_token
			) use (
				$parent_id,
				$order_by,
				$pagination_helper,
				$mime_type_prefix,
				$fields
			) {
				$mime_type_check = "(mimeType contains '" .
					$mime_type_prefix .
					"' or (mimeType contains 'application/vnd.google-apps.shortcut' and " .
					"shortcutDetails.targetMimeType contains '" .
					$mime_type_prefix .
					"'))";

				return API_Client::get_drive_client()->files->listFiles(
					array(
						'fields'                    => 'nextPageToken, files(' . $fields->format() . ')',
						'includeItemsFromAllDrives' => true,
						'orderBy'                   => $order_by,
						'pageSize'                  => $pagination_helper->next_list_size( 1000 ),
						'pageToken'                 => $page_token,
						'q'                         => "'" .
							$parent_id .
							"' in parents and " .
							$mime_type_check .
							' and trashed = false',
						'supportsAllDrives'         => true,
					)
				);
			},
			static function ( $response ) use ( $fields, $pagination_helper ) {
				$dirs = array();
				$pagination_helper->iterate(
					$response->getFiles(),
					// phpcs:ignore SlevomatCodingStandard.PHP.DisallowReference.DisallowedInheritingVariableByReference
					static function ( $file ) use ( $fields, &$dirs ) {
						$dirs[] = $fields->parse_response( $file );
					}
				);

				return $dirs;
			},
			$pagination_helper
		);
	}

	/**
	 * Searches for files by name fragment across all drives, filtered by a Drive API mimeType clause.
	 *
	 * @param string $query The filename fragment to search for.
	 * @param string $mime_type_clause A Drive API query clause restricting results by mimeType.
	 * @param bool   $include_mime_type Whether to include the file's mimeType in each result record.
	 *
	 * @return PromiseInterface A promise resolving to an array of file records with id, name, parents
	 *                          (and mimeType, if `$include_mime_type` is true).
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	private static function search_files_by_name( $query, $mime_type_clause, $include_mime_type ) {
		$safe          = str_replace( '"', '\\"', $query );
		$result_fields = $include_mime_type ? 'files(id, name, mimeType, parents)' : 'files(id, name, parents)';
		$params        = array(
			'fields'                    => $result_fields,
			'includeItemsFromAllDrives' => true,
			'pageSize'                  => 100,
			'q'                         => 'name contains "' . $safe . '" and ' . $mime_type_clause .
				' and trashed = false',
			'supportsAllDrives'         => true,
		);

		return API_Client::async_request(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			API_Client::get_drive_client()->files->listFiles( $params ),
			static function ( $response ) use ( $include_mime_type ) {
				$results = array();

				foreach ( $response->getFiles() as $file ) {
					$parents = $file->getParents();
					$entry   = array(
						'id'      => $file->getId(),
						'name'    => $file->getName(),
						'parents' => is_array( $parents ) ? $parents : array(),
					);

					if ( $include_mime_type ) {
						$entry['mimeType'] = $file->getMimeType();
					}

					$results[] = $entry;
				}

				return $results;
			}
		);
	}

	/**
	 * Requests a single file's data by ID.
	 *
	 * @param string   $file_id The ID of the file.
	 * @param string   $request_fields The Drive API `fields` parameter value to request.
	 * @param callable $transform Transforms the raw Google API response into the returned value.
	 *
	 * @return PromiseInterface A promise resolving to the value returned by `$transform`.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	private static function get_file_by_id( $file_id, $request_fields, $transform ) {
		return API_Client::async_request(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			API_Client::get_drive_client()->files->get(
				$file_id,
				array(
					'fields'            => $request_fields,
					'supportsAllDrives' => true,
				)
			),
			$transform
		);
	}
}
