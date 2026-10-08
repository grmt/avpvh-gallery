<?php
/**
 * Contains the Filter_Sharing class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use AVPVH_Directory;

/**
 * Sharing a named filter (see Filter_Memory) with other members and with
 * directory groups (avpvh-members: bestuur, boek, leden …). The owner keeps
 * whom it's shared with on the filter itself ("users", "groups"); everyone
 * it's shared with sees it under "Gedeeld met mij" and may apply it, but
 * only the owner can change or delete it.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Filter_Sharing {

	/**
	 * Where the directory's group names are cached.
	 */
	private const GROUPS_KEY = 'avpvh_gallery_directory_groups';

	/**
	 * Registers the AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_gallery_filter_share', array( self::class, 'handle_share' ) );
		add_action( 'wp_ajax_gallery_filter_recipients', array( self::class, 'handle_recipients' ) );
	}

	/**
	 * Whom a stored filter is shared with, sanitized.
	 *
	 * @param mixed $preset A stored filter.
	 *
	 * @return array{users: array<int>, groups: array<string>}
	 */
	public static function audience( $preset ) {
		$users  = is_array( $preset ) && is_array( $preset['users'] ?? null ) ? $preset['users'] : array();
		$groups = is_array( $preset ) && is_array( $preset['groups'] ?? null ) ? $preset['groups'] : array();

		$groups = array_filter( array_map( 'sanitize_key', array_slice( $groups, 0, 50 ) ) );
		$users  = array_filter( array_map( 'absint', array_slice( $users, 0, 200 ) ) );

		return array(
			'groups' => array_values( array_unique( $groups ) ),
			'users'  => array_values( array_unique( $users ) ),
		);
	}

	/**
	 * The "gallery_filter_share" endpoint. POST: id (one of the user's named
	 * filters), users (JSON list of user IDs), groups (JSON list of group
	 * names). Returns the user's filters.
	 *
	 * @return void
	 */
	public static function handle_share() {
		check_ajax_referer( 'avpvh_tag_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; sanitized by audience().
		$filter_id = sanitize_key( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) );
		$audience  = self::audience(
			array(
				'groups' => json_decode( wp_unslash( (string) ( $_POST['groups'] ?? '[]' ) ), true ),
				'users'  => json_decode( wp_unslash( (string) ( $_POST['users'] ?? '[]' ) ), true ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$presets = Filter_Memory::saved_filters();
		$found   = false;

		foreach ( $presets as $index => $preset ) {
			if ( $preset['id'] !== $filter_id ) {
				continue;
			}

			$presets[ $index ] = array_merge( $preset, $audience );
			$found             = true;
		}

		if ( ! $found ) {
			wp_send_json_error( array( 'message' => 'Dit filter bestaat niet (meer)' ), 404 );
		}

		update_user_meta( get_current_user_id(), Filter_Memory::PRESETS_KEY, $presets );
		wp_send_json_success( array( 'filters' => $presets ) );
	}

	/**
	 * The "gallery_filter_recipients" endpoint: whom a filter can be shared
	 * with — the other users by name, and the directory's groups.
	 *
	 * @return void
	 */
	public static function handle_recipients() {
		$users = array();

		foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) {
			if ( get_current_user_id() === (int) $user->ID ) {
				continue;
			}

			$users[] = array(
				'id'   => (int) $user->ID,
				'name' => (string) $user->display_name,
			);
		}

		wp_send_json_success(
			array(
				'groups' => self::groups(),
				'users'  => $users,
			)
		);
	}

	/**
	 * Other users' named filters shared with the current user (directly or
	 * through one of their groups), with the owner's name; whom else they
	 * are shared with is left out.
	 *
	 * @return array<array<string, mixed>>
	 */
	public static function shared_with_me() {
		$viewer = get_current_user_id();

		if ( 0 === $viewer ) {
			return array();
		}

		$my_groups = Exclusion_Permission::current_groups();
		$shared    = array();
		$owners    = get_users(
			array(
				'exclude'  => array( $viewer ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only the few users with named filters.
				'meta_key' => Filter_Memory::PRESETS_KEY,
			)
		);

		foreach ( $owners as $owner ) {
			foreach ( Filter_Memory::presets_of( (int) $owner->ID ) as $preset ) {
				if (
					! in_array( $viewer, $preset['users'], true )
					&& array() === array_intersect( $my_groups, $preset['groups'] )
				) {
					continue;
				}

				$shared[] = array_merge(
					$preset,
					array(
						'groups' => array(),
						'id'     => 'shared-' . $owner->ID . '-' . $preset['id'],
						'owner'  => (string) $owner->display_name,
						'users'  => array(),
					)
				);
			}
		}

		return $shared;
	}

	/**
	 * The directory's group names, lower-cased (cached for an hour; none
	 * without avpvh-members).
	 *
	 * @return array<string>
	 */
	private static function groups() {
		$cached = get_transient( self::GROUPS_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$groups = array();

		if ( class_exists( AVPVH_Directory::class ) ) {
			$listed = AVPVH_Directory::list_groups();

			foreach ( is_array( $listed ) ? $listed : array() as $group ) {
				$name     = is_array( $group ) ? (string) ( $group['displayName'] ?? '' ) : (string) $group;
				$groups[] = strtolower( $name );
			}
		}

		$groups = array_values( array_filter( array_unique( $groups ) ) );
		sort( $groups );
		set_transient( self::GROUPS_KEY, $groups, HOUR_IN_SECONDS );

		return $groups;
	}
}
