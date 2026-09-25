<?php
/**
 * Contains the Flagged_Photos class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Frontend\Photo_Tags;

/**
 * Admin overview of photos flagged via the "zorgen" reactions (privacy/AVG
 * concerns, requests to hide a photo) — see Photo_Tags::REACTIONS — with a
 * quick action to exclude the photo from the gallery, or dismiss the flag.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Flagged_Photos {

	/**
	 * Maps a "zorgen" reaction slug to the agallery_photo_exclusions reason
	 * code it implies when a flagged photo is excluded from this page.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on this single constant's multi-line array value (see Photo_Tags::REACTIONS for the same pattern).
	private const REASON_MAP = array(
		'hide_request' => 'member_request',
		'privacy'      => 'privacy_objection',
	);

	/**
	 * Registers the admin page and the exclude/dismiss action handlers.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( self::class, 'register_page' ) );
		add_action( 'admin_post_avpvh_flagged_exclude', array( self::class, 'handle_exclude' ) );
		add_action( 'admin_post_avpvh_flagged_dismiss', array( self::class, 'handle_dismiss' ) );
	}

	/**
	 * Registers the admin submenu page.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'avpvh_basic',
			esc_html__( "Gevlagde foto's", 'avpvh-gallery' ),
			esc_html__( "Gevlagde foto's", 'avpvh-gallery' ),
			'manage_options',
			'avpvh_flagged_photos',
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the admin page.
	 *
	 * @return void
	 */
	public static function render() {
		$rows  = self::query_flagged();
		$intro = esc_html__(
			'Foto\'s gemeld als "Ongemakkelijk / AVG-issue / kinderen" of "Verzoek om te verbergen".',
			'avpvh-gallery'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( "Gevlagde foto's", 'avpvh-gallery' ); ?></h1>
			<p>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped via esc_html__() above.
				echo $intro;
				?>
			</p>
			<?php

			if ( array() === $rows ) :
				?>
				<p><?php esc_html_e( "Geen gevlagde foto's.", 'avpvh-gallery' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Foto', 'avpvh-gallery' ); ?></th>
							<th><?php esc_html_e( 'Meldingen', 'avpvh-gallery' ); ?></th>
							<th><?php esc_html_e( 'Laatst gemeld', 'avpvh-gallery' ); ?></th>
							<th><?php esc_html_e( 'Status', 'avpvh-gallery' ); ?></th>
							<th><?php esc_html_e( 'Actie', 'avpvh-gallery' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row_html() escapes each piece internally before assembling the returned markup.
						echo implode( '', array_map( array( self::class, 'row_html' ), $rows ) );
						?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Excludes a flagged photo from the gallery, clears its flags, and
	 * redirects back to this page.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public static function handle_exclude() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'avpvh-gallery' ), 403 );
		}

		$image_id = isset( $_POST['image_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['image_id'] ) ) : '';
		check_admin_referer( 'avpvh_flagged_exclude_' . $image_id );

		if ( '' !== $image_id ) {
			$flags = isset( $_POST['flags'] )
				? explode( ',', sanitize_text_field( wp_unslash( (string) $_POST['flags'] ) ) )
				: array();

			self::exclude_photo( $image_id, self::reasons_for_flags( $flags ) );
			self::clear_flags( $image_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=avpvh_flagged_photos' ) );
		exit;
	}

	/**
	 * Dismisses a flagged photo's "zorgen" reactions without excluding it,
	 * and redirects back to this page.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'avpvh-gallery' ), 403 );
		}

		$image_id = isset( $_POST['image_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['image_id'] ) ) : '';
		check_admin_referer( 'avpvh_flagged_dismiss_' . $image_id );

		if ( '' !== $image_id ) {
			self::clear_flags( $image_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=avpvh_flagged_photos' ) );
		exit;
	}

	/**
	 * Builds the HTML for a single row of the flagged-photos table. Built
	 * as a string (rather than templated inline PHP/HTML) so every dynamic
	 * value is escaped exactly once, close to where it's used.
	 *
	 * @param array{image_id: string, hide_request_count: int, privacy_count: int, last_flagged: string, excluded_at: string|null} $row One row from query_flagged().
	 *
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.UnusedPrivateMethod") -- called indirectly via array_map( array( self::class, 'row_html' ), ... ) in render(), which PHPMD's static analysis doesn't follow.
	 */
	private static function row_html( array $row ) {
		$flags     = self::flags_for_row( $row );
		$drive_url = esc_url( 'https://drive.google.com/file/d/' . rawurlencode( $row['image_id'] ) . '/view' );
		$post_url  = esc_url( admin_url( 'admin-post.php' ) );
		$raw_id    = $row['image_id'];
		$image_id  = esc_html( $row['image_id'] );
		$badges    = self::badges_html( $row );
		$status    = null !== $row['excluded_at']
			? esc_html__( 'Al uitgesloten', 'avpvh-gallery' )
			: esc_html__( 'Actief in gallery', 'avpvh-gallery' );

		$exclude_form = '';

		if ( null === $row['excluded_at'] ) {
			$exclude_form = self::action_form_html(
				$post_url,
				'avpvh_flagged_exclude',
				$raw_id,
				$flags,
				esc_html__( 'Uitsluiten', 'avpvh-gallery' ),
				'button button-primary'
			);
		}

		$dismiss_form = self::action_form_html(
			$post_url,
			'avpvh_flagged_dismiss',
			$raw_id,
			'',
			esc_html__( 'Negeren', 'avpvh-gallery' ),
			'button'
		);

		return '<tr>'
			. '<td><a href="' . $drive_url . '" target="_blank" rel="noopener noreferrer">' . $image_id . '</a></td>'
			. '<td>' . $badges . '</td>'
			. '<td>' . esc_html( $row['last_flagged'] ) . '</td>'
			. '<td>' . $status . '</td>'
			. '<td>' . $exclude_form . $dismiss_form . '</td>'
			. '</tr>';
	}

	/**
	 * The "zorgen" flag slugs present on a row (i.e. with a count above 0).
	 *
	 * @param array{hide_request_count: int, privacy_count: int} $row One row from query_flagged().
	 *
	 * @return array<string>
	 */
	private static function flags_for_row( array $row ) {
		$flags = array();

		if ( $row['hide_request_count'] > 0 ) {
			$flags[] = 'hide_request';
		}

		if ( $row['privacy_count'] > 0 ) {
			$flags[] = 'privacy';
		}

		return $flags;
	}

	/**
	 * The "🙈 N" / "⚠️ N" badges for a row's flag counts.
	 *
	 * @param array{hide_request_count: int, privacy_count: int} $row One row from query_flagged().
	 *
	 * @return string
	 */
	private static function badges_html( array $row ) {
		$badges = '';

		if ( $row['hide_request_count'] > 0 ) {
			$badges .= '<span>🙈 ' . esc_html( (string) $row['hide_request_count'] ) . '</span> ';
		}

		if ( $row['privacy_count'] > 0 ) {
			$badges .= '<span>⚠️ ' . esc_html( (string) $row['privacy_count'] ) . '</span>';
		}

		return $badges;
	}

	/**
	 * Builds a single-button admin-post form (the "Uitsluiten"/"Negeren" actions).
	 *
	 * @param string               $post_url Pre-escaped admin-post.php URL.
	 * @param string               $action   The admin_post_{$action} hook name.
	 * @param string               $image_id Raw (unescaped) image ID — also used to build the nonce action string, so it must match handle_exclude()/handle_dismiss()'s own raw value.
	 * @param array<string>|string $flags    Flag slugs for the exclude action, or '' for dismiss.
	 * @param string               $label    Pre-escaped button label.
	 * @param string               $css_class Button CSS class(es).
	 *
	 * @return string
	 */
	private static function action_form_html( $post_url, $action, $image_id, $flags, $label, $css_class ) {
		$flags_value = is_array( $flags ) ? esc_attr( implode( ',', $flags ) ) : '';
		$flags_field = is_array( $flags ) ? '<input type="hidden" name="flags" value="' . $flags_value . '" />' : '';

		return '<form method="post" action="' . $post_url . '" style="display:inline;">'
			. wp_nonce_field( $action . '_' . $image_id, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="image_id" value="' . esc_attr( $image_id ) . '" />'
			. $flags_field
			. '<button type="submit" class="' . esc_attr( $css_class ) . '">' . $label . '</button>'
			. '</form>';
	}

	/**
	 * Maps submitted "zorgen" flag slugs to agallery_photo_exclusions reason
	 * codes, falling back to 'member_request' if none matched (shouldn't
	 * normally happen — the flags are read from this page's own form).
	 *
	 * @param array<string> $flags Reaction slugs, e.g. ['hide_request', 'privacy'].
	 *
	 * @return array<string>
	 */
	private static function reasons_for_flags( array $flags ) {
		$reasons = array();

		foreach ( $flags as $flag ) {
			if ( isset( self::REASON_MAP[ $flag ] ) ) {
				$reasons[] = self::REASON_MAP[ $flag ];
			}
		}

		return array() !== $reasons ? $reasons : array( 'member_request' );
	}

	/**
	 * Queries photos with at least one "zorgen"-group reaction, along with
	 * their current exclusion status.
	 *
	 * @return array<array{image_id: string, hide_request_count: int, privacy_count: int, last_flagged: string, excluded_at: string|null}>
	 */
	private static function query_flagged() {
		global $wpdb;

		$reactions_table  = $wpdb->prefix . 'agallery_photo_reactions';
		$exclusions_table = $wpdb->prefix . 'agallery_photo_exclusions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin tables, no cache group defined; table names are concatenated (not user-supplied), the %s placeholders below are filled via $wpdb->prepare().
		$rows_result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.image_id,
					SUM(r.emoji = 'hide_request') AS hide_request_count,
					SUM(r.emoji = 'privacy') AS privacy_count,
					MAX(r.created_at) AS last_flagged,
					e.updated_at AS excluded_at
				 FROM {$reactions_table} r
				 LEFT JOIN {$exclusions_table} e ON e.image_id = r.image_id
				 WHERE r.emoji IN (%s, %s)
				 GROUP BY r.image_id
				 ORDER BY last_flagged DESC",
				'hide_request',
				'privacy'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = is_array( $rows_result ) ? $rows_result : array();

		return array_map(
			static function ( $row ) {
				return array(
					'excluded_at'        => $row['excluded_at'],
					'hide_request_count' => intval( $row['hide_request_count'] ),
					'image_id'           => $row['image_id'],
					'last_flagged'       => $row['last_flagged'],
					'privacy_count'      => intval( $row['privacy_count'] ),
				);
			},
			$rows
		);
	}

	/**
	 * Excludes a photo from the gallery, mirroring
	 * Corrections_REST::save_exclusion().
	 *
	 * @param string        $image_id The Google Drive file ID.
	 * @param array<string> $reasons  agallery_photo_exclusions reason codes.
	 *
	 * @return void
	 */
	private static function exclude_photo( $image_id, array $reasons ) {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_exclusions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
		$wpdb->replace(
			$table,
			array(
				'excluded_by' => get_current_user_id(),
				'folder_id'   => '',
				'image_id'    => $image_id,
				'media_type'  => 'image',
				'note'        => esc_html__( "Uitgesloten via het \"Gevlagde foto's\" overzicht.", 'avpvh-gallery' ),
				'reasons'     => implode( ',', $reasons ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Clears a photo's "zorgen"-group reactions so it stops showing on this
	 * page, whether or not it was excluded.
	 *
	 * @param string $image_id The Google Drive file ID.
	 *
	 * @return void
	 */
	private static function clear_flags( $image_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'agallery_photo_reactions';

		foreach ( Photo_Tags::FLAGGED_REACTIONS as $emoji ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no cache group defined.
			$wpdb->delete(
				$table,
				array(
					'emoji'    => $emoji,
					'image_id' => $image_id,
				),
				array( '%s', '%s' )
			);
		}
	}
}
