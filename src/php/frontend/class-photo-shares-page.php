<?php
/**
 * Contains the Photo_Shares_Page class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use stdClass;

/**
 * The user's shared photo selections on their profile ([avpvh_gallery_shares]):
 * each with its link and expiry, and "Opnieuw maken" for expired or failed
 * ones (see Photo_Shares).
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Shares_Page {

	/**
	 * How shares that ended without an error are shown.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const ENDED = array(
		'expired' => 'Verlopen',
		'removed' => 'Verwijderd',
	);

	/**
	 * Registers the shortcode and the "Opnieuw maken" form handler.
	 */
	public function __construct() {
		add_shortcode( 'avpvh_gallery_shares', array( self::class, 'shortcode' ) );
		add_action( 'admin_post_avpvh_gallery_share_recreate', array( self::class, 'handle_recreate' ) );
	}

	/**
	 * The [avpvh_gallery_shares] shortcode.
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		Photo_Shares_Limit::sync( get_current_user_id() );
		$shares = Photo_Shares_DB::for_user( get_current_user_id() );
		$html   = '<div class="avpvh-gallery-shares"><h3>Gedeelde fotoselecties</h3>';

		if ( array() === $shares ) {
			return $html . '<p>Je hebt nog geen foto’s gedeeld. '
				. 'Filter in de galerie en kies "Delen via Google Drive".</p></div>';
		}

		$html .= '<table><thead><tr><th>Selectie</th><th>Foto’s</th><th>Status</th>'
			. '<th>Beschikbaar tot</th></tr></thead><tbody>';

		foreach ( $shares as $share ) {
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( '' === $share->description ? '–' : $share->description ),
				esc_html( 0 < (int) $share->photo_count ? (string) $share->photo_count : '' ),
				self::status_html( $share ),
				esc_html( 'ready' === $share->status ? Photo_Shares::date( (string) $share->expires_at ) : '' )
			);
		}

		return $html . '</tbody></table></div>';
	}

	/**
	 * Makes an expired or failed share again, with the photos its filter
	 * finds now. admin-post form: share, _wpnonce.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public static function handle_recreate() {
		check_admin_referer( 'avpvh_gallery_share_recreate' );
		$share = Photo_Shares_DB::get( absint( $_POST['share'] ?? 0 ) );
		$back  = wp_get_referer();

		if ( null !== $share && self::may_recreate( $share ) ) {
			if ( 'ready' === $share->status ) {
				// Made again in place of the open one: its folder goes.
				Photo_Shares_Removal::close( $share );
			}

			Photo_Shares::restart( $share );
		}

		wp_safe_redirect( false === $back ? home_url() : $back );
		exit;
	}

	/**
	 * Whether the current user may make this share again: their own, over,
	 * and not too many open.
	 *
	 * @param stdClass $share The share.
	 *
	 * @return bool
	 */
	private static function may_recreate( $share ) {
		if ( get_current_user_id() !== (int) $share->user_id ) {
			return false;
		}

		// An open one is replaced, so it needs no room of its own.
		return 'ready' === $share->status
			|| ( in_array( $share->status, array( 'expired', 'failed', 'removed' ), true )
				&& Photo_Shares::MAX_OPEN > Photo_Shares_DB::open_count( get_current_user_id() ) );
	}

	/**
	 * A share's status cell: its link, progress, or what went wrong with a
	 * button to make it again.
	 *
	 * @param stdClass $share The share.
	 *
	 * @return string
	 */
	private static function status_html( $share ) {
		if ( 'ready' === $share->status ) {
			return sprintf(
				'<a href="%s" target="_blank" rel="noopener">Openen in Google Drive</a>',
				esc_url( Photo_Shares::folder_url( (string) $share->drive_folder_id ) )
			) . self::recreate_form( $share );
		}

		if ( 'pending' === $share->status ) {
			$done = (int) $share->photo_count;

			return 0 === $done
				? 'Wordt gemaakt… (je krijgt een e-mail)'
				: sprintf( 'Wordt gemaakt… (%d foto’s klaar; je krijgt een e-mail)', $done );
		}

		$text = self::ENDED[ $share->status ] ?? esc_html( (string) $share->error );

		return $text . self::recreate_form( $share );
	}

	/**
	 * The "Opnieuw maken" button: makes the share again with its saved
	 * filter and choices (for an open one, in place of it).
	 *
	 * @param stdClass $share The share.
	 *
	 * @return string
	 */
	private static function recreate_form( $share ) {
		return sprintf(
			' <form method="post" action="%s" style="display:inline">%s'
				. '<input type="hidden" name="action" value="avpvh_gallery_share_recreate">'
				. '<input type="hidden" name="share" value="%d">'
				. '<button type="submit">Opnieuw maken</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'avpvh_gallery_share_recreate', '_wpnonce', true, false ),
			(int) $share->id
		);
	}
}
