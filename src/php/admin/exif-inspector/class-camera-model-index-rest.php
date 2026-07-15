<?php // phpcs:ignore SlevomatCodingStandard.Files.FileLength.FileTooLong -- a few lines over; the resumable-scan state machine reads more clearly kept together.
/**
 * Contains the Camera_Model_Index_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Exceptions\Plugin_Not_Authorized_Exception;
use Avpvh\Frontend\API_Fields;
use Avpvh\Frontend\Paging_Pagination_Helper;
use Avpvh\Options;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API controller for the resumable, persistent camera-model index.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Camera_Model_Index_REST {

	private const CAMERA_MODEL_INDEX_OPTION = 'avpvh_camera_model_index';
	private const CAMERA_MODEL_SCAN_OPTION  = 'avpvh_camera_model_scan';

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
			'exif-inspector/model-index/status',
			array(
				'callback'            => array( $this, 'camera_model_index_status' ),
				'methods'             => 'GET',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/model-index/start',
			array(
				'callback'            => array( $this, 'start_camera_model_index' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/model-index/step',
			array(
				'callback'            => array( $this, 'step_camera_model_index' ),
				'methods'             => 'POST',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);

		register_rest_route(
			'avpvh-gallery/v1',
			'exif-inspector/model-index/models',
			array(
				'args'                => array(
					'folder_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => array( $this, 'camera_models_for_folder' ),
				'methods'             => 'GET',
				'permission_callback' => array( Exif_Inspector_Permission::class, 'check' ),
			)
		);
	}

	/**
	 * Returns progress and information about the last complete model index.
	 *
	 * @return WP_REST_Response
	 */
	public function camera_model_index_status() {
		return new WP_REST_Response( self::camera_model_status_data(), 200 );
	}

	/**
	 * Starts a fresh, resumable scan at the configured gallery root.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_camera_model_index() {
		$root_path = Options::$root_path->get();
		$root_id   = is_array( $root_path ) && array() !== $root_path ? end( $root_path ) : '';

		if ( ! is_string( $root_id ) || '' === $root_id ) {
			return new WP_Error(
				'missing_root',
				'Configure a gallery root before indexing camera models',
				array( 'status' => 400 )
			);
		}

		update_option(
			self::CAMERA_MODEL_SCAN_OPTION,
			array(
				'children'      => array(),
				'direct_models' => array(),
				'image_count'   => 0,
				'processed'     => array(),
				'queue'         => array( $root_id ),
				'root_id'       => $root_id,
				'started_at'    => time(),
			),
			false
		);

		return new WP_REST_Response( self::camera_model_status_data(), 200 );
	}

	/**
	 * Scans one folder and persists enough state to resume after interruption.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function step_camera_model_index() {
		$state = get_option( self::CAMERA_MODEL_SCAN_OPTION, array() );

		if ( ! is_array( $state ) || array() === $state['queue'] ) {
			return new WP_Error( 'scan_not_running', 'No camera model scan is running', array( 'status' => 409 ) );
		}

		$folder_id = array_shift( $state['queue'] );

		try {
			list( $directories, $images ) = self::fetch_folder_contents( $folder_id );
		} catch ( Plugin_Not_Authorized_Exception $e ) {
			return new WP_Error( 'not_authorized', 'Plugin not authorized', array( 'status' => 403 ) );
		} catch ( Exception $e ) {
			array_unshift( $state['queue'], $folder_id );
			update_option( self::CAMERA_MODEL_SCAN_OPTION, $state, false );

			return new WP_Error(
				'model_index_error',
				'Indexing failed: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}

		$state = self::merge_step_results( $state, $folder_id, $directories, $images );

		if ( array() === $state['queue'] ) {
			$index = array(
				'children'      => $state['children'],
				'direct_models' => $state['direct_models'],
				'folder_count'  => count( $state['processed'] ),
				'image_count'   => $state['image_count'],
				'root_id'       => $state['root_id'],
				'updated_at'    => time(),
			);
			update_option( self::CAMERA_MODEL_INDEX_OPTION, $index, false );
			delete_option( self::CAMERA_MODEL_SCAN_OPTION );
		} else {
			update_option( self::CAMERA_MODEL_SCAN_OPTION, $state, false );
		}

		return new WP_REST_Response( self::camera_model_status_data(), 200 );
	}

	/**
	 * Returns cached camera models from a folder and all indexed descendants.
	 *
	 * @param WP_REST_Request $request Request containing folder_id.
	 * @return WP_REST_Response
	 */
	public function camera_models_for_folder( $request ) {
		$folder_id = (string) $request->get_param( 'folder_id' );
		$index     = get_option( self::CAMERA_MODEL_INDEX_OPTION, array() );
		$index     = is_array( $index ) ? $index : array();

		$model_names = self::descendant_model_names( $folder_id, $index );

		return new WP_REST_Response(
			array(
				'indexed'    => self::folder_is_indexed( $folder_id, $index ),
				'models'     => $model_names,
				'updated_at' => isset( $index['updated_at'] ) ? $index['updated_at'] : null,
			),
			200
		);
	}

	/**
	 * Collects the sorted, unique camera models used anywhere in a folder or its indexed descendants.
	 *
	 * @param string               $folder_id Starting folder ID.
	 * @param array<string, mixed> $index     The persisted camera-model index.
	 *
	 * @return array<string>
	 */
	private static function descendant_model_names( $folder_id, array $index ) {
		$models = array();
		$seen   = array();
		$queue  = array( $folder_id );

		while ( array() !== $queue ) {
			$id = array_pop( $queue );

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			foreach ( self::index_lookup( $index, 'direct_models', $id ) as $model ) {
				$models[ (string) $model ] = true;
			}

			foreach ( self::index_lookup( $index, 'children', $id ) as $child_id ) {
				$queue[] = (string) $child_id;
			}
		}

		$model_names = array_keys( $models );
		natcasesort( $model_names );

		return array_values( $model_names );
	}

	/**
	 * Reads one folder's entry from a sub-array of the persisted index, defaulting to an empty array.
	 *
	 * @param array<string, mixed> $index Persisted camera-model index.
	 * @param string               $key   Either 'direct_models' or 'children'.
	 * @param string               $id    Folder ID.
	 *
	 * @return array<mixed>
	 */
	private static function index_lookup( array $index, $key, $id ) {
		return isset( $index[ $key ][ $id ] ) ? $index[ $key ][ $id ] : array();
	}

	/**
	 * Whether a folder has already been scanned into the persisted index.
	 *
	 * @param string               $folder_id Folder ID.
	 * @param array<string, mixed> $index     The persisted camera-model index.
	 *
	 * @return bool
	 */
	private static function folder_is_indexed( $folder_id, array $index ) {
		return isset( $index['direct_models'] ) && array_key_exists( $folder_id, $index['direct_models'] );
	}

	/**
	 * Fetches one folder's immediate subfolders and images (with camera model metadata).
	 *
	 * @param string $folder_id The folder to scan.
	 *
	 * @return array{0: array<array<string, mixed>>, 1: array<array<string, mixed>>} Directories, then images.
	 */
	private static function fetch_folder_contents( $folder_id ) {
		$all_items = static function () {
			return ( new Paging_Pagination_Helper() )->withValues( 0, 1000000 );
		};

		$results = API_Client::execute(
			array(
				API_Facade::list_directories(
					$folder_id,
					new API_Fields( array( 'id', 'name' ) ),
					$all_items(),
					'name'
				),
				API_Facade::list_images(
					$folder_id,
					new API_Fields(
						array(
							'id',
							'imageMediaMetadata' => array( 'cameraModel' ),
						)
					),
					$all_items(),
					'name'
				),
			)
		);

		return array( $results[0], $results[1] );
	}

	/**
	 * Merges one scanned folder's children/models into the persisted scan state.
	 *
	 * @param array<string, mixed>        $state       The scan state (already popped $folder_id off its queue).
	 * @param string                      $folder_id   The folder that was just scanned.
	 * @param array<array<string, mixed>> $directories Immediate subfolders of $folder_id.
	 * @param array<array<string, mixed>> $images      Images directly inside $folder_id.
	 *
	 * @return array<string, mixed> The updated scan state.
	 */
	private static function merge_step_results( array $state, $folder_id, array $directories, array $images ) {
		list( $children, $state['queue'] ) = self::enqueue_new_children( $directories, $state );

		natcasesort( $children );
		$model_names = self::direct_model_names( $images );

		$state['children'][ $folder_id ]      = array_values( $children );
		$state['direct_models'][ $folder_id ] = $model_names;
		$state['processed'][]                 = $folder_id;
		$state['image_count']                += count( $images );

		return $state;
	}

	/**
	 * Lists this folder's child IDs and appends the not-yet-seen ones to the scan queue.
	 *
	 * @param array<array<string, mixed>> $directories Immediate subfolders of the scanned folder.
	 * @param array<string, mixed>        $state       The scan state (already popped the current folder off its queue).
	 *
	 * @return array{0: array<string>, 1: array<string>} All child IDs, then the updated queue.
	 */
	private static function enqueue_new_children( array $directories, array $state ) {
		$processed = array_fill_keys( array_map( 'strval', $state['processed'] ), true );
		$queued    = array_fill_keys( array_map( 'strval', $state['queue'] ), true );
		$children  = array();
		$queue     = $state['queue'];

		foreach ( $directories as $directory ) {
			$child_id = isset( $directory['id'] ) ? (string) $directory['id'] : '';

			if ( '' === $child_id ) {
				continue;
			}

			$children[] = $child_id;

			if ( isset( $processed[ $child_id ] ) || isset( $queued[ $child_id ] ) ) {
				continue;
			}

			$queue[]             = $child_id;
			$queued[ $child_id ] = true;
		}

		return array( $children, $queue );
	}

	/**
	 * Collects the sorted, unique camera models used by a list of images.
	 *
	 * @param array<array<string, mixed>> $images Images with optional imageMediaMetadata.cameraModel.
	 *
	 * @return array<string>
	 */
	private static function direct_model_names( array $images ) {
		$models = array();

		foreach ( $images as $image ) {
			$model = isset( $image['imageMediaMetadata']['cameraModel'] )
				? trim( (string) $image['imageMediaMetadata']['cameraModel'] )
				: '';

			if ( '' !== $model ) {
				$models[ $model ] = true;
			}
		}

		$model_names = array_keys( $models );
		natcasesort( $model_names );

		return array_values( $model_names );
	}

	/**
	 * Builds a compact scan status response.
	 *
	 * @return array<string, mixed>
	 */
	private static function camera_model_status_data() {
		$state = get_option( self::CAMERA_MODEL_SCAN_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$index = get_option( self::CAMERA_MODEL_INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		$queue = isset( $state['queue'] ) ? $state['queue'] : array();

		return array(
			'folders_done'   => count( isset( $state['processed'] ) ? $state['processed'] : array() ),
			'folders_queued' => count( $queue ),
			'folder_count'   => intval( isset( $index['folder_count'] ) ? $index['folder_count'] : 0 ),
			'images_done'    => intval( isset( $state['image_count'] ) ? $state['image_count'] : 0 ),
			'image_count'    => intval( isset( $index['image_count'] ) ? $index['image_count'] : 0 ),
			'running'        => array() !== $queue,
			'updated_at'     => isset( $index['updated_at'] ) ? $index['updated_at'] : null,
		);
	}
}
