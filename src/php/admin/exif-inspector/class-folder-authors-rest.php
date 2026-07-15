<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- the inheritance-resolution walk (parent map, path, effective authors) reads more clearly kept together.
/**
 * Contains the Folder_Authors_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// phpcs:disable SlevomatCodingStandard.Classes.ClassLength.ClassTooLong -- see FileTooLong suppression above.
/**
 * REST API controller for folder → member(s) authorship, built on top of the camera-model
 * index's folder tree. A folder with no explicit row inherits its effective author(s) from
 * the nearest ancestor that has one; a folder with rows is explicit and cascades to its own
 * descendants. member_id 0 is a reserved sentinel meaning "explicitly AVPvH, stop inheriting".
 *
 * @phan-constructor-used-for-side-effects
 */
final class Folder_Authors_REST {

	/**
	 * Sentinel member_id representing the organisation itself rather than a specific member.
	 */
	private const AVPVH_SENTINEL = 0;

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
	 */
	public function register_routes() {
		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/folder-authors',
			array(
				array(
					'callback'            => array( $this, 'list_folder_authors' ),
					'methods'             => 'GET',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
				array(
					'callback'            => array( $this, 'save_folder_authors' ),
					'methods'             => 'POST',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
			)
		);
	}

	/**
	 * Lists every folder in the camera-model index with its camera models and resolved author(s).
	 *
	 * @return WP_REST_Response
	 */
	public function list_folder_authors() {
		$index = Camera_Model_Index_REST::get_index();

		if ( ! isset( $index['root_id'] ) || '' === $index['root_id'] ) {
			return new WP_REST_Response( array( 'folders' => array() ), 200 );
		}

		$children      = isset( $index['children'] ) ? $index['children'] : array();
		$names         = isset( $index['names'] ) ? $index['names'] : array();
		$direct_models = isset( $index['direct_models'] ) ? $index['direct_models'] : array();
		$root_id       = (string) $index['root_id'];
		$root_name     = isset( $index['root_name'] ) && '' !== $index['root_name']
			? (string) $index['root_name']
			: $root_id;
		$parents       = self::build_parent_map( $children );
		$explicit      = self::load_explicit_authors();

		$folders = array();

		foreach ( array_keys( $children ) as $folder_id ) {
			$folders[] = self::describe_folder(
				(string) $folder_id,
				$root_id,
				$root_name,
				$parents,
				$names,
				$direct_models,
				$explicit
			);
		}

		return new WP_REST_Response( array( 'folders' => $folders ), 200 );
	}

	/**
	 * Sets, clears, or reverts-to-inherited the explicit author assignment for one folder.
	 *
	 * Body: {folder_id: string, mode: 'inherit'|'members', member_ids?: int[]}
	 * - mode 'inherit' removes any explicit assignment (the folder reverts to inheriting).
	 * - mode 'members' with an empty (or all-zero) member_ids list explicitly sets AVPvH.
	 * - mode 'members' with one or more member_ids sets those as the explicit authors.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_folder_authors( $request ) {
		$params    = $request->get_json_params();
		$folder_id = isset( $params['folder_id'] ) ? sanitize_text_field( $params['folder_id'] ) : '';
		$mode      = isset( $params['mode'] ) ? sanitize_key( $params['mode'] ) : '';

		if ( '' === $folder_id ) {
			return new WP_Error( 'invalid_folder', 'folder_id is required', array( 'status' => 400 ) );
		}

		if ( ! in_array( $mode, array( 'inherit', 'members' ), true ) ) {
			return new WP_Error( 'invalid_mode', "mode must be 'inherit' or 'members'", array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agallery_folder_authors';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$wpdb->delete( $table, array( 'folder_id' => $folder_id ), array( '%s' ) );

		if ( 'inherit' === $mode ) {
			return new WP_REST_Response(
				array(
					'folder_id' => $folder_id,
					'mode'      => 'inherit',
				),
				200
			);
		}

		$member_ids = self::sanitize_member_ids( isset( $params['member_ids'] ) ? $params['member_ids'] : array() );

		foreach ( $member_ids as $member_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->insert(
				$table,
				array(
					'folder_id' => $folder_id,
					'member_id' => $member_id,
				),
				array( '%s', '%d' )
			);
		}

		return new WP_REST_Response(
			array(
				'folder_id'  => $folder_id,
				'member_ids' => $member_ids,
				'mode'       => 'members',
			),
			200
		);
	}

	/**
	 * Builds one folder's row for the table: identity, camera models, and resolved authors.
	 *
	 * @param string                       $folder_id     The folder being described.
	 * @param string                       $root_id       Gallery root folder ID.
	 * @param string                       $root_name     Gallery root folder display name.
	 * @param array<string, string>        $parents       Child ID → parent ID map.
	 * @param array<string, string>        $names         Folder ID → display name map.
	 * @param array<string, array<string>> $direct_models Folder ID → camera models found directly inside it.
	 * @param array<string, array<int>>    $explicit      Folder ID → explicitly assigned member IDs.
	 *
	 * @return array<string, mixed>
	 */
	private static function describe_folder(
		$folder_id,
		$root_id,
		$root_name,
		array $parents,
		array $names,
		array $direct_models,
		array $explicit
	) {
		$resolved = self::resolve_authors( $folder_id, $parents, $explicit );

		return array(
			'camera_models'        => isset( $direct_models[ $folder_id ] ) ? array_values(
				$direct_models[ $folder_id ]
			) : array(),
			'effective_member_ids' => $resolved['member_ids'],
			'effective_source'     => $resolved['source'],
			'explicit_member_ids'  => isset( $explicit[ $folder_id ] ) ? array_values(
				$explicit[ $folder_id ]
			) : array(),
			'folder_id'            => $folder_id,
			'name'                 => self::folder_label( $folder_id, $root_id, $root_name, $names ),
			'path'                 => self::build_path( $folder_id, $root_id, $root_name, $parents, $names ),
		);
	}

	/**
	 * The display name for a folder: the root's own name for the root, else its recorded name,
	 * falling back to the raw ID for folders indexed before name-tracking was added.
	 *
	 * @param string                $folder_id Folder ID.
	 * @param string                $root_id   Gallery root folder ID.
	 * @param string                $root_name Gallery root folder display name.
	 * @param array<string, string> $names     Folder ID → display name map.
	 *
	 * @return string
	 */
	private static function folder_label( $folder_id, $root_id, $root_name, array $names ) {
		if ( $folder_id === $root_id ) {
			return $root_name;
		}

		return isset( $names[ $folder_id ] ) && '' !== $names[ $folder_id ] ? $names[ $folder_id ] : $folder_id;
	}

	/**
	 * Inverts the camera-model index's parent → children map into a child → parent lookup.
	 *
	 * @param array<string, array<string>> $children Folder ID → child folder IDs.
	 *
	 * @return array<string, string> Child ID → parent ID.
	 */
	private static function build_parent_map( array $children ) {
		$parents = array();

		foreach ( $children as $parent_id => $child_ids ) {
			foreach ( (array) $child_ids as $child_id ) {
				$parents[ (string) $child_id ] = (string) $parent_id;
			}
		}

		return $parents;
	}

	/**
	 * Builds a "Root / Parent / Folder" breadcrumb for a folder by walking up the parent map.
	 * Guards against a cyclical map (which should never happen, but a REST response must
	 * never hang) with a generous hop limit.
	 *
	 * @param string                $folder_id Folder to build the path for.
	 * @param string                $root_id   Gallery root folder ID.
	 * @param string                $root_name Gallery root folder display name.
	 * @param array<string, string> $parents   Child ID → parent ID map.
	 * @param array<string, string> $names     Folder ID → display name map.
	 *
	 * @return string
	 */
	private static function build_path( $folder_id, $root_id, $root_name, array $parents, array $names ) {
		$segments = array();
		$current  = $folder_id;

		for ( $hop = 0; $hop < 50 && '' !== $current; ++$hop ) {
			array_unshift( $segments, self::folder_label( $current, $root_id, $root_name, $names ) );

			if ( $current === $root_id ) {
				break;
			}

			$current = isset( $parents[ $current ] ) ? $parents[ $current ] : '';
		}

		return implode( ' / ', $segments );
	}

	/**
	 * Reads every explicit folder-author assignment from the database.
	 *
	 * @return array<string, array<int>> Folder ID → assigned member IDs (a single [0] entry means "explicitly AVPvH").
	 */
	private static function load_explicit_authors() {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_folder_authors';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$rows_result = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is concatenated (not user-supplied), no user input in this query.
			"SELECT folder_id, member_id FROM {$table}",
			ARRAY_A
		);
		$rows = is_array( $rows_result ) ? $rows_result : array();
		$map  = array();

		foreach ( $rows as $row ) {
			$folder_id = (string) $row['folder_id'];

			if ( ! isset( $map[ $folder_id ] ) ) {
				$map[ $folder_id ] = array();
			}

			$map[ $folder_id ][] = (int) $row['member_id'];
		}

		return $map;
	}

