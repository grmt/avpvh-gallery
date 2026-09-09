<?php
/**
 * Contains the Photo_Tags class for handling photo annotations, comments, and reactions.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Handles photo tagging, comments, and reactions via AJAX.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Tags {

	/**
	 * The fixed set of reactions a photo can get, grouped by what they
	 * judge — liking the subject/content is a different axis from flagging
	 * its technical quality or a content concern, so they're independent
	 * groups rather than one flat list. Deliberately not open emoji:
	 * restricting it to this small, specific vocabulary is what makes
	 * counting them ("how many people flagged this as blurry") meaningful.
	 * group => (slug => a display label including its icon).
	 *
	 * The "zorgen" group is different from the other two: a reaction there
	 * doesn't just get counted, it also surfaces the photo on the
	 * "Gevlagde foto's" admin page (Avpvh\Admin\Settings_Pages\Flagged_Photos)
	 * so an administrator can review it.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility, SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- no-modifier matches the convention used elsewhere (see Photo_Corrections_DB::SCHEMA_VERSION); the "multi constant" error is a PHPCSUtils false positive on this single constant's multi-line array value.
	const REACTIONS = array(
		'kwaliteit' => array(
			'blurry' => '🔍 Niet scherp',
			'goodq'  => '✅ Goede kwaliteit',
			'shaky'  => '📸 Bewogen',
		),
		'subject'   => array(
			'like' => '👍 Leuke foto',
		),
		'zorgen'    => array(
			'hide_request' => '🙈 Verzoek om te verbergen',
			'privacy'      => '⚠️ Ongemakkelijk / AVG-issue / kinderen',
		),
	);

	/**
	 * The reaction slugs (within the "zorgen" group) that flag a photo for
	 * admin review — see the REACTIONS docblock above.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility, SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- no-modifier matches the convention used elsewhere (see Photo_Corrections_DB::SCHEMA_VERSION); the "multi constant" error is a PHPCSUtils false positive triggered by the adjacent REACTIONS constant's multi-line array value.
	const FLAGGED_REACTIONS = array( 'hide_request', 'privacy' );

	/**
	 * Initializes AJAX handlers
	 */
	public function __construct() {
		add_action( 'wp_ajax_gallery_tag_add', array( $this, 'ajax_add_tag' ) );
		add_action( 'wp_ajax_gallery_tag_list', array( $this, 'ajax_list_tags' ) );
		add_action( 'wp_ajax_gallery_tag_delete', array( $this, 'ajax_delete_tag' ) );
		add_action( 'wp_ajax_gallery_comment_add', array( $this, 'ajax_add_comment' ) );
		add_action( 'wp_ajax_gallery_comment_list', array( $this, 'ajax_list_comments' ) );
		add_action( 'wp_ajax_gallery_reaction_add', array( $this, 'ajax_add_reaction' ) );
		add_action( 'wp_ajax_gallery_reaction_list', array( $this, 'ajax_list_reactions' ) );
		add_action( 'wp_ajax_gallery_tag_candidates', array( $this, 'ajax_tag_candidates' ) );
	}

	/**
	 * AJAX handler: suggests members to tag for a photo, narrowed to the
	 * participants of the activity matching the photo's folder (if any is
	 * marked gallery_taggable in avpvh-members) — falls back to an empty
	 * list (the client then falls back to the full membership list) when
	 * there's no match.
	 *
	 * @return void
	 */
	public function ajax_tag_candidates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup, no state change to protect with a nonce.
		$folder_id = sanitize_text_field( wp_unslash( (string) ( $_GET['folder_id'] ?? '' ) ) );

		if ( ! $folder_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid folder ID', 'avpvh-gallery' ) ), 400 );
		}

		wp_send_json_success( array( 'members' => self::participants_for_folder( $folder_id ) ) );
	}

	/**
	 * AJAX handler: Add a tag to a photo
	 *
	 * @return void
	 */
	public function ajax_add_tag() {
		$this->check_can_tag();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$image_id = sanitize_text_field( wp_unslash( (string) ( $_POST['image_id'] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$member_id = intval( $_POST['member_id'] ?? 0 );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$region_data = isset( $_POST['region_data'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['region_data'] ) )
			: null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $image_id || ! $member_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_tags';

		// Get member name from avpvh_members table via LLDAP.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, first_name, last_name FROM {$wpdb->prefix}avm_members WHERE id = %d",
				$member_id
			)
		);

		if ( ! $member ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Member not found', 'avpvh-gallery' ) ), 404 );
		}

		$member_name = $member->first_name . ' ' . $member->last_name;

		$this->insert_or_error(
			$table,
			array(
				'category'    => 'personen',
				'created_at'  => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
				'image_id'    => $image_id,
				'member_id'   => $member_id,
				'member_name' => $member_name,
				'region_data' => $region_data,
				'tag_key'     => (string) $member_id,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ),
			esc_html__( 'Failed to create tag', 'avpvh-gallery' )
		);

		// Sync to Google Drive (non-blocking).
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking'  => false,
				'body'      => array(
					'action'      => 'gallery_sync_tags_to_drive',
					'image_id'    => $image_id,
					'_ajax_nonce' => wp_create_nonce( 'avpvh_sync_nonce' ),
				),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- shared hook name also used (and suppressed the same way) in class-exif-data-rest.php and class-media-stream-rest.php; renaming would be a breaking change for sites already hooked into it.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		wp_send_json_success( array( 'tag_id' => $wpdb->insert_id ) );
	}

	/**
	 * AJAX handler: List all tags for an image
	 *
	 * @return void
	 */
	public function ajax_list_tags() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list endpoint, no state change to protect with a nonce.
		$image_id = sanitize_text_field( wp_unslash( (string) ( $_GET['image_id'] ?? '' ) ) );

		if ( ! $image_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid image ID', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$tags_table = $wpdb->prefix . 'agallery_photo_tags';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$tags = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $tags_table is concatenated (not user-supplied); the %s placeholder below is filled via $wpdb->prepare().
				"SELECT id, member_id, member_name, region_data FROM {$tags_table}
				 WHERE image_id = %s AND category = 'personen' ORDER BY created_at",
				$image_id
			)
		);

		$tags_with_meta = array_map(
			static function ( $tag ) {
				return array(
					'id'          => intval( $tag->id ),
					'member_id'   => intval( $tag->member_id ),
					'member_name' => $tag->member_name,
					'region_data' => $tag->region_data ? json_decode( $tag->region_data ) : null,
				);
			},
			$tags
		);

		wp_send_json_success( array( 'tags' => $tags_with_meta ) );
	}

	/**
	 * AJAX handler: Delete a tag
	 *
	 * @return void
	 */
	public function ajax_delete_tag() {
		$this->check_can_tag();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$tag_id = intval( $_POST['tag_id'] ?? 0 );

		if ( ! $tag_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid tag ID', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_tags';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$tag = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is concatenated (not user-supplied); the %d placeholder above is filled via $wpdb->prepare().
			$wpdb->prepare( "SELECT image_id, created_by FROM {$table} WHERE id = %d", $tag_id )
		);

		if ( ! $tag ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Tag not found', 'avpvh-gallery' ) ), 404 );
		}

		// Check permission: only creator can delete.
		if ( intval( $tag->created_by ) !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized', 'avpvh-gallery' ) ), 403 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$wpdb->delete( $table, array( 'id' => $tag_id ), array( '%d' ) );

		wp_send_json_success();
	}

	/**
	 * AJAX handler: Add a comment to a photo. Comments belong to the photo
	 * as a whole, not to any one tag on it.
	 *
	 * @return void
	 */
	public function ajax_add_comment() {
		$this->check_can_tag();

		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$image_id = sanitize_text_field( wp_unslash( (string) ( $_POST['image_id'] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$comment_text = sanitize_textarea_field( wp_unslash( (string) ( $_POST['comment'] ?? '' ) ) );

		if ( ! $image_id || ! $comment_text ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		$comment_id = $this->insert_or_error(
			$wpdb->prefix . 'agallery_photo_comments',
			array(
				'comment_text' => $comment_text,
				'created_at'   => current_time( 'mysql' ),
				'image_id'     => $image_id,
				'user_id'      => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%d' ),
			esc_html__( 'Failed to create comment', 'avpvh-gallery' )
		);

		wp_send_json_success( array( 'comment_id' => $comment_id ) );
	}

	/**
	 * AJAX handler: Add an emoji reaction to a photo. Reactions belong to
	 * the photo as a whole, not to any one tag on it.
	 *
	 * @return void
	 */
	public function ajax_add_reaction() {
		$this->check_can_tag();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$image_id = sanitize_text_field( wp_unslash( (string) ( $_POST['image_id'] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$emoji = sanitize_text_field( wp_unslash( (string) ( $_POST['emoji'] ?? '' ) ) );

		if ( ! $image_id || ! isset( self::all_reactions()[ $emoji ] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'agallery_photo_reactions';
		$user_id = get_current_user_id();

		// Toggle reaction: remove if exists, add if doesn't.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		if ( $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is concatenated (not user-supplied); the placeholders below are filled via $wpdb->prepare().
				"SELECT id FROM {$table} WHERE image_id = %s AND user_id = %d AND emoji = %s",
				$image_id,
				$user_id,
				$emoji
			)
		) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, no cache group defined.
			$wpdb->delete(
				$table,
				array(
					'emoji'    => $emoji,
					'image_id' => $image_id,
					'user_id'  => $user_id,
				),
				array( '%s', '%s', '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, no cache group defined.
			$wpdb->insert(
				$table,
				array(
					'created_at' => current_time( 'mysql' ),
					'emoji'      => $emoji,
					'image_id'   => $image_id,
					'user_id'    => $user_id,
				),
				array( '%s', '%s', '%s', '%d' )
			);
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler: lists comments for a photo.
	 *
	 * @return void
	 */
	public function ajax_list_comments() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list endpoint, no state change to protect with a nonce.
		$image_id = sanitize_text_field( wp_unslash( (string) ( $_GET['image_id'] ?? '' ) ) );

		if ( ! $image_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid image ID', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_comments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is concatenated (not user-supplied); the %s placeholder below is filled via $wpdb->prepare().
				"SELECT id, user_id, comment_text, created_at FROM {$table} WHERE image_id = %s ORDER BY created_at",
				$image_id
			)
		);

		$comments = array_map(
			static function ( $row ) {
				$user = get_userdata( (int) $row->user_id );

				return array(
					'id'         => intval( $row->id ),
					'user_name'  => $user ? $user->display_name : esc_html__( 'Onbekend', 'avpvh-gallery' ),
					'text'       => $row->comment_text,
					'created_at' => $row->created_at,
				);
			},
			$rows
		);

		wp_send_json_success( array( 'comments' => $comments ) );
	}

	/**
	 * AJAX handler: lists reaction counts for a photo, and which of them the
	 * current user has given.
	 *
	 * @return void
	 */
	public function ajax_list_reactions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list endpoint, no state change to protect with a nonce.
		$image_id = sanitize_text_field( wp_unslash( (string) ( $_GET['image_id'] ?? '' ) ) );

		if ( ! $image_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid image ID', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'agallery_photo_reactions';
		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is concatenated (not user-supplied); the placeholders below are filled via $wpdb->prepare().
				"SELECT emoji, COUNT(*) as count, MAX(user_id = %d) as mine FROM {$table}
				 WHERE image_id = %s GROUP BY emoji",
				$user_id,
				$image_id
			)
		);

		$reactions = array_map(
			static function ( $row ) {
				return array(
					'slug'  => $row->emoji,
					'count' => intval( $row->count ),
					'mine'  => (bool) intval( $row->mine ),
				);
			},
			$rows
		);

		wp_send_json_success( array( 'reactions' => $reactions ) );
	}

	/**
	 * Check if user can tag photos and verify nonce
	 *
	 * @return void
	 */
	private function check_can_tag() {
		check_ajax_referer( 'avpvh_tag_nonce' );
	}

	/**
	 * Insert data or send error response
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data Data to insert.
	 * @param array<int, string>   $formats Format specifiers.
	 * @param string               $error_msg Error message on failure.
	 * @return int Insert ID on success.
	 */
	private function insert_or_error( $table, array $data, array $formats, $error_msg ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, no cache group defined.
		$wpdb->insert( $table, $data, $formats );

		if ( ! $wpdb->insert_id ) {
			wp_send_json_error( array( 'message' => esc_html( $error_msg ) ), 500 );
		}

		return $wpdb->insert_id;
	}

	/**
	 * All reactions across both groups, flattened to slug => label — used
	 * to validate a submitted reaction slug without caring which group it's in.
	 *
	 * @return array<string, string>
	 */
	private static function all_reactions() {
		$all = array();

		foreach ( self::REACTIONS as $group ) {
			$all = array_merge( $all, $group );
		}

		return $all;
	}

	/**
	 * Best-effort: resolves the folder's Drive name and looks up its matching
	 * activity's participants. Never fails the caller.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 *
	 * @return array<array{id: int, name: string}>
	 */
	private static function participants_for_folder( $folder_id ) {
		try {
			$results     = API_Client::execute( array( API_Facade::get_file_name( $folder_id ) ) );
			$folder_name = is_string( $results[0] ) ? $results[0] : '';

			return '' !== $folder_name ? Activity_Participants::for_folder_name( $folder_name ) : array();
		} catch ( Throwable $e ) {
			return array();
		}
	}
}
