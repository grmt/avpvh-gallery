<?php
/**
 * Contains the Subject_Tags class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Fixed-vocabulary "what's in this photo" checklist tags (rubriek "graven"),
 * independent of who's in the photo. Each tag is a simple boolean per photo
 * — checked or not — so the REST API is a read (all active slugs for a
 * photo) and a single-tag toggle, matching the checkbox UI in the lightbox.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Subject_Tags {

	/**
	 * The fixed vocabulary: slug => Dutch label. Flat for now (rubriek
	 * "graven" is the only category); a category dimension can be added
	 * later without changing the storage shape (image_id, tag_slug).
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility, SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- no-modifier matches the convention used elsewhere (see Photo_Corrections_DB::SCHEMA_VERSION); the "multi constant" error is a PHPCSUtils false positive on this single constant's multi-line array value (confuses the array's "=>" pairs for constant separators).
	const TAGS = array(
		'archeoloog_maakt_foto' => 'Archeoloog maakt foto',
		'belletje'              => 'Belletje',
		'coupe'                 => 'Coupe',
		'fibula'                => 'Fibula',
		'hand_met_vondst'       => 'Hand met vondst',
		'intekenen'             => 'Intekenen',
		'kan'                   => 'Kan',
		'kinderen'              => 'Kinderen',
		'kruiwagen'             => 'Kruiwagen',
		'kwadrant'              => 'Kwadrant',
		'metaal'                => 'Metaal',
		'meten'                 => 'Meten',
		'munt'                  => 'Munt',
		'muur'                  => 'Muur',
		'opruimen'              => 'Opruimen',
		'overzicht'             => 'Overzicht',
		'paalgat'               => 'Paalgat',
		'pauze'                 => 'Pauze',
		'potje'                 => 'Potje',
		'profiel'               => 'Profiel',
		'reconstructie'         => 'Reconstructie',
		'regen'                 => 'Regen',
		'rondleiding'           => 'Rondleiding',
		'schaal'                => 'Schaal',
		'schaduw'               => 'Schaduw',
		'schaven'               => 'Schaven',
		'scherf'                => 'Scherf',
		'schop'                 => 'Schop',
		'troffel'               => 'Troffel',
		'vlak'                  => 'Vlak',
		'vondst'                => 'Vondst',
		'zeven'                 => 'Zeven',
	);

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
			'subject-tags',
			array(
				'args'                => array(
					'file_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => array( $this, 'get_tags' ),
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'avpvh-gallery/v1',
			'subject-tags',
			array(
				'args'                => array(
					'active'   => array(
						'required' => true,
						'type'     => 'boolean',
					),
					'file_id'  => array(
						'required' => true,
						'type'     => 'string',
					),
					'tag_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return isset( self::TAGS[ $value ] );
						},
					),
				),
				'callback'            => array( $this, 'toggle_tag' ),
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Returns the active tag slugs for a photo.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tags( $request ) {
		global $wpdb;
		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_subject_tags';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is concatenated (not user-supplied); %s below is a real placeholder.
				'SELECT tag_slug FROM ' . $table . ' WHERE image_id = %s',
				$file_id
			)
		);

		return new WP_REST_Response( array( 'tags' => is_array( $slugs ) ? $slugs : array() ), 200 );
	}

	/**
	 * Toggles a single tag on or off for a photo.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_tag( $request ) {
		global $wpdb;
		$file_id  = sanitize_text_field( (string) $request->get_param( 'file_id' ) );
		$tag_slug = sanitize_text_field( (string) $request->get_param( 'tag_slug' ) );
		$active   = (bool) $request->get_param( 'active' );

		if ( '' === $file_id ) {
			return new WP_Error( 'invalid_file', 'file_id is required', array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'agallery_photo_subject_tags';

		if ( $active ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->replace(
				$table,
				array(
					'created_at' => current_time( 'mysql' ),
					'created_by' => get_current_user_id(),
					'image_id'   => $file_id,
					'tag_slug'   => $tag_slug,
				),
				array( '%s', '%d', '%s', '%s' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->delete(
				$table,
				array(
					'image_id' => $file_id,
					'tag_slug' => $tag_slug,
				),
				array( '%s', '%s' )
			);
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}
