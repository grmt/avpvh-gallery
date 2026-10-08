<?php
/**
 * Contains the Photo_Shares_Cleanup class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * Tidying the profile's share list (see Photo_Shares_Page): a share that
 * has ended (expired, removed, failed) can be taken off the list, one at a
 * time ("Uit lijst halen") or all at once ("Opruimen"). It's only hidden
 * (status "hidden"); its folder was already gone.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Shares_Cleanup {

	/**
	 * Statuses of shares that have ended.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	public const ENDED = array( 'expired', 'removed', 'failed' );

	/**
	 * The share list's HTML id, to come back to after a button.
	 */
	public const ANCHOR = 'avpvh-gallery-shares';

	/**
	 * Registers the two form handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_avpvh_gallery_share_hide', array( self::class, 'handle_hide' ) );
		add_action( 'admin_post_avpvh_gallery_share_tidy', array( self::class, 'handle_tidy' ) );
	}

	/**
	 * "Uit lijst halen": hides one of the user's ended shares.
	 *
	 * @return void
	 */
	public static function handle_hide() {
		check_admin_referer( 'avpvh_gallery_share_hide' );
		$share = Photo_Shares_DB::get( absint( $_POST['share'] ?? 0 ) );

		if (
			null !== $share
			&& get_current_user_id() === (int) $share->user_id
			&& in_array( $share->status, self::ENDED, true )
		) {
			Photo_Shares_DB::update( (int) $share->id, array( 'status' => 'hidden' ) );
		}

		self::back();
	}

	/**
	 * "Opruimen": hides all of the user's ended shares.
	 *
	 * @return void
	 */
	public static function handle_tidy() {
		check_admin_referer( 'avpvh_gallery_share_tidy' );
		Photo_Shares_DB::hide_ended( get_current_user_id() );
		self::back();
	}

	/**
	 * The "Opruimen" button.
	 *
	 * @return string
	 */
	public static function tidy_form() {
		return sprintf(
			' <form method="post" action="%s" style="display:inline">%s'
				. '<input type="hidden" name="action" value="avpvh_gallery_share_tidy">'
				. '<button type="submit" title="Verlopen, verwijderde en mislukte selecties uit de lijst halen">'
				. 'Opruimen</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'avpvh_gallery_share_tidy', '_wpnonce', true, false )
		);
	}

	/**
	 * Back to the profile, at the share list (not the top of the page).
	 * Also used by Photo_Shares_Page's buttons.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public static function back() {
		$back = wp_get_referer();
		$back = false === $back ? home_url() : strtok( $back, '#' );
		wp_safe_redirect( $back . '#' . self::ANCHOR );
		exit;
	}
}