	/**
	 * Resolves a folder's effective author(s): its own explicit assignment if it has one,
	 * else the nearest ancestor's, else the AVPvH default.
	 *
	 * @param string                    $folder_id Folder to resolve.
	 * @param array<string, string>     $parents   Child ID → parent ID map.
	 * @param array<string, array<int>> $explicit  Folder ID → explicitly assigned member IDs.
	 *
	 * @return array{member_ids: array<int>, source: string} member_ids is never empty (AVPvH sentinel [0] when unassigned).
	 */
	private static function resolve_authors( $folder_id, array $parents, array $explicit ) {
		$current = $folder_id;

		for ( $hop = 0; $hop < 50 && '' !== $current; ++$hop ) {
			if ( isset( $explicit[ $current ] ) && array() !== $explicit[ $current ] ) {
				return array(
					'member_ids' => self::real_members_or_avpvh( $explicit[ $current ] ),
					'source'     => $current === $folder_id ? 'explicit' : 'inherited',
				);
			}

			$current = isset( $parents[ $current ] ) ? $parents[ $current ] : '';
		}

		return array(
			'member_ids' => array( self::AVPVH_SENTINEL ),
			'source'     => 'default',
		);
	}

	/**
	 * Strips the AVPvH sentinel out of an assignment, unless it's all there is.
	 *
	 * @param array<int> $member_ids Raw assigned member IDs for one folder.
	 *
	 * @return array<int>
	 */
	private static function real_members_or_avpvh( array $member_ids ) {
		$real = array_values(
			array_filter(
				$member_ids,
				static function ( $member_id ) {
					return $member_id > self::AVPVH_SENTINEL;
				}
			)
		);

		return array() !== $real ? $real : array( self::AVPVH_SENTINEL );
	}

	/**
	 * Sanitizes the member_ids submitted for an explicit 'members' assignment: unique positive
	 * integers, or the single AVPvH sentinel when the list is empty (explicitly org-owned).
	 *
	 * @param mixed $raw Raw member_ids value from the request body.
	 *
	 * @return array<int>
	 */
	private static function sanitize_member_ids( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array( self::AVPVH_SENTINEL );
		}

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $raw ),
					static function ( $member_id ) {
						return $member_id > self::AVPVH_SENTINEL;
					}
				)
			)
		);

		return array() !== $ids ? $ids : array( self::AVPVH_SENTINEL );
	}
}
