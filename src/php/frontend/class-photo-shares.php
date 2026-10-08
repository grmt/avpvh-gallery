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
	 * How Drive says the recipient has no Google account.
	 */
	private const NO_GOOGLE = '/invalidSharingRequest|no Google account/i';

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
		add_action( 'wp_ajax_gallery_dig_captions', array( Share_Caption::class, 'ajax_folders' ) );
		add_action( 'wp_ajax_nopriv_gallery_dig_captions', array( Share_Caption::class, 'ajax_folders' ) );
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
	 * gallery_filter), folders (JSON Drive folder IDs: only photos below
	 * those branches), description (the filter in words, for e-mail/profile),
	 * captions ('1': upright, with dig names written on them; see Share_Image),
	 * a4 ('1': cropped to A4 proportions for printing),
	 * google (the user's Google address, when asked for; see Share_Recipient).
	 *
	 * @return void
	 */
	public static function ajax_create() {
		check_ajax_referer( 'avpvh_tag_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; the conditions are validated by Photo_Filter.
		$conditions  = wp_unslash( (string) ( $_POST['conditions'] ?? '[]' ) );
		$folder_ids  = Photo_Filter_Scope::folder_ids( wp_unslash( (string) ( $_POST['folders'] ?? '[]' ) ) );
		$folder_json = (string) wp_json_encode( $folder_ids );
		$description = sanitize_text_field( wp_unslash( (string) ( $_POST['description'] ?? '' ) ) );
		$captions    = '1' === wp_unslash( (string) ( $_POST['captions'] ?? '' ) ) && Share_Image::available();
		$crop_a4     = '1' === wp_unslash( (string) ( $_POST['a4'] ?? '' ) ) && Share_Image::available();
		$google      = sanitize_email( wp_unslash( (string) ( $_POST['google'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$valid = Photo_Filter::valid_conditions( $conditions );
		$error = self::create_refusal( $valid, $folder_ids );

		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ), 400 );
		}

		Share_Recipient::remember( get_current_user_id(), $google );

		self::start(
			Photo_Shares_DB::insert(
				array(
					'a4'          => $crop_a4 ? 1 : 0,
					'captions'    => $captions ? 1 : 0,
					'conditions'  => (string) wp_json_encode( $valid ),
					'description' => mb_substr( $description, 0, 500 ),
					'folder_id'   => $folder_json,
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
		self::lift_time_limit();
		$folder = '';
		$count  = 0;

		try {
			$conditions = Photo_Filter::valid_conditions( $share->conditions );
			$matching   = Photo_Filter::all_matching_ids(
				$conditions,
				self::stored_folders( (string) $share->folder_id )
			);
			$ids        = array_slice( $matching, 0, self::MAX_PHOTOS );
			$folder     = Share_Drive::create_folder( self::folder_name( $share ) );
			// Known right away, so the folder can be found if the build dies.
			Photo_Shares_DB::update( (int) $share->id, array( 'drive_folder_id' => $folder ) );
			// Shared before filling it, so an address without a Google
			// account fails at once rather than after all the photos.
			Share_Drive::share_with( $folder, $share->recipient );
			$count = self::copy( $share, $ids, $folder );
		} catch ( Throwable $e ) {
			self::fail( $share, $folder, $e );

			return;
		}

		Photo_Shares_DB::update(
			(int) $share->id,
			array(
				'drive_folder_id' => $folder,
				'expires_at'      => wp_date( 'Y-m-d H:i:s', time() + self::DAYS * DAY_IN_SECONDS ),
				'photo_count'     => $count,
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
	 * @param array<string>                                             $folder_ids Folder limits, or none.
	 *
	 * @return string|null
	 */
	private static function create_refusal( array $conditions, array $folder_ids ) {
		if ( ! Share_Drive::configured() ) {
			return 'Delen via Google Drive is nog niet ingesteld; vraag een beheerder';
		}

		if ( self::MAX_OPEN <= Photo_Shares_DB::open_count( get_current_user_id() ) ) {
			return sprintf( 'Je hebt al %d delingen open; wacht tot er een verloopt', self::MAX_OPEN );
		}

		try {
			$count = count( Photo_Filter::all_matching_ids( $conditions, $folder_ids ) );
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
	 * Puts a share's photos in its folder, as JPEG files (see Share_Image),
	 * with progress kept in photo_count; plain copies where Imagick is
	 * missing.
	 *
	 * @param stdClass      $share     The share.
	 * @param array<string> $ids       Drive file IDs.
	 * @param string        $folder_id The share's folder.
	 *
	 * @return int How many photos were put in.
	 */
	private static function copy( $share, array $ids, $folder_id ) {
		if ( ! Share_Image::available() ) {
			Share_Drive::copy_into( $ids, $folder_id );

			return count( $ids );
		}

		$options = array(
			'a4'       => 1 === (int) ( $share->a4 ?? 0 ),
			'captions' => 1 === (int) ( $share->captions ?? 0 ),
		);

		return Share_Image::copy_into(
			$ids,
			$folder_id,
			$options,
			static function ( $done ) use ( $share ) {
				Photo_Shares_DB::update( (int) $share->id, array( 'photo_count' => $done ) );
			}
		);
	}

	/**
	 * Reads the new JSON folder list and old single-folder share records.
	 *
	 * @param string $stored Stored folder_id column.
	 *
	 * @return array<string>
	 */
	private static function stored_folders( $stored ) {
		if ( '' === $stored ) {
			return array();
		}

		$folders = Photo_Filter_Scope::folder_ids( $stored );

		return array() === $folders ? array( $stored ) : $folders;
	}

	/**
	 * Lets a build run as long as it needs: cron runs as a web request,
	 * whose time limit (30 s here) is far too short for editing hundreds of
	 * photos.
	 *
	 * @return void
	 */
	private static function lift_time_limit() {
		if ( ! function_exists( 'set_time_limit' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a background job, not a page.
		set_time_limit( 0 );
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

		if ( 1 === preg_match( self::NO_GOOGLE, $error->getMessage() ) ) {
			Share_Recipient::forget( (int) $share->user_id, (string) $share->recipient );
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

		if ( 1 === preg_match( self::NO_GOOGLE, $message ) ) {
			return sprintf(
				'Bij %s hoort geen Google-account. Deel opnieuw vanuit de galerij en geef daar je Google-adres op.',
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
