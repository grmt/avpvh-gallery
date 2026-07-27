<?php
/**
 * Contains the Photo_Tags class for handling photo annotations, comments, and reactions.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

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
	 * Initializes AJAX handlers
	 */
	public function __construct() {
		add_action( 'wp_ajax_gallery_tag_add', array( $this, 'ajax_add_tag' ) );
		add_action( 'wp_ajax_gallery_tag_list', array( $this, 'ajax_list_tags' ) );
		add_action( 'wp_ajax_gallery_tag_delete', array( $this, 'ajax_delete_tag' ) );
		add_action( 'wp_ajax_gallery_comment_add', array( $this, 'ajax_add_comment' ) );
		add_action( 'wp_ajax_gallery_reaction_add', array( $this, 'ajax_add_reaction' ) );
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
				'created_at'  => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
				'image_id'    => $image_id,
				'member_id'   => $member_id,
				'member_name' => $member_name,
				'region_data' => $region_data,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' ),
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
		$tags_table      = $wpdb->prefix . 'agallery_photo_tags';
		$comments_table  = $wpdb->prefix . 'agallery_tag_comments';
		$reactions_table = $wpdb->prefix . 'agallery_reactions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$tags = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $tags_table is concatenated (not user-supplied); the %s placeholder below is filled via $wpdb->prepare().
				"SELECT id, member_id, member_name, region_data FROM {$tags_table}
				 WHERE image_id = %s ORDER BY created_at",
				$image_id
			)
		);

		$tags_with_meta = array_map(
			static function ( $tag ) use ( $wpdb, $comments_table, $reactions_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
				$comments = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $comments_table is concatenated (not user-supplied); the %d placeholder below is filled via $wpdb->prepare().
						"SELECT id, user_id, comment_text, created_at FROM {$comments_table}
						 WHERE tag_id = %d ORDER BY created_at",
						$tag->id
					)
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
				$reactions = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $reactions_table is concatenated (not user-supplied); the %d placeholder below is filled via $wpdb->prepare().
						"SELECT emoji, COUNT(*) as count FROM {$reactions_table}
						 WHERE tag_id = %d GROUP BY emoji",
						$tag->id
					)
				);

				return array(
					'id'          => intval( $tag->id ),
					'member_id'   => intval( $tag->member_id ),
					'member_name' => $tag->member_name,
					'region_data' => $tag->region_data ? json_decode( $tag->region_data ) : null,
					'comments'    => array_map(
						static function ( $comment ) {
							return array(
								'id'         => intval( $comment->id ),
								'user_id'    => intval( $comment->user_id ),
								'text'       => $comment->comment_text,
								'created_at' => $comment->created_at,
							);
						},
						$comments
					),
					'reactions'   => array_map(
						static function ( $reaction ) {
							return array(
								'emoji' => $reaction->emoji,
								'count' => intval( $reaction->count ),
							);
						},
						$reactions
					),
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
	 * AJAX handler: Add a comment to a tag
	 *
	 * @return void
	 */
	public function ajax_add_comment() {
		$this->check_can_tag();

		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$tag_id = intval( $_POST['tag_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$comment_text = sanitize_textarea_field( wp_unslash( (string) ( $_POST['comment'] ?? '' ) ) );

		if ( ! $tag_id || ! $comment_text ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		$comment_id = $this->insert_or_error(
			$wpdb->prefix . 'agallery_tag_comments',
			array(
				'comment_text' => $comment_text,
				'created_at'   => current_time( 'mysql' ),
				'tag_id'       => $tag_id,
				'user_id'      => get_current_user_id(),
			),
			array( '%s', '%s', '%d', '%d' ),
			esc_html__( 'Failed to create comment', 'avpvh-gallery' )
		);

		wp_send_json_success( array( 'comment_id' => $comment_id ) );
	}

	/**
	 * AJAX handler: Add an emoji reaction to a tag
	 *
	 * @return void
	 */
	public function ajax_add_reaction() {
		$this->check_can_tag();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$tag_id = intval( $_POST['tag_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$emoji = sanitize_text_field( wp_unslash( (string) ( $_POST['emoji'] ?? '' ) ) );

		if ( ! $tag_id || ! $emoji ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'agallery_reactions';
		$user_id = get_current_user_id();

		// Toggle reaction: remove if exists, add if doesn't.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		if ( $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is concatenated (not user-supplied); the placeholders below are filled via $wpdb->prepare().
				"SELECT id FROM {$table} WHERE tag_id = %d AND user_id = %d AND emoji = %s",
				$tag_id,
				$user_id,
				$emoji
			)
		) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, no cache group defined.
			$wpdb->delete(
				$table,
				array(
					'emoji'   => $emoji,
					'tag_id'  => $tag_id,
					'user_id' => $user_id,
				),
				array( '%s', '%d', '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, no cache group defined.
			$wpdb->insert(
				$table,
				array(
					'created_at' => current_time( 'mysql' ),
					'emoji'      => $emoji,
					'tag_id'     => $tag_id,
					'user_id'    => $user_id,
				),
				array( '%s', '%s', '%d', '%d' )
			);
		}

		wp_send_json_success();
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
}
