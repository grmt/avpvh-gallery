<?php
/**
 * Contains the Photo_Shares_Mail class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use DateTimeImmutable;
use stdClass;
use Throwable;

/**
 * The e-mail sent when a share is ready: its link, and the user's other
 * shares that are still open — each with its link, when it was made, how
 * long it stays available, and a link to remove it (see
 * Photo_Shares_Removal), so making room for a new one is easy.
 */
final class Photo_Shares_Mail {

	/**
	 * Seconds in a day.
	 */
	private const DAY = 86400;

	/**
	 * Style of a table cell in the e-mail.
	 */
	private const CELL = 'padding: 4px 12px 4px 0; border-bottom: 1px solid #ddd;';

	/**
	 * Mails a ready share's link to its recipient (as HTML, so the links
	 * read as words rather than long addresses).
	 *
	 * @param stdClass|null $share The share.
	 *
	 * @return void
	 */
	public static function send( $share ) {
		if ( ! $share instanceof stdClass ) {
			return;
		}

		$html = sprintf(
			'<p>Hallo,</p><p>Je fotoselectie <strong>%s</strong> (%d foto’s) staat klaar in Google Drive: %s</p>'
				. '<p>Alleen te openen met het Google-account %s, tot %s. Daarna wordt de map verwijderd; '
				. 'op je profiel kun je de selectie dan opnieuw laten maken. Eerder weg mag ook: %s.</p>',
			esc_html( '' === $share->description ? 'Fotoselectie' : $share->description ),
			(int) $share->photo_count,
			self::link( Photo_Shares::folder_url( (string) $share->drive_folder_id ), 'openen' ),
			esc_html( (string) $share->recipient ),
			esc_html( Photo_Shares::date( (string) $share->expires_at ) ),
			self::link( Photo_Shares_Removal::url( $share ), 'verwijderen' )
		);

		wp_mail(
			(string) $share->recipient,
			'Je fotoselectie staat klaar',
			'<html><body style="font-family: Arial, sans-serif; font-size: 14px;">' . $html . self::others( $share )
				. '</body></html>',
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * The user's other open shares as a table (none when there are none):
	 * name, when made, how long still available, and links.
	 *
	 * @param stdClass $share The share the mail is about.
	 *
	 * @return string
	 */
	private static function others( $share ) {
		Photo_Shares_Limit::sync( (int) $share->user_id );
		$others = array_filter(
			Photo_Shares_DB::for_user( (int) $share->user_id ),
			static function ( $other ) use ( $share ) {
				return 'ready' === $other->status && (int) $other->id !== (int) $share->id;
			}
		);

		if ( array() === $others ) {
			return '';
		}

		$rows = '';

		foreach ( $others as $other ) {
			$rows .= sprintf(
				'<tr><td style="%1$s">%2$s</td><td style="%1$s">%3$s</td><td style="%1$s">%4$s</td>'
					. '<td style="%1$s">%5$s · %6$s</td></tr>',
				self::CELL,
				esc_html( '' === $other->description ? 'Fotoselectie' : $other->description ),
				esc_html( Photo_Shares::date( (string) $other->created_at ) ),
				esc_html( self::remaining( (string) $other->expires_at ) ),
				self::link( Photo_Shares::folder_url( (string) $other->drive_folder_id ), 'openen' ),
				self::link( Photo_Shares_Removal::url( $other ), 'verwijderen' )
			);
		}

		return sprintf(
			'<p>Je andere selecties die nog open staan (je kunt er %d tegelijk hebben):</p>'
				. '<table style="border-collapse: collapse;"><tr><th style="%2$s">Selectie</th>'
				. '<th style="%2$s">Gemaakt</th><th style="%2$s">Nog</th><th style="%2$s"></th></tr>%3$s</table>',
			Photo_Shares::MAX_OPEN,
			self::CELL . ' text-align: left;',
			$rows
		);
	}

	/**
	 * A link.
	 *
	 * @param string $url  Where to.
	 * @param string $text Its text.
	 *
	 * @return string
	 */
	private static function link( $url, $text ) {
		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $text ) );
	}

	/**
	 * How long until a date: "3 dagen", "1 dag" or "minder dan een dag".
	 *
	 * @param string $mysql Local date and time.
	 *
	 * @return string
	 */
	private static function remaining( $mysql ) {
		try {
			$left = ( new DateTimeImmutable( $mysql, wp_timezone() ) )->getTimestamp() - time();
		} catch ( Throwable $e ) {
			// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- an unreadable date: say nothing precise.
			return 'even';
		}

		$days = (int) floor( $left / self::DAY );

		if ( 1 > $days ) {
			return 'minder dan een dag';
		}

		return 1 === $days ? '1 dag' : sprintf( '%d dagen', $days );
	}
}
