<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- the root-relative folder-verification walk (BFS over Drive ancestor chains) reads more clearly kept together.
/**
 * Contains the Browse_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Exceptions\API_Exception;
use Avpvh\Exceptions\Directory_Not_Found_Exception;
use Avpvh\Exceptions\Not_Found_Exception;
use Avpvh\Exceptions\Plugin_Not_Authorized_Exception;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Single_Page_Pagination_Helper;
use Avpvh\Options;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// phpcs:disable SlevomatCodingStandard.Classes.ClassLength.ClassTooLong -- see FileTooLong suppression above.
/**
 * REST API controller for folder/file browsing and search in the EXIF Inspector.
 *
 * @phan-constructor-used-for-side-effects
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
final class Browse_REST {

	/**
	 * Registers the REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	public function register_routes() {
		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/search',
			array(
				'args'                => array(
					'q' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'type'              => 'string',
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && mb_strlen( $v ) >= 2;
						},
					),
				),
				'callback'            => array( $this, 'search_files' ),
				'methods'             => 'GET',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/list-folders',
			array(
				'callback'            => array( $this, 'list_folders' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/list-files',
			array(
				'callback'            => array( $this, 'list_files' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/list-subfolders',
			array(
				'callback'            => array( $this, 'list_subfolders' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);
	}

	/**
	 * Gets a folder ID by parent and folder name.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_folders( $request ) {
		try {
			$params      = $request->get_json_params();
			$parent_id   = isset( $params['parent_id'] ) ? $params['parent_id'] : '';
			$folder_name = isset( $params['folder_name'] ) ? $params['folder_name'] : '';

			if ( '' === $parent_id ) {
				$root_path = Options::$root_path->get();
				$parent_id = end( $root_path );
			}

			if ( '' === $folder_name ) {
				return new WP_Error( 'invalid_folder_name', 'Folder name is required', array( 'status' => 400 ) );
			}

			$folder_id = self::find_folder_id_by_name( $parent_id, $folder_name );

			return new WP_REST_Response( array( 'folder_id' => $folder_id ), 200 );
		} catch ( Directory_Not_Found_Exception $e ) {
			return new WP_Error(
				'folder_not_found',
				'Folder not found: ' . $e->getMessage(),
				array( 'status' => 404 )
			);
		} catch ( Not_Found_Exception $e ) {
			return new WP_Error(
				'folder_not_found',
				'Folder not found: ' . $e->getMessage(),
				array( 'status' => 404 )
			);
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( API_Exception $e ) {
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Lists files in a directory.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	public function list_files( $request ) {
		try {
			$params    = $request->get_json_params();
			$parent_id = isset( $params['parent_id'] ) ? $params['parent_id'] : '';

			if ( '' === $parent_id ) {
				return new WP_Error( 'invalid_parent', 'Parent ID is required', array( 'status' => 400 ) );
			}

			$results = API_Client::execute(
				array(
					API_Facade::list_images(
						$parent_id,
						self::browse_fields(),
						new Single_Page_Pagination_Helper(),
						'name'
					),
					API_Facade::list_videos(
						$parent_id,
						self::browse_fields(),
						new Single_Page_Pagination_Helper(),
						'name'
					),
				)
			);
			$files   = array_merge( $results[0], $results[1] );
			usort(
				$files,
				static function ( $a, $b ) {
					return strnatcasecmp( (string) $a['name'], (string) $b['name'] );
				}
			);

			return new WP_REST_Response(
				array(
					'excluded_ids' => self::excluded_ids_for_folder( $parent_id ),
					'files'        => $files,
				),
				200
			);
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( API_Exception $e ) {
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Lists immediate subfolders of a given Drive folder.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_subfolders( $request ) {
		try {
			$params    = $request->get_json_params();
			$parent_id = isset( $params['parent_id'] ) ? $params['parent_id'] : '';

			if ( '' === $parent_id ) {
				return new WP_Error( 'invalid_parent', 'Parent ID is required', array( 'status' => 400 ) );
			}

			$drive      = API_Client::get_drive_client();
			$params_api = array(
				'fields'                    => 'files(id, name)',
				'includeItemsFromAllDrives' => true,
				'pageSize'                  => 100,
				'q'                         => '"' . $parent_id . '" in parents and ' .
					'mimeType = "application/vnd.google-apps.folder" and trashed = false',
				'supportsAllDrives'         => true,
			);
			$results    = API_Client::execute(
				array(
					API_Client::async_request(
						// @phan-suppress-next-line PhanTypeMismatchArgument
						$drive->files->listFiles( $params_api ),
						static function ( $response ) {
							$out = array();

							foreach ( $response->getFiles() as $f ) {
								$out[] = array(
									'id'   => $f->getId(),
									'name' => $f->getName(),
								);
							}

							return $out;
						}
					),
				)
			);

			return new WP_REST_Response( array( 'folders' => $results[0] ), 200 );
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( API_Exception $e ) {
			return new WP_Error( 'api_error', 'API error: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Searches for image and video files by filename fragment.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_files( $request ) {
		$query     = $request->get_param( 'q' );
		$root_path = Options::$root_path->get();
		$root_id   = is_array( $root_path ) && array() !== $root_path ? end( $root_path ) : '';

		// Cache per (query, root) for 2 minutes — avoids repeated Drive API hits
		// when the user types character by character or retries the same search.
		$cache_key = 'avpvh_srch_media_v2_' . md5( $query . "\x00" . $root_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return new WP_REST_Response( $cached, 200 );
		}

		try {
			$data = self::search_and_filter( $query, $root_id );
			set_transient( $cache_key, $data, 120 );

			return new WP_REST_Response( $data, 200 );
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( Throwable $e ) {
			// Return empty results rather than 500 — Drive rate limits are transient
			// and a hard error breaks the autocomplete UX unnecessarily.
			return new WP_REST_Response(
				array(
					'files'       => array(),
					'folders'     => array(),
					'unavailable' => true,
				),
				200
			);
		}
	}

	/**
	 * Finds a direct child folder of $parent_id whose name matches $folder_name (case-insensitively).
	 * Drive's name= and name-contains operators are both case-sensitive, so this lists all folders
	 * in the parent and matches case-insensitively in PHP via strcasecmp.
	 *
	 * @param string $parent_id   Parent Drive folder ID.
	 * @param string $folder_name Folder name to match.
	 *
	 * @return string The matching folder's ID (or shortcut target ID).
	 *
	 * @throws Directory_Not_Found_Exception When no matching folder is found.
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	private static function find_folder_id_by_name( $parent_id, $folder_name ) {
		$drive = API_Client::get_drive_client();
		$q     = '"' . $parent_id . '" in parents and (mimeType = "application/vnd.google-apps.folder" or ' .
			'(mimeType = "application/vnd.google-apps.shortcut" and ' .
			'shortcutDetails.targetMimeType = "application/vnd.google-apps.folder")) and trashed = false';

		return API_Client::execute(
			array(
				API_Client::async_request(
					// @phan-suppress-next-line PhanTypeMismatchArgument
					$drive->files->listFiles(
						array(
							'fields'                    => 'files(id, name, mimeType, shortcutDetails(targetId))',
							'includeItemsFromAllDrives' => true,
							'pageSize'                  => 1000,
							'q'                         => $q,
							'supportsAllDrives'         => true,
						)
					),
					static function ( $response ) use ( $folder_name ) {
						foreach ( $response->getFiles() as $f ) {
							if ( 0 === strcasecmp( $f->getName(), $folder_name ) ) {
								return 'application/vnd.google-apps.shortcut' === $f->getMimeType()
									? $f->getShortcutDetails()->getTargetId()
									: $f->getId();
							}
						}

						throw new Directory_Not_Found_Exception( esc_html( $folder_name ) );
					}
				),
			)
		)[0];
	}

	/**
	 * The Drive fields requested for each file/video listed in the inspector.
	 *
	 * @return API_Fields
	 */
	private static function browse_fields() {
		return new API_Fields(
			array(
				'createdTime',
				'description',
				'id',
				'imageMediaMetadata' => array(
					'aperture',
					'cameraMake',
					'cameraModel',
					'exposureTime',
					'focalLength',
					'height',
					'isoSpeed',
					'rotation',
					'width',
				),
				'videoMediaMetadata' => array( 'width', 'height', 'durationMillis' ),
				'mimeType',
				'md5Checksum',
				'modifiedTime',
				'name',
				'size',
				'thumbnailLink',
				'iconLink',
				'hasThumbnail',
				'webContentLink',
				'webViewLink',
			)
		);
	}

	/**
	 * Returns the string image/video IDs excluded from the public gallery for a folder.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 *
	 * @return array<string>
	 */
	private static function excluded_ids_for_folder( $folder_id ) {
		global $wpdb;
		$exclusions_table = $wpdb->prefix . 'agallery_photo_exclusions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$excluded_ids_col = $wpdb->get_col(
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT image_id FROM ' . $exclusions_table . ' WHERE folder_id = %s',
				$folder_id
			)
		);
		$excluded_ids = is_array( $excluded_ids_col ) ? $excluded_ids_col : array();

		return array_values( array_map( 'strval', $excluded_ids ) );
	}

	/**
	 * Runs the Drive search and restricts/annotates folder results relative to the gallery root.
	 *
	 * @param string $query   Filename fragment to search for.
	 * @param string $root_id Gallery root folder ID (may be empty or 'root').
	 *
	 * @return array{files: array<array<string, mixed>>, folders: array<array<string, mixed>>}
	 */
	private static function search_and_filter( $query, $root_id ) {
		$results = API_Client::execute(
			array(
				API_Facade::search_media( $query ),
				API_Facade::search_folders( $query ),
			)
		);

		$files   = $results[0];
		$folders = $results[1];

		// Walk the parent chain of each found folder upward until we hit root_id (max 4 levels).
		// Results outside the gallery root are excluded; folders also get a parentName for display.
		if ( '' !== $root_id && 'root' !== $root_id ) {
			$files   = self::filter_folders_by_root( $files, $root_id );
			$folders = self::filter_folders_by_root( $folders, $root_id );
		} elseif ( array() !== $folders ) {
			// No root filtering — just resolve the direct parent name for display.
			$folders = self::resolve_parent_names( $folders );
		}

		return array(
			'files'   => $files,
			'folders' => $folders,
		);
	}

	/**
	 * Filters a list of Drive folders to only those whose ancestor chain contains $root_id.
	 * Walks up to 4 levels. Batches all parent lookups per level.
	 * Also attaches parentName (name of the direct parent) for display.
	 *
	 * @param array<array<string,mixed>> $folders Folders with 'id', 'name', 'parents'.
	 * @param string                     $root_id Gallery root folder ID.
	 * @return array<array<string,mixed>> Filtered folders, each with optional 'parentName'.
	 */
	private static function filter_folders_by_root( array $folders, $root_id ) {
		list( $verified, $pending ) = self::partition_by_direct_parent( $folders, $root_id );
		$parent_name                = array();

		for ( $level = 0; $level < 3 && array() !== $pending; ++$level ) {
			$id_parents = self::fetch_parents_for_pending( $pending );

			if ( null === $id_parents ) {
				break;
			}

			if ( 0 === $level ) {
				$parent_name = self::capture_direct_parent_names( $pending );
			}

			list( $pending, $verified ) = self::advance_pending_level( $pending, $id_parents, $root_id, $verified );
		}

		foreach ( array_keys( $pending ) as $idx ) {
			$verified[ $idx ] = false;
		}

		return self::assemble_verified_folders( $folders, $verified, $parent_name );
	}

	/**
	 * Splits folders into those already verified by their direct parent and those needing more levels.
	 *
	 * @param array<array<string,mixed>> $folders Folders with 'parents'.
	 * @param string                     $root_id Gallery root folder ID.
	 *
	 * @return array{0: array<int, bool>, 1: array<int, array<string>>} Verified map, then pending map (folder index → parent IDs).
	 */
	private static function partition_by_direct_parent( array $folders, $root_id ) {
		$verified = array();
		$pending  = array();

		foreach ( $folders as $idx => $f ) {
			$parents = isset( $f['parents'] ) ? $f['parents'] : array();

			if ( array() === $parents ) {
				$verified[ $idx ] = false;
			} elseif ( in_array( $root_id, $parents, true ) ) {
				$verified[ $idx ] = true;
			} else {
				$pending[ $idx ] = $parents;
			}
		}

		return array( $verified, $pending );
	}

	/**
	 * Batch-fetches parents for every ID referenced across all pending folders.
	 *
	 * @param array<int, array<string>> $pending Folder index → parent IDs still to verify.
	 *
	 * @return array<string, array<string>>|null Parents keyed by ID, or null on API failure.
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	private static function fetch_parents_for_pending( array $pending ) {
		$all_ids = array();

		foreach ( $pending as $ids ) {
			foreach ( $ids as $id ) {
				$all_ids[] = $id;
			}
		}

		$all_ids  = array_values( array_unique( $all_ids ) );
		$promises = array_map(
			static function ( $id ) {
				return API_Facade::get_file_parents( $id );
			},
			$all_ids
		);

		try {
			$results = API_Client::execute( $promises );
		} catch ( Throwable $e ) {
			return null;
		}

		$id_parents = array();

		foreach ( $all_ids as $i => $id ) {
			$id_parents[ $id ] = is_array( $results[ $i ] ) ? $results[ $i ] : array();
		}

		return $id_parents;
	}

	/**
	 * Fetches the direct parent's display name for each pending folder (first level only).
	 *
	 * @param array<int, array<string>> $pending Folder index → parent IDs still to verify.
	 *
	 * @return array<int, string> Folder index → direct parent name.
	 */
	private static function capture_direct_parent_names( array $pending ) {
		$parent_name = array();

		foreach ( $pending as $idx => $ids ) {
			$pid = isset( $ids[0] ) ? $ids[0] : '';

			if ( '' === $pid ) {
				continue;
			}

			try {
				$name_results        = API_Client::execute( array( API_Facade::get_file_name( $pid ) ) );
				$parent_name[ $idx ] = is_string( $name_results[0] ) ? $name_results[0] : '';
			} catch ( Throwable $e ) {
				$parent_name[ $idx ] = '';
			}
		}

		return $parent_name;
	}

	/**
	 * Checks one more ancestor level for every still-pending folder.
	 *
	 * @param array<int, array<string>>    $pending    Folder index → parent IDs from the previous level.
	 * @param array<string, array<string>> $id_parents Parents of every ID in $pending, keyed by ID.
	 * @param string                       $root_id    Gallery root folder ID.
	 * @param array<int, bool>             $verified   Verified map from the previous level.
	 *
	 * @return array{0: array<int, array<string>>, 1: array<int, bool>} The next level's pending map, then the updated verified map.
	 */
	private static function advance_pending_level( array $pending, array $id_parents, $root_id, array $verified ) {
		$next_pending = array();

		foreach ( $pending as $idx => $ids ) {
			$result = self::collect_grandparents( $ids, $id_parents, $root_id );

			if ( $result['verified'] ) {
				$verified[ $idx ] = true;
			} elseif ( array() !== $result['grandparents'] ) {
				$next_pending[ $idx ] = array_values( array_unique( $result['grandparents'] ) );
			} else {
				$verified[ $idx ] = false;
			}
		}

		return array( $next_pending, $verified );
	}

	/**
	 * Collects the grandparent IDs of one folder's pending parent IDs, stopping early
	 * (and reporting verified) if $root_id turns up among them.
	 *
	 * @param array<string>                $ids        Parent IDs to check one level up.
	 * @param array<string, array<string>> $id_parents Parents of every relevant ID, keyed by ID.
	 * @param string                       $root_id    Gallery root folder ID.
	 *
	 * @return array{verified: bool, grandparents: array<string>}
	 */
	private static function collect_grandparents( array $ids, array $id_parents, $root_id ) {
		$grandparents = array();

		foreach ( $ids as $id ) {
			$gps = isset( $id_parents[ $id ] ) ? $id_parents[ $id ] : array();

			if ( in_array( $root_id, $gps, true ) ) {
				return array(
					'grandparents' => array(),
					'verified'     => true,
				);
			}

			foreach ( $gps as $gp ) {
				$grandparents[] = $gp;
			}
		}

		return array(
			'grandparents' => $grandparents,
			'verified'     => false,
		);
	}

	/**
	 * Builds the final filtered folder list, attaching parentName where known.
	 *
	 * @param array<array<string,mixed>> $folders     Original, unfiltered folders.
	 * @param array<int, bool>           $verified    Verified map.
	 * @param array<int, string>         $parent_name Folder index → direct parent name.
	 *
	 * @return array<array<string,mixed>> Filtered folders, each with optional 'parentName'.
	 */
	private static function assemble_verified_folders( array $folders, array $verified, array $parent_name ) {
		$result = array();

		foreach ( $folders as $idx => $f ) {
			if ( ! ( isset( $verified[ $idx ] ) ? $verified[ $idx ] : false ) ) {
				continue;
			}

			if ( isset( $parent_name[ $idx ] ) && '' !== $parent_name[ $idx ] ) {
				$f['parentName'] = $parent_name[ $idx ];
			}

			$result[] = $f;
		}

		return $result;
	}

	/**
	 * Attaches parentName to each folder by fetching the direct parent's name.
	 *
	 * @param array<array<string,mixed>> $folders Folders with 'parents'.
	 * @return array<array<string,mixed>> Folders with 'parentName' added where possible.
	 */
	private static function resolve_parent_names( array $folders ) {
		$parent_ids = self::collect_direct_parent_ids( $folders );

		if ( array() === $parent_ids ) {
			return $folders;
		}

		$names_by_pid = self::fetch_names_by_id( $parent_ids );

		foreach ( $folders as $idx => $f ) {
			$pid = isset( $f['parents'][0] ) ? $f['parents'][0] : '';

			if ( '' !== $pid && isset( $names_by_pid[ $pid ] ) && '' !== $names_by_pid[ $pid ] ) {
				$folders[ $idx ]['parentName'] = $names_by_pid[ $pid ];
			}
		}

		return $folders;
	}

	/**
	 * Collects the direct parent ID of every folder that has one.
	 *
	 * @param array<array<string,mixed>> $folders Folders with 'parents'.
	 *
	 * @return array<string> Unique parent IDs.
	 */
	private static function collect_direct_parent_ids( array $folders ) {
		$parent_ids = array();

		foreach ( $folders as $f ) {
			if ( isset( $f['parents'] ) && array() !== $f['parents'] ) {
				$parent_ids[] = $f['parents'][0];
			}
		}

		return array_values( array_unique( $parent_ids ) );
	}

	/**
	 * Fetches the display name for each given Drive ID. Best-effort: returns an empty
	 * map on any API failure rather than propagating the error.
	 *
	 * @param array<string> $parent_ids Drive folder IDs.
	 *
	 * @return array<string, string> ID → name.
	 */
	private static function fetch_names_by_id( array $parent_ids ) {
		try {
			$promises = array_map(
				static function ( $pid ) {
					return API_Facade::get_file_name( $pid );
				},
				$parent_ids
			);
			$results  = API_Client::execute( $promises );
		} catch ( Throwable $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort parent-name lookup; on failure we return an empty map and folders simply lack a parentName.
			return array();
		}

		$names_by_pid = array();

		foreach ( $parent_ids as $i => $pid ) {
			$names_by_pid[ $pid ] = is_string( $results[ $i ] ) ? $results[ $i ] : '';
		}

		return $names_by_pid;
	}
}
