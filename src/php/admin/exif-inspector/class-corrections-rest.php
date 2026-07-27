<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- six related endpoints (corrections + exclusions) over two tables; splitting further would fragment one cohesive concern.
/**
 * Contains the Corrections_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// phpcs:disable SlevomatCodingStandard.Classes.ClassLength.ClassTooLong -- see FileTooLong suppression above.
/**
 * REST API controller for photo/folder orientation corrections and private exclusions.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Corrections_REST {

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
			'exif-inspector/corrections',
			array(
				array(
					'args'                => array(
						'file_id' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
					'callback'            => array( $this, 'get_corrections' ),
					'methods'             => 'GET',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
				array(
					'callback'            => array( $this, 'save_corrections' ),
					'methods'             => 'POST',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/folder-corrections',
			array(
				array(
					'callback'            => array( $this, 'get_folder_corrections' ),
					'methods'             => 'GET',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
				array(
					'callback'            => array( $this, 'save_folder_corrections' ),
					'methods'             => 'POST',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/exclusion',
			array(
				array(
					'callback'            => array( $this, 'get_exclusion' ),
					'methods'             => 'GET',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
				array(
					'callback'            => array( $this, 'save_exclusion' ),
					'methods'             => 'POST',
					'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
				),
			)
		);
	}

	/**
	 * Gets the inherited grid/lightbox correction for a folder.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_folder_corrections( $request ) {
		global $wpdb;
		$folder_id = sanitize_text_field( (string) $request->get_param( 'folder_id' ) );

		if ( '' === $folder_id ) {
			return new WP_Error( 'invalid_folder', 'folder_id is required', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_folder_corrections';

		return new WP_REST_Response(
			array( 'corrections' => self::read_corrections_table( $table, 'folder_id', $folder_id ) ),
			200
		);
	}

	/**
	 * Enables or disables horizontal mirroring for every photo in a folder.
	 * Individual photo rows, including identity rows, override this default.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_folder_corrections( $request ) {
		global $wpdb;
		$params    = $request->get_json_params();
		$folder_id = isset( $params['folder_id'] ) ? sanitize_text_field( $params['folder_id'] ) : '';
		$enabled   = isset( $params['mirror'] ) && $params['mirror'];

		if ( '' === $folder_id ) {
			return new WP_Error( 'invalid_folder', 'folder_id is required', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_folder_corrections';

		foreach ( array( 'grid', 'lightbox' ) as $size_key ) {
			if ( $enabled ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
				$wpdb->replace(
					$table,
					array(
						'folder_id' => $folder_id,
						'h_flip'    => 1,
						'rotation'  => 0,
						'size_key'  => $size_key,
						'v_flip'    => 0,
					),
					array( '%s', '%d', '%d', '%s', '%d' )
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
				$wpdb->delete(
					$table,
					array(
						'folder_id' => $folder_id,
						'size_key'  => $size_key,
					),
					array( '%s', '%s' )
				);
			}
		}

		return new WP_REST_Response( array( 'mirror' => $enabled ), 200 );
	}

	/**
	 * Gets all stored corrections for a file.
	 *
	 * Returns {corrections: {size_key: {r, h, v}}}, including explicit identity overrides.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_corrections( $request ) {
		global $wpdb;
		$file_id = $request->get_param( 'file_id' );

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'File ID is required', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_corrections';

		return new WP_REST_Response(
			array( 'corrections' => self::read_corrections_table( $table, 'image_id', $file_id ) ),
			200
		);
	}

	/**
	 * Saves a single correction for one size of a file.
	 *
	 * Body: {file_id, size_key, rotation, h_flip, v_flip, inherit}
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	public function save_corrections( $request ) {
		global $wpdb;
		$params = $request->get_json_params() ?? array();
		$c      = self::parse_correction_params( $params );

		if ( '' === $c['file_id'] ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		if ( '' === $c['size_key'] ) {
			return new WP_Error( 'invalid_size_key', 'size_key is required', array( 'status' => 400 ) );
		}

		if ( ! in_array( $c['rotation'], array( 0, 90, 180, 270 ), true ) ) {
			return new WP_Error( 'invalid_rotation', 'rotation must be 0, 90, 180 or 270', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_corrections';

		if ( isset( $params['inherit'] ) && $params['inherit'] ) {
			// Removing the photo row makes it inherit the folder correction again.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->delete(
				$table,
				array(
					'image_id' => $c['file_id'],
					'size_key' => $c['size_key'],
				),
				array( '%s', '%s' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->replace(
				$table,
				array(
					'h_flip'   => $c['h_flip'],
					'image_id' => $c['file_id'],
					'rotation' => $c['rotation'],
					'size_key' => $c['size_key'],
					'v_flip'   => $c['v_flip'],
				),
				array( '%d', '%s', '%d', '%s', '%d' )
			);
		}

		return new WP_REST_Response(
			array(
				'h_flip'   => (bool) $c['h_flip'],
				'rotation' => $c['rotation'],
				'size_key' => $c['size_key'],
				'v_flip'   => (bool) $c['v_flip'],
			),
			200
		);
	}

	/**
	 * Gets the private gallery-exclusion state for a photo.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_exclusion( $request ) {
		global $wpdb;
		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_exclusions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$row = $wpdb->get_row(
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT reasons, note, updated_at FROM ' . $table . ' WHERE image_id = %s',
				$file_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return new WP_REST_Response(
				array(
					'excluded' => false,
					'note'     => '',
					'reasons'  => array(),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'excluded'   => true,
				'note'       => (string) $row['note'],
				'reasons'    => array_values( array_filter( explode( ',', (string) $row['reasons'] ) ) ),
				'updated_at' => (string) $row['updated_at'],
			),
			200
		);
	}

	/**
	 * Saves or removes the private gallery-exclusion state for a photo.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	public function save_exclusion( $request ) {
		global $wpdb;
		$p = self::parse_exclusion_params( $request->get_json_params() ?? array() );

		if ( '' === $p['file_id'] ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		if ( $p['excluded'] && array() === $p['reasons'] ) {
			return new WP_Error( 'missing_reason', 'Select at least one reason', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_exclusions';

		if ( $p['excluded'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->replace(
				$table,
				array(
					'excluded_by' => get_current_user_id(),
					'folder_id'   => $p['folder_id'],
					'image_id'    => $p['file_id'],
					'media_type'  => $p['media_type'],
					'note'        => mb_substr( $p['note'], 0, 1000 ),
					'reasons'     => implode( ',', $p['reasons'] ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->delete( $table, array( 'image_id' => $p['file_id'] ), array( '%s' ) );
		}

		return new WP_REST_Response(
			array(
				'excluded' => $p['excluded'],
				'note'     => $p['note'],
				'reasons'  => $p['reasons'],
			),
			200
		);
	}

	/**
	 * Reads size_key → {rotation, h_flip, v_flip} rows from a corrections table into the
	 * REST response shape.
	 *
	 * @param string $table   Fully-prefixed table name.
	 * @param string $id_col  Column name the ID is matched against ('folder_id' or 'image_id').
	 * @param string $id      The folder or file ID to look up.
	 *
	 * @return array<string, array{r: int, h: bool, v: bool}>
	 *
	 * @SuppressWarnings("PHPMD.ShortVariable")
	 */
	private static function read_corrections_table( $table, $id_col, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$rows_result = $wpdb->get_results(
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT size_key, rotation, h_flip, v_flip FROM ' . $table . ' WHERE ' . $id_col . ' = %s',
				$id
			),
			ARRAY_A
		);
		$rows        = is_array( $rows_result ) ? $rows_result : array();
		$corrections = array();

		foreach ( $rows as $row ) {
			$corrections[ $row['size_key'] ] = array(
				'h' => (bool) intval( $row['h_flip'] ),
				'r' => intval( $row['rotation'] ),
				'v' => (bool) intval( $row['v_flip'] ),
			);
		}

		return $corrections;
	}

	/**
	 * Parses and sanitizes the request body for save_corrections().
	 *
	 * @param array<string, mixed> $params Raw JSON request body.
	 *
	 * @return array{file_id: string, size_key: string, rotation: int, h_flip: int, v_flip: int}
	 */
	private static function parse_correction_params( array $params ) {
		return array(
			'file_id'  => isset( $params['file_id'] ) ? sanitize_text_field( $params['file_id'] ) : '',
			'h_flip'   => isset( $params['h_flip'] ) && $params['h_flip'] ? 1 : 0,
			'rotation' => isset( $params['rotation'] ) ? intval( $params['rotation'] ) : 0,
			'size_key' => isset( $params['size_key'] ) ? sanitize_text_field( $params['size_key'] ) : '',
			'v_flip'   => isset( $params['v_flip'] ) && $params['v_flip'] ? 1 : 0,
		);
	}

	/**
	 * Parses and sanitizes the request body for save_exclusion().
	 *
	 * @param array<string, mixed> $params Raw JSON request body.
	 *
	 * @return array{file_id: string, folder_id: string, excluded: bool, media_type: string, note: string, reasons: array<string>}
	 */
	private static function parse_exclusion_params( array $params ) {
		$allowed = array( 'poor_quality', 'duplicate', 'privacy_objection', 'children', 'other' );

		return array(
			'excluded'   => isset( $params['excluded'] ) && (bool) $params['excluded'],
			'file_id'    => isset( $params['file_id'] ) ? sanitize_text_field( $params['file_id'] ) : '',
			'folder_id'  => isset( $params['folder_id'] ) ? sanitize_text_field( $params['folder_id'] ) : '',
			'media_type' => isset( $params['mime_type'] ) && str_starts_with( (string) $params['mime_type'], 'video/' )
				? 'video'
				: 'image',
			'note'       => isset( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '',
			'reasons'    => isset( $params['reasons'] ) && is_array( $params['reasons'] )
				? array_values(
					array_unique(
						array_intersect(
							$allowed,
							array_map( 'sanitize_key', $params['reasons'] )
						)
					)
				)
				: array(),
		);
	}
}
