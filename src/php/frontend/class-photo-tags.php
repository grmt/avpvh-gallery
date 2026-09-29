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

use Avpvh\Tag_Log;

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

		$context = Photo_Folder_Context::for_folder( $folder_id );

		wp_send_json_success(
			array_merge(
				array(
					'members' => $context['participants'],
					'place'   => $context['place'],
				),
				Person_Filters::for_year( $context['year'] )
			)
		);
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified above via check_can_tag().
		$free_name = sanitize_text_field( wp_unslash( (string) ( $_POST['member_name'] ?? '' ) ) );

		if ( ! $image_id || ( ! $member_id && '' === $free_name ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters', 'avpvh-gallery' ) ), 400 );
		}

		$person = $member_id ? self::member_person( $member_id ) : self::free_text_person( $free_name );

		global $wpdb;
		$this->insert_or_error(
			$wpdb->prefix . 'agallery_photo_tags',
			array(
				'category'    => 'personen',
				'created_at'  => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
				'image_id'    => $image_id,
				'member_id'   => $person['member_id'],
				'member_name' => $person['member_name'],
				'region_data' => $region_data,
				'tag_key'     => $person['tag_key'],
			),
			// wpdb writes a null member_id (non-member tag) as NULL regardless of its %d format.
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ),
			esc_html__( 'Failed to create tag', 'avpvh-gallery' )
		);
		Tag_Log::record( $image_id, 'personen', $person['tag_key'], $person['member_name'], 'add' );

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
				"SELECT id, member_id, member_name, region_data, created_by, created_at FROM {$tags_table}
				 WHERE image_id = %s AND category = 'personen' ORDER BY created_at",
				$image_id
			)
		);

		$tagger_names   = Tag_Log::user_names( array_map( 'intval', array_column( $tags, 'created_by' ) ) );
		$tags_with_meta = array_map(
			static function ( $tag ) use ( $tagger_names ) {
				return array(
					'id'          => intval( $tag->id ),
					'member_id'   => intval( $tag->member_id ),
					'member_name' => $tag->member_name,
					'region_data' => $tag->region_data ? json_decode( $tag->region_data ) : null,
					'tagged_at'   => (string) $tag->created_at,
					'tagged_by'   => $tagger_names[ (int) $tag->created_by ] ?? '',
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
			$wpdb->prepare( "SELECT image_id, tag_key, member_name FROM {$table} WHERE id = %d", $tag_id )
		);

		if ( ! $tag ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Tag not found', 'avpvh-gallery' ) ), 404 );
		}

		// Anyone logged in may tag; only admins and "boek" members may remove
		// a tag (the same people who may exclude photos).
		if ( ! Exclusion_Permission::check() ) {
			wp_send_json_error( array( 'message' => 'Alleen boek-leden kunnen tags verwijderen' ), 403 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$wpdb->delete( $table, array( 'id' => $tag_id ), array( '%d' ) );
		Tag_Log::record(
			(string) $tag->image_id,
			'personen',
			(string) $tag->tag_key,
			(string) $tag->member_name,
			'remove'
		);

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table ($table is concatenated, not user-supplied); the placeholders are filled via $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT emoji, COUNT(*) as count, MAX(user_id = %d) as mine,
				        GROUP_CONCAT(user_id ORDER BY created_at) as user_ids
				 FROM {$table} WHERE image_id = %s GROUP BY emoji",
				$user_id,
				$image_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Who reacted, by display name — visible to logged-in users only, like
		// the rest of this (wp_ajax_-only) endpoint.
		$user_ids = array();

		foreach ( $rows as $row ) {
			$user_ids = array_merge( $user_ids, array_map( 'intval', explode( ',', (string) $row->user_ids ) ) );
		}

		$user_names = Tag_Log::user_names( $user_ids );
		$reactions  = array_map(
			static function ( $row ) use ( $user_names ) {
				return array(
					'count' => intval( $row->count ),
					'mine'  => (bool) intval( $row->mine ),
					'names' => array_values(
						array_filter(
							array_map(
								static function ( $user_id ) use ( $user_names ) {
									return $user_names[ (int) $user_id ] ?? '';
								},
								explode( ',', (string) $row->user_ids )
							)
						)
					),
					'slug'  => $row->emoji,
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
	 * Resolves a member ID to the tag row's person fields; ends the request
	 * with a 404 if there's no such member.
	 *
	 * @param int $member_id Member ID (avm_members.id).
	 *
	 * @return array{member_id: int|null, member_name: string, tag_key: string}
	 */
	private static function member_person( $member_id ) {
		global $wpdb;
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

		return array(
			'member_id'   => $member_id,
			'member_name' => $member->first_name . ' ' . $member->last_name,
			'tag_key'     => (string) $member_id,
		);
	}

	/**
	 * A person who isn't a member, tagged by name only. The tag key is the
	 * lowercased name, so the same person can't be tagged twice on a photo.
	 *
	 * @param string $name The typed name.
	 *
	 * @return array{member_id: int|null, member_name: string, tag_key: string}
	 */
	private static function free_text_person( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', $name ) ?? '' );

		return array(
			'member_id'   => null,
			'member_name' => $name,
			'tag_key'     => 'name:' . mb_strtolower( $name ),
		);
	}
}
