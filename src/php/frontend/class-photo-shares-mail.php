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
	 * Mails a ready share's link to its recipient.
	 *
	 * @param stdClass|null $share The share.
	 *
	 * @return void
	 */
	public static function send( $share ) {
		if ( ! $share instanceof stdClass ) {
			return;
		}

		$lines = array(
			'Hallo,',
			'',
			sprintf( 'Je fotoselectie (%d foto’s) staat klaar in Google Drive:', (int) $share->photo_count ),
			Photo_Shares::folder_url( (string) $share->drive_folder_id ),
			'',
			'Filter: ' . ( '' === $share->description ? '–' : $share->description ),
			sprintf(
				'Alleen te openen met het Google-account %s, tot %s.',
				$share->recipient,
				Photo_Shares::date( (string) $share->expires_at )
			),
			'Daarna wordt de map verwijderd; op je profiel kun je de selectie dan opnieuw laten maken.',
			'Eerder weg mag ook: ' . Photo_Shares_Removal::url( $share ),
		);

		wp_mail(
			(string) $share->recipient,
			'Je fotoselectie staat klaar',
			implode( "\n", array_merge( $lines, self::others( $share ) ) )
		);
	}

	/**
	 * The lines about the user's other open shares (none when there are
	 * none).
	 *
	 * @param stdClass $share The share the mail is about.
	 *
	 * @return array<string>
	 */
	private static function others( $share ) {
		$others = array_filter(
			Photo_Shares_DB::for_user( (int) $share->user_id ),
			static function ( $other ) use ( $share ) {
				return 'ready' === $other->status && (int) $other->id !== (int) $share->id;
			}
		);

		if ( array() === $others ) {
			return array();
		}

		$lines = array(
			'',
			sprintf( 'Je andere delingen die nog open staan (je kunt er %d tegelijk hebben):', Photo_Shares::MAX_OPEN ),
		);

		foreach ( $others as $other ) {
			$lines[] = '';
			$lines[] = '- ' . ( '' === $other->description ? 'Fotoselectie' : $other->description );
			$lines[] = sprintf(
				'  gemaakt %s, nog %s beschikbaar',
				Photo_Shares::date( (string) $other->created_at ),
				self::remaining( (string) $other->expires_at )
			);
			$lines[] = '  ' . Photo_Shares::folder_url( (string) $other->drive_folder_id );
			$lines[] = '  verwijderen: ' . Photo_Shares_Removal::url( $other );
		}

		return $lines;
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
