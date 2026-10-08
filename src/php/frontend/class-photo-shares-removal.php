<?php
/**
 * Contains the Photo_Shares_Removal class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use stdClass;
use Throwable;

/**
 * Removing a share before it expires, from the link in the e-mail (see
 * Photo_Shares_Mail). The link carries a token only the e-mail knows, so it
 * works without logging in; it opens a page asking to confirm (mail
 * programs and virus scanners may open links by themselves), and only the
 * button there removes the share: its folder is deleted (fine if it is
 * already gone in Drive) and it shows as "Verwijderd" on the profile.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Shares_Removal {

	/**
	 * The admin-post action.
	 */
	private const ACTION = 'avpvh_gallery_share_remove';

	/**
	 * The HTML the confirmation page may contain.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const FORM_HTML = array(
		'button' => array(
			'class' => true,
			'type'  => true,
		),
		'form'   => array(
			'action' => true,
			'method' => true,
		),
		'input'  => array(
			'name'  => true,
			'type'  => true,
			'value' => true,
		),
		'p'      => array(),
		'strong' => array(),
	);

	/**
	 * Registers the confirmation page and the removal.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The link that removes a share.
	 *
	 * @param stdClass $share The share.
	 *
	 * @return string
	 */
	public static function url( $share ) {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'share'  => (int) $share->id,
				'token'  => self::token( $share ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Removes a share: deletes its folder if it's still there and marks it
	 * removed. Also used to make room for a new share (Photo_Shares_Limit).
	 *
	 * @param stdClass $share The share.
	 *
	 * @return void
	 */
	public static function close( $share ) {
		if ( '' !== (string) $share->drive_folder_id ) {
			try {
				Share_Drive::remove( (string) $share->drive_folder_id );
			} catch ( Throwable $e ) {
				// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- already gone: the share is over either way.
				unset( $e );
			}
		}

		Photo_Shares_DB::update( (int) $share->id, array( 'status' => 'removed' ) );
	}

	/**
	 * GET: asks to confirm; POST: removes the share.
	 *
	 * @return void
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- the token from the e-mail stands in for a nonce.
		$share = Photo_Shares_DB::get( absint( $_REQUEST['share'] ?? 0 ) );
		$token = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['token'] ?? '' ) ) );
		$sure  = isset( $_POST['token'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		if ( ! $share instanceof stdClass || ! hash_equals( self::token( $share ), $token ) ) {
			wp_die( 'Deze link is ongeldig.', 'Deling verwijderen', array( 'response' => 403 ) );
		}

		if ( ! in_array( $share->status, array( 'pending', 'ready' ), true ) ) {
			wp_die( 'Deze deling is al verwijderd of verlopen.', 'Deling verwijderen' );
		}

		if ( ! $sure ) {
			wp_die( wp_kses( self::confirmation( $share, $token ), self::FORM_HTML ), 'Deling verwijderen' );
		}

		self::close( $share );
		wp_die(
			'De deling is verwijderd. Op je profiel kun je hem later opnieuw laten maken.',
			'Deling verwijderd'
		);
	}

	/**
	 * The confirmation page: what will be removed, and the button.
	 *
	 * @param stdClass $share The share.
	 * @param string   $token Its token.
	 *
	 * @return string
	 */
	private static function confirmation( $share, $token ) {
		return sprintf(
			'<p>Deling verwijderen: <strong>%s</strong> (gemaakt %s)?</p>'
				. '<p>De map in Google Drive wordt verwijderd.</p>'
				. '<form method="post" action="%s">'
				. '<input type="hidden" name="action" value="%s">'
				. '<input type="hidden" name="share" value="%d">'
				. '<input type="hidden" name="token" value="%s">'
				. '<button type="submit" class="button">Verwijderen</button></form>',
			esc_html( '' === $share->description ? 'Fotoselectie' : $share->description ),
			esc_html( Photo_Shares::date( (string) $share->created_at ) ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION ),
			(int) $share->id,
			esc_attr( $token )
		);
	}

	/**
	 * A share's secret token, for links in e-mails.
	 *
	 * @param stdClass $share The share.
	 *
	 * @return string
	 */
	private static function token( $share ) {
		return substr(
			hash_hmac( 'sha256', 'share-remove|' . $share->id . '|' . $share->created_at, wp_salt( 'auth' ) ),
			0,
			32
		);
	}
}
