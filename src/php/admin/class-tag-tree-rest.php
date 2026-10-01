<?php
/**
 * Contains the Tag_Tree_REST class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Frontend\Subject_Tag_Editor;
use Avpvh\Frontend\Subject_Tag_Tree;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints behind the "Tags" admin page (Tag_Tree_Page), admins only:
 * read the tree with how many photos have each tag, and change it.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Tag_Tree_REST {

	/**
	 * Route suffix => HTTP method and handler.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const ROUTES = array(
		''        => array( 'GET', 'tree' ),
		'/create' => array( 'POST', 'create' ),
		'/delete' => array( 'POST', 'delete' ),
		'/shift'  => array( 'POST', 'shift' ),
		'/update' => array( 'POST', 'update' ),
	);

	/**
	 * Registers the routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers the routes: GET tag-tree, POST tag-tree/{create,update,shift,delete}.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};

		foreach ( self::ROUTES as $path => list( $method, $callback ) ) {
			register_rest_route(
				'avpvh-gallery/v1',
				'tag-tree' . $path,
				array(
					'callback'            => array( self::class, $callback ),
					'methods'             => $method,
					'permission_callback' => $admin,
				)
			);
		}
	}

	/**
	 * The tree, and how many photos have each tag.
	 *
	 * @return WP_REST_Response
	 */
	public static function tree() {
		return new WP_REST_Response(
			array(
				'nodes' => array_values( Subject_Tag_Tree::nodes() ),
				'usage' => (object) Subject_Tag_Tree::usage(),
			),
			200
		);
	}

	/**
	 * Adds a section, group or tag. Body: parent_id (null: top level), type, label.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( $request ) {
		$parent = $request->get_param( 'parent_id' );
		$result = Subject_Tag_Editor::create(
			null === $parent ? null : (int) $parent,
			(string) $request->get_param( 'type' ),
			sanitize_text_field( (string) $request->get_param( 'label' ) )
		);

		return is_string( $result ) ? self::problem( $result ) : self::tree();
	}

	/**
	 * Renames, moves, or sets one-tag-per-photo. Body: id, and any of label,
	 * single, parent_id (null: top level).
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( $request ) {
		$body    = $request->get_json_params() ?? array();
		$changes = array_intersect_key( $body, array_flip( array( 'label', 'single', 'parent_id' ) ) );

		if ( isset( $changes['label'] ) ) {
			$changes['label'] = sanitize_text_field( (string) $changes['label'] );
		}

		$problem = Subject_Tag_Editor::update( (int) $request->get_param( 'id' ), $changes );

		return null !== $problem ? self::problem( $problem ) : self::tree();
	}

	/**
	 * Moves a node one place up (direction -1) or down (1).
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public static function shift( $request ) {
		Subject_Tag_Editor::shift( (int) $request->get_param( 'id' ), (int) $request->get_param( 'direction' ) );

		return self::tree();
	}

	/**
	 * Removes a node, what's below it, and its tags from photos; also says how
	 * many tags were taken off photos.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public static function delete( $request ) {
		$removed = Subject_Tag_Editor::delete( (int) $request->get_param( 'id' ) );

		return new WP_REST_Response(
			array(
				'nodes'   => array_values( Subject_Tag_Tree::nodes() ),
				'removed' => $removed,
				'usage'   => (object) Subject_Tag_Tree::usage(),
			),
			200
		);
	}

	/**
	 * A 400 with a message for the page to show.
	 *
	 * @param string $message What's wrong.
	 *
	 * @return WP_Error
	 */
	private static function problem( $message ) {
		return new WP_Error( 'invalid', $message, array( 'status' => 400 ) );
	}
}
