<?php
/**
 * Contains the Photo_Shares class.
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
 * Sharing a filter's photos through Google Drive: the photos the gallery
 * shows for a filter are copied (in date order) into a new folder, which
 * only the user's Google account (Share_Recipient) may view. The user gets
 * the link by e-mail and on their profile (Photo_Shares_Page), and after a
 * week the folder is deleted. The filter is kept, so an expired share can
 * be made again — with the photos that match it by then.
 *
 * Copying runs in the background (WP-cron); the e-mail says when it's done.
 * The folders are made by the service account set in the plugin's settings
 * (see Share_Drive); without one, sharing isn't offered.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Photo_Shares {

	/**
	 * At most this many photos per share.
	 */
	public const MAX_PHOTOS = 500;

	/**
	 * At most this many shares per user that are being made or still open.
	 */
	public const MAX_OPEN = 5;

	/**
	 * Days a share stays available.
	 */
	private const DAYS = 7;

	/**
	 * Cron hook that makes one share (argument: share ID).
	 */
	private const BUILD_HOOK = 'avpvh_gallery_build_share';

	/**
	 * Daily cron hook that deletes expired shares' folders.
	 */
	private const EXPIRE_HOOK = 'avpvh_gallery_expire_shares';

	/**
	 * Registers the AJAX endpoint, the cron jobs and the profile list.
	 */
	public function __construct() {
		new Photo_Shares_Page();
		add_action( 'wp_ajax_gallery_share_create', array( self::class, 'ajax_create' ) );
		add_action( self::BUILD_HOOK, array( self::class, 'build' ) );
		add_action( self::EXPIRE_HOOK, array( self::class, 'expire' ) );
		add_action( 'init', array( self::class, 'schedule_expiry' ) );
	}

	/**
	 * Makes sure expired shares are cleaned up daily.
	 *
	 * @return void
	 */
	public static function schedule_expiry() {
		if ( false === wp_next_scheduled( self::EXPIRE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EXPIRE_HOOK );
		}
	}

	/**
	 * Starts a share of a filter's photos. POST: conditions (JSON, as for
	 * gallery_filter), folder ('' or a Drive folder ID: only photos below
	 * it), description (the filter in words, for the e-mail and profile).
	 *
	 * @return void
	 */
	public static function ajax_create() {
		check_ajax_referer( 'avpvh_tag_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; the conditions are validated by Photo_Filter.
		$conditions  = wp_unslash( (string) ( $_POST['conditions'] ?? '[]' ) );
		$folder_id   = sanitize_text_field( wp_unslash( (string) ( $_POST['folder'] ?? '' ) ) );
		$description = sanitize_text_field( wp_unslash( (string) ( $_POST['description'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$valid = Photo_Filter::valid_conditions( $conditions );
		$error = self::create_refusal( $valid, $folder_id );

		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ), 400 );
		}

		self::start(
			Photo_Shares_DB::insert(
				array(
					'conditions'  => (string) wp_json_encode( $valid ),
					'description' => mb_substr( $description, 0, 500 ),
					'folder_id'   => $folder_id,
				)
			)
		);

		wp_send_json_success( array( 'recipient' => Share_Recipient::for_user( get_current_user_id() ) ) );
	}

	/**
	 * Makes a share again with its saved filter (see Photo_Shares_Page).
	 *
	 * @param stdClass $share The share.
	 *
	 * @return void
	 */
	public static function restart( $share ) {
		Photo_Shares_DB::update(
			(int) $share->id,
			array(
				'created_at'      => current_time( 'mysql' ),
				'drive_folder_id' => '',
				'error'           => '',
				'expires_at'      => null,
				'photo_count'     => 0,
				'recipient'       => Share_Recipient::for_user( (int) $share->user_id ),
				'status'          => 'pending',
			)
		);
		self::start( (int) $share->id );
	}

	/**
	 * Cron: copies a share's photos into a new folder, shares it with the
	 * recipient and mails them the link. On failure the half-made folder is
	 * removed and the share marked failed (shown on the profile).
	 *
	 * @param int $share_id Share ID.
	 *
	 * @return void
	 */
	public static function build( $share_id ) {
		$share = Photo_Shares_DB::get( (int) $share_id );

		if ( ! $share instanceof stdClass ) {
			return;
		}

		if ( 'pending' !== $share->status ) {
			return;
		}

		wp_set_current_user( (int) $share->user_id );
		$folder = '';

		try {
			$conditions = Photo_Filter::valid_conditions( $share->conditions );
			$matching   = Photo_Filter::all_matching_ids( $conditions, $share->folder_id );
			$ids        = array_slice( $matching, 0, self::MAX_PHOTOS );
			$folder     = Share_Drive::create_folder( self::folder_name( $share ) );
			Share_Drive::copy_into( $ids, $folder );
			Share_Drive::share_with( $folder, $share->recipient );
		} catch ( Throwable $e ) {
			self::fail( $share, $folder, $e );

			return;
		}

		Photo_Shares_DB::update(
			(int) $share->id,
			array(
				'drive_folder_id' => $folder,
				'expires_at'      => wp_date( 'Y-m-d H:i:s', time() + self::DAYS * DAY_IN_SECONDS ),
				'photo_count'     => count( $ids ),
				'status'          => 'ready',
			)
		);
		self::mail_link( Photo_Shares_DB::get( (int) $share->id ) );
	}

	/**
	 * Cron (daily): deletes the folders of shares past their date.
	 *
	 * @return void
	 */
	public static function expire() {
		foreach ( Photo_Shares_DB::expired() as $share ) {
			try {
				Share_Drive::remove( (string) $share->drive_folder_id );
			} catch ( Throwable $e ) {
				// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- already gone (or Drive unreachable): the share is over either way.
				unset( $e );
			}

			Photo_Shares_DB::update( (int) $share->id, array( 'status' => 'expired' ) );
		}
	}

	/**
	 * A Drive folder's web address.
	 *
	 * @param string $folder_id Drive folder ID.
	 *
	 * @return string
	 */
	public static function folder_url( $folder_id ) {
		return 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id );
	}

	/**
	 * A stored date and time as "11 oktober 2026, 14:30".
	 *
	 * @param string $mysql Local date and time.
	 *
	 * @return string
	 */
	public static function date( $mysql ) {
		return '' === $mysql ? '' : date_i18n( 'j F Y, H:i', (int) strtotime( $mysql ) );
	}

	/**
	 * Why a share can't be started, or null if it can.
	 *
	 * @param array<array{kind: string, value: string|int, op: string}> $conditions Valid conditions.
	 * @param string                                                    $folder_id  Folder limit, or ''.
	 *
	 * @return string|null
	 */
	private static function create_refusal( array $conditions, $folder_id ) {
		if ( ! Share_Drive::configured() ) {
			return 'Delen via Google Drive is nog niet ingesteld; vraag een beheerder';
		}

		if ( self::MAX_OPEN <= Photo_Shares_DB::open_count( get_current_user_id() ) ) {
			return sprintf( 'Je hebt al %d delingen open; wacht tot er een verloopt', self::MAX_OPEN );
		}

		try {
			$count = count( Photo_Filter::all_matching_ids( $conditions, $folder_id ) );
		} catch ( Throwable $e ) {
			// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- the user only needs to know it failed.
			return 'Het filter kon niet worden uitgevoerd';
		}

		if ( 0 === $count ) {
			return 'Dit filter vindt geen foto’s';
		}

		return self::MAX_PHOTOS < $count
			? sprintf( 'Je kunt hooguit %d foto’s tegelijk delen (dit filter vindt er %d)', self::MAX_PHOTOS, $count )
			: null;
	}

	/**
	 * Schedules making a share right away.
	 *
	 * @param int $share_id Share ID.
	 *
	 * @return void
	 */
	private static function start( $share_id ) {
		wp_schedule_single_event( time(), self::BUILD_HOOK, array( $share_id ) );
		spawn_cron();
	}

	/**
	 * Marks a share failed and removes its half-made folder.
	 *
	 * @param stdClass  $share     The share.
	 * @param string    $folder_id Its folder, if made.
	 * @param Throwable $error     What went wrong.
	 *
	 * @return void
	 */
	private static function fail( $share, $folder_id, Throwable $error ) {
		if ( '' !== $folder_id ) {
			try {
				Share_Drive::remove( $folder_id );
			} catch ( Throwable $e ) {
				// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- left for whoever looks in the selections folder.
				unset( $e );
			}
		}

		Photo_Shares_DB::update(
			(int) $share->id,
			array(
				'error'  => mb_substr( self::explain( $error, $share->recipient ), 0, 500 ),
				'status' => 'failed',
			)
		);
	}

	/**
	 * A failure in words for the profile.
	 *
	 * @param Throwable $error     What went wrong.
	 * @param string    $recipient The Google address shared with.
	 *
	 * @return string
	 */
	private static function explain( Throwable $error, $recipient ) {
		$message = $error->getMessage();

		if ( 1 === preg_match( '/insufficient|notFound|File not found/i', $message ) ) {
			return 'Het service-account kan de foto’s of de map voor selecties niet bereiken; '
				. 'vraag een beheerder de instellingen te controleren.';
		}

		if ( 1 === preg_match( '/invalidSharingRequest|no Google account/i', $message ) ) {
			return sprintf(
				'Bij %s hoort geen Google-account. Voeg op je profiel een Google-adres toe en maak de deling opnieuw.',
				$recipient
			);
		}

		return 'Delen mislukt: ' . $message;
	}

	/**
	 * Mails a ready share's link to its recipient.
	 *
	 * @param stdClass|null $share The share.
	 *
	 * @return void
	 */
	private static function mail_link( $share ) {
		if ( null === $share ) {
			return;
		}

		$lines = array(
			'Hallo,',
			'',
			sprintf( 'Je fotoselectie (%d foto’s) staat klaar in Google Drive:', (int) $share->photo_count ),
			self::folder_url( (string) $share->drive_folder_id ),
			'',
			'Filter: ' . ( '' === $share->description ? '–' : $share->description ),
			sprintf(
				'Alleen te openen met het Google-account %s, tot %s.',
				$share->recipient,
				self::date( (string) $share->expires_at )
			),
			'Daarna wordt de map verwijderd; op je profiel kun je de selectie dan opnieuw laten maken.',
		);

		wp_mail( (string) $share->recipient, 'Je fotoselectie staat klaar', implode( "\n", $lines ) );
	}

	/**
	 * The folder's name: the date and the filter.
	 *
	 * @param stdClass $share The share.
	 *
	 * @return string
	 */
	private static function folder_name( $share ) {
		$name = 'Fotoselectie ' . current_time( 'Y-m-d' );

		return '' === $share->description ? $name : $name . ' – ' . mb_substr( (string) $share->description, 0, 80 );
	}
}
