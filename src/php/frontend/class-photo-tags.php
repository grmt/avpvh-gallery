<?php
/**
 * Contains the Photo_Tags class for handling photo annotations, comments, and reactions.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

use function Avpvh\Helpers\Get_Helpers\get_option;

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
	 * @param string $table Table name.
	 * @param array  $data Data to insert.
	 * @param array  $formats Format specifiers.
	 * @param string $error_msg Error message on failure.
	 * @return int Insert ID on success.
	 */
	private function insert_or_error( $table, array $data, array $formats, $error_msg ) {
		global $wpdb;
		$wpdb->insert( $table, $data, $formats );
		if ( ! $wpdb->insert_id ) {
			wp_send_json_error( array( 'message' => esc_html( $error_msg ) ), 500 );
		}
		return $wpdb->insert_id;
	}

	/**
	 * AJAX handler: Add a tag to a photo
	 *
	 * @return void
	 */
	public function ajax_add_tag() {
		$this->check_can_tag();

		$image_id = sanitize_text_field( $_POST['image_id'] ?? '' );
		$member_id = intval( $_POST['member_id'] ?? 0 );
		$region_data = isset( $_POST['region_data'] ) ? sanitize_text_field( $_POST['region_data'] ) : null;

		if ( ! $image_id || ! $member_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_tags';

		// Get member name from avpvh_members table via LLDAP
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
				'image_id' => $image_id,
				'member_id' => $member_id,
				'member_name' => $member_name,
				'region_data' => $region_data,
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s' ),
			esc_html__( 'Failed to create tag', 'avpvh-gallery' )
		);

		// Sync to Google Drive (non-blocking)
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking' => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body' => array(
					'action' => 'gallery_sync_tags_to_drive',
					'image_id' => $image_id,
					'_ajax_nonce' => wp_create_nonce( 'avpvh_sync_nonce' ),
				),
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
		$image_id = sanitize_text_field( $_GET['image_id'] ?? '' );

		if ( ! $image_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid image ID', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$tags_table = $wpdb->prefix . 'agallery_photo_tags';
		$comments_table = $wpdb->prefix . 'agallery_tag_comments';
		$reactions_table = $wpdb->prefix . 'agallery_reactions';

		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, member_id, member_name, region_data FROM {$tags_table}
				 WHERE image_id = %s ORDER BY created_at",
				$image_id
			)
		);

		$tags_with_meta = array_map(
			function( $tag ) use ( $wpdb, $comments_table, $reactions_table ) {
				$comments = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, user_id, comment_text, created_at FROM {$comments_table}
						 WHERE tag_id = %d ORDER BY created_at",
						$tag->id
					)
				);

				$reactions = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT emoji, COUNT(*) as count FROM {$reactions_table}
						 WHERE tag_id = %d GROUP BY emoji",
						$tag->id
					)
				);

				return array(
					'id' => intval( $tag->id ),
					'member_id' => intval( $tag->member_id ),
					'member_name' => $tag->member_name,
					'region_data' => $tag->region_data ? json_decode( $tag->region_data ) : null,
					'comments' => array_map(
						function( $c ) {
							return array(
								'id' => intval( $c->id ),
								'user_id' => intval( $c->user_id ),
								'text' => $c->comment_text,
								'created_at' => $c->created_at,
							);
						},
						$comments
					),
					'reactions' => array_map(
						function( $r ) {
							return array(
								'emoji' => $r->emoji,
								'count' => intval( $r->count ),
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

		$tag_id = intval( $_POST['tag_id'] ?? 0 );

		if ( ! $tag_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid tag ID', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_tags';

		$tag = $wpdb->get_row(
			$wpdb->prepare( "SELECT image_id, created_by FROM {$table} WHERE id = %d", $tag_id )
		);

		if ( ! $tag ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Tag not found', 'avpvh-gallery' ) ), 404 );
		}

		// Check permission: only creator can delete
		if ( intval( $tag->created_by ) !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized', 'avpvh-gallery' ) ), 403 );
		}

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

		$tag_id = intval( $_POST['tag_id'] ?? 0 );
		$comment_text = sanitize_textarea_field( $_POST['comment'] ?? '' );

		if ( ! $tag_id || ! $comment_text ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		$comment_id = $this->insert_or_error(
			$wpdb->prefix . 'agallery_tag_comments',
			array(
				'tag_id' => $tag_id,
				'user_id' => get_current_user_id(),
				'comment_text' => $comment_text,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' ),
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

		$tag_id = intval( $_POST['tag_id'] ?? 0 );
		$emoji = sanitize_text_field( $_POST['emoji'] ?? '' );

		if ( ! $tag_id || ! $emoji ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agallery_reactions';
		$user_id = get_current_user_id();

		// Toggle reaction: remove if exists, add if doesn't
		if ( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE tag_id = %d AND user_id = %d AND emoji = %s",
				$tag_id,
				$user_id,
				$emoji
			)
		) ) {
			$wpdb->delete(
				$table,
				array( 'tag_id' => $tag_id, 'user_id' => $user_id, 'emoji' => $emoji ),
				array( '%d', '%d', '%s' )
			);
		} else {
			$wpdb->insert(
				$table,
				array( 'tag_id' => $tag_id, 'user_id' => $user_id, 'emoji' => $emoji, 'created_at' => current_time( 'mysql' ) ),
				array( '%d', '%d', '%s', '%s' )
			);
		}

		wp_send_json_success();
	}
}
