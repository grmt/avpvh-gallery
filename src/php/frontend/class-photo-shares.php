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

use Throwable;

/**
 * Sharing a filter's photos through Google Drive: the photos the gallery
 * shows for a filter are copied (in capture order) into a new folder in the
 * plugin's Drive, which only the user's Google account may view. The user
 * gets the link by e-mail and on their profile ([avpvh_gallery_shares]),
 * and after a week the folder is deleted. The filter is kept, so an expired
 * share can be made again — with the photos that match it by then.
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
	private const MAX_OPEN = 5;

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
	 * Registers the AJAX endpoint, the profile shortcode, the recreate form
	 * handler and the cron jobs.
	 */
	public function __construct() {
		add_action( 'wp_ajax_gallery_share_create', array( self::class, 'ajax_create' ) );
		add_action( 'admin_post_avpvh_gallery_share_recreate', array( self::class, 'handle_recreate' ) );
		add_shortcode( 'avpvh_gallery_shares', array( self::class, 'shortcode' ) );
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

		$recipient = self::recipient( get_current_user_id() );
		self::start(
			self::insert(
				array(
					'conditions'  => (string) wp_json_encode( $valid ),
					'description' => mb_substr( $description, 0, 500 ),
					'folder_id'   => $folder_id,
				)
			)
		);

		wp_send_json_success( array( 'recipient' => $recipient ) );
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

		if ( self::MAX_OPEN <= self::open_count( get_current_user_id() ) ) {
			return sprintf( 'Je hebt al %d delingen open; wacht tot er een verloopt', self::MAX_OPEN );
		}

		try {
			$count = count( Photo_Filter::all_matching_ids( $conditions, $folder_id ) );
		} catch ( Throwable $e ) {
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
	 * Makes an expired or failed share again, with the photos its filter
	 * finds now. admin-post form: share, _wpnonce.
	 *
	 * @return void
	 */
	public static function handle_recreate() {
		check_admin_referer( 'avpvh_gallery_share_recreate' );
		$share_id = absint( $_POST['share'] ?? 0 );
		$share    = self::get( $share_id );
		$back     = wp_get_referer();

		if (
			null !== $share &&
			(int) $share->user_id === get_current_user_id() &&
			in_array( $share->status, array( 'expired', 'failed' ), true ) &&
			self::MAX_OPEN > self::open_count( get_current_user_id() )
		) {
			self::update(
				$share_id,
				array(
					'created_at'      => current_time( 'mysql' ),
					'drive_folder_id' => '',
					'error'           => '',
					'expires_at'      => null,
					'photo_count'     => 0,
					'recipient'       => self::recipient( get_current_user_id() ),
					'status'          => 'pending',
				)
			);
			self::start( $share_id );
		}

		wp_safe_redirect( false === $back ? home_url() : $back );
		exit;
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
	 * Cron: copies a share's photos into a new folder, shares it with the
	 * recipient and mails them the link. On failure the half-made folder is
	 * removed and the share marked failed (shown on the profile).
	 *
	 * @param int $share_id Share ID.
	 *
	 * @return void
	 */
	public static function build( $share_id ) {
		$share = self::get( (int) $share_id );

		if ( null === $share || 'pending' !== $share->status ) {
			return;
		}

		wp_set_current_user( (int) $share->user_id );
		$folder = '';

		try {
			$ids = array_slice(
				Photo_Filter::all_matching_ids( Photo_Filter::valid_conditions( $share->conditions ), $share->folder_id ),
				0,
				self::MAX_PHOTOS
			);
			$folder = Share_Drive::create_folder( self::folder_name( $share ) );
			Share_Drive::copy_into( $ids, $folder );
			Share_Drive::share_with( $folder, $share->recipient );
		} catch ( Throwable $e ) {
			self::fail( $share, (string) $folder, $e );

			return;
		}

		$expires = wp_date( 'Y-m-d H:i:s', time() + self::DAYS * DAY_IN_SECONDS );
		self::update(
			(int) $share->id,
			array(
				'drive_folder_id' => (string) $folder,
				'expires_at'      => $expires,
				'photo_count'     => count( $ids ),
				'status'          => 'ready',
			)
		);
		self::mail_link( self::get( (int) $share->id ) );
	}

	/**
	 * Marks a share failed and removes its half-made folder.
	 *
	 * @param object    $share     The share.
	 * @param string    $folder_id Its folder, if made.
	 * @param Throwable $error     What went wrong.
	 *
	 * @return void
	 */
	private static function fail( $share, $folder_id, Throwable $error ) {
		if ( '' !== $folder_id ) {
			try {
				Share_Drive::remove( $folder_id );
			} catch ( Throwable $ignored ) {
				// Left for whoever looks in the plugin's Drive.
				unset( $ignored );
			}
		}

		self::update(
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

		if ( false !== stripos( $message, 'insufficient' ) || false !== stripos( $message, 'notFound' ) || false !== stripos( $message, 'File not found' ) ) {
			return 'Het service-account kan de foto’s of de map voor selecties niet bereiken; vraag een beheerder de instellingen te controleren.';
		}

		if ( false !== stripos( $message, 'invalidSharingRequest' ) || false !== stripos( $message, 'no Google account' ) ) {
			return sprintf( 'Bij %s hoort geen Google-account. Voeg op je profiel een Google-adres toe en maak de deling opnieuw.', $recipient );
		}

		return 'Delen mislukt: ' . $message;
	}

	/**
	 * Cron (daily): deletes the folders of shares past their date.
	 *
	 * @return void
	 */
	public static function expire() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$shares = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agallery_photo_shares WHERE status = 'ready' AND expires_at < %s",
				current_time( 'mysql' )
			)
		);

		foreach ( is_array( $shares ) ? $shares : array() as $share ) {
			try {
				Share_Drive::remove( (string) $share->drive_folder_id );
			} catch ( Throwable $e ) {
				// Already gone (or Drive unreachable): the share is over either way.
				unset( $e );
			}

			self::update( (int) $share->id, array( 'status' => 'expired' ) );
		}
	}

	/**
	 * The address to share with: the user's e-mail address when it's a
	 * Gmail address; otherwise the Google address they verified in
	 * avpvh-members, if any; otherwise still their e-mail address (it may be
	 * a Google account on another domain).
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string
	 */
	public static function recipient( $user_id ) {
		$user    = get_userdata( $user_id );
		$member  = function_exists( 'avpvh_get_member_by_wp_user' ) ? avpvh_get_member_by_wp_user( $user_id ) : null;
		$primary = is_object( $member ) && '' !== (string) ( $member->email ?? '' )
			? (string) $member->email
			: ( false === $user ? '' : (string) $user->user_email );

		if ( 1 === preg_match( '/@(gmail|googlemail)\.com$/i', $primary ) || ! is_object( $member ) ) {
			return $primary;
		}

		return self::google_identities( (int) $member->id )[0] ?? $primary;
	}

	/**
	 * A member's Google-verified addresses (avpvh-members), verified first.
	 *
	 * @param int $member_id avpvh-members member ID.
	 *
	 * @return array<string>
	 */
	private static function google_identities( $member_id ) {
		if ( ! class_exists( '\\AVPVH_DB' ) || ! method_exists( '\\AVPVH_DB', 'get_member_identities' ) ) {
			return array();
		}

		$google = array_filter(
			(array) call_user_func( array( '\\AVPVH_DB', 'get_member_identities' ), $member_id ),
			static function ( $identity ) {
				return is_object( $identity ) && 'google' === ( $identity->provider ?? '' );
			}
		);
		usort(
			$google,
			static function ( $a, $b ) {
				return (int) empty( $a->verified_at ) - (int) empty( $b->verified_at );
			}
		);

		return array_map(
			static function ( $identity ) {
				return (string) $identity->email;
			},
			$google
		);
	}

	/**
	 * Mails a ready share's link to its recipient.
	 *
	 * @param object|null $share The share.
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
			sprintf( 'Alleen te openen met het Google-account %s, tot %s.', $share->recipient, self::date( (string) $share->expires_at ) ),
			'Daarna wordt de map verwijderd; op je profiel kun je de selectie dan opnieuw laten maken.',
		);

		wp_mail( (string) $share->recipient, 'Je fotoselectie staat klaar', implode( "\n", $lines ) );
	}

	/**
	 * The [avpvh_gallery_shares] shortcode: the current user's shares with
	 * their link and expiry, and "Opnieuw maken" for expired or failed ones.
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$shares = self::for_user( get_current_user_id() );
		$html   = '<div class="avpvh-gallery-shares"><h3>Gedeelde fotoselecties</h3>';

		if ( array() === $shares ) {
			return $html . '<p>Je hebt nog geen foto’s gedeeld. Filter in de galerie en kies "Delen via Google Drive".</p></div>';
		}

		$html .= '<table><thead><tr><th>Selectie</th><th>Foto’s</th><th>Status</th><th>Beschikbaar tot</th></tr></thead><tbody>';

		foreach ( $shares as $share ) {
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( '' === $share->description ? '–' : $share->description ),
				esc_html( 0 < (int) $share->photo_count ? (string) $share->photo_count : '' ),
				self::status_html( $share ),
				esc_html( 'ready' === $share->status ? self::date( (string) $share->expires_at ) : '' )
			);
		}

		return $html . '</tbody></table></div>';
	}

	/**
	 * A share's status cell: its link, progress, or what went wrong with a
	 * button to make it again.
	 *
	 * @param object $share The share.
	 *
	 * @return string
	 */
	private static function status_html( $share ) {
		if ( 'ready' === $share->status ) {
			return sprintf( '<a href="%s" target="_blank" rel="noopener">Openen in Google Drive</a>', esc_url( self::folder_url( (string) $share->drive_folder_id ) ) );
		}

		if ( 'pending' === $share->status ) {
			return 'Wordt gemaakt… (je krijgt een e-mail)';
		}

		$text = 'expired' === $share->status ? 'Verlopen' : esc_html( (string) $share->error );

		return $text . sprintf(
			' <form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="avpvh_gallery_share_recreate"><input type="hidden" name="share" value="%d"><button type="submit">Opnieuw maken</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'avpvh_gallery_share_recreate', '_wpnonce', true, false ),
			(int) $share->id
		);
	}

	/**
	 * The folder's name: the date and the filter.
	 *
	 * @param object $share The share.
	 *
	 * @return string
	 */
	private static function folder_name( $share ) {
		$name = 'Fotoselectie ' . current_time( 'Y-m-d' );

		return '' === $share->description ? $name : $name . ' – ' . mb_substr( (string) $share->description, 0, 80 );
	}

	/**
	 * A Drive folder's web address.
	 *
	 * @param string $folder_id Drive folder ID.
	 *
	 * @return string
	 */
	private static function folder_url( $folder_id ) {
		return 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id );
	}

	/**
	 * A stored date and time as "11 oktober 2026, 14:30".
	 *
	 * @param string $mysql Local date and time.
	 *
	 * @return string
	 */
	private static function date( $mysql ) {
		return '' === $mysql ? '' : date_i18n( 'j F Y, H:i', (int) strtotime( $mysql ) );
	}

	/**
	 * How many of a user's shares are being made or still open.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return int
	 */
	private static function open_count( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agallery_photo_shares WHERE user_id = %d AND status IN ('pending', 'ready')",
				$user_id
			)
		);
	}

	/**
	 * A user's shares, newest first.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array<object>
	 */
	private static function for_user( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agallery_photo_shares WHERE user_id = %d ORDER BY created_at DESC LIMIT 20",
				$user_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One share.
	 *
	 * @param int $share_id Share ID.
	 *
	 * @return object|null
	 */
	private static function get( $share_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agallery_photo_shares WHERE id = %d", $share_id )
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Stores a new pending share for the current user.
	 *
	 * @param array{conditions: string, description: string, folder_id: string} $fields The filter.
	 *
	 * @return int The new share's ID.
	 */
	private static function insert( array $fields ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table.
		$wpdb->insert(
			$wpdb->prefix . 'agallery_photo_shares',
			array_merge(
				$fields,
				array(
					'created_at' => current_time( 'mysql' ),
					'recipient'  => self::recipient( get_current_user_id() ),
					'status'     => 'pending',
					'user_id'    => get_current_user_id(),
				)
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Changes a share.
	 *
	 * @param int                  $share_id Share ID.
	 * @param array<string, mixed> $fields   Columns to change.
	 *
	 * @return void
	 */
	private static function update( $share_id, array $fields ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update( $wpdb->prefix . 'agallery_photo_shares', $fields, array( 'id' => $share_id ) );
	}
}
