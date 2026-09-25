<?php
/**
 * Contains the Image_Proxy class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\GET_Helpers;
use Avpvh\Helpers;

/**
 * Contains all the functions used to handle the "nieuwsbrief_image" AJAX endpoint.
 *
 * The "nieuwsbrief_image" AJAX endpoint serves a single Drive-hosted image, resized
 * to a requested width via Drive's own thumbnail generation, streamed through the
 * website server so post_content never has to embed image bytes itself. Restricted
 * to logged-in members and to files inside a fixed allow-list of Nieuwsbrief edition
 * folders, so this can't be used as a general-purpose "fetch any Drive file" oracle.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Image_Proxy {

	/**
	 * Drive folder IDs this endpoint will serve images from (the "06-Nieuwsbrief"
	 * edition folders). A requested file must have one of these as its immediate
	 * parent. Value is the Nieuwsbrief edition each ID belongs to.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on this single constant's multi-line array value (see Photo_Tags::REACTIONS for the same pattern).
	private const ALLOWED_PARENT_IDS = array(
		'1-WG9Jrs7uve86a5PnniaYXi_Bnro7UD-' => '20210102 Nieuwsbrief 33',
		'1A1j2yxv9_nUw8k9H7A6iTH6jMVnVstne' => '20201107 Nieuwsbrief 32',
		'1Af0FcJbdXTHcYjBF3bX4EXRFo3CegqkV' => '20110504 Nieuwsbrief 10',
		'1bJxTOb_GLY3zmoL7x3vqLq2TqO_sbGFL' => '20100309 Nieuwsbrief 2',
		'1DcgA1SQ0V6qALhrcDSohNp2z9Xw0V9nk' => '20160418 Nieuwsbrief 23',
		'1dh1TY5fVbvZ8WhsCn66NvaDX0Yk1BJxi' => '20211231 Nieuwsbrief 35',
		'1E3F4oUf0Er2IMgBgmuVHaX80fkfn4CST' => '20100608 Nieuwsbrief 5',
		'1es6QxaPp2NqllplOrj0g_FoHepyVQ30B' => '20151216 Nieuwsbrief 22',
		'1FK0KsNqpnbDOgAEbbri_-wg6lpxlsl0u' => '20230625 Nieuwsbrief 37',
		'1G83LrAO04Kx7AjSqkXcjiBYJPDjEkWzc' => '20251130 Nieuwsbrief 40',
		'1gsUmWIKaL7FHR4mTX9szVCqJ58AgOkAm' => '20100503 Nieuwsbrief 3',
		'1GvDTnKqd2Tf32ufaflnvQGHKxvSjQ5mY' => '20150112 Nieuwsbrief 20',
		'1i3iOn8rRKRuZ-bkxqMGWgex5As8kWJl7' => '20191101 Nieuwsbrief 29',
		'1IE2u8Ppq3Q8TqQnCCPomM4XVELuVkSEm' => '20101123 Nieuwsbrief 7',
		'1IrkP6EvBjiW4bYSp1aW8G6AF9csKZa7_' => '20130121 Nieuwsbrief 16',
		'1iY3_3kaovB5TUR7aJiTEfAinsq4R47L4' => '20171119 Nieuwsbrief',
		'1jHu89_sc6Cx4XdcJILN21SuWtQBXjsII' => '20120930 Nieuwsbrief 15',
		'1JzpA_rrEg_m_BnJOVmC30GdoNGJ_qy9Q' => '20100526 Nieuwsbrief 4',
		'1KIHDoEoNKy4aRNRpijwrRdWZED4up4pP' => '20200208 Nieuwsbrief 30',
		'1KmmYUoNY0KTvPNfyO0EN6fbm_OI40zVr' => '20140429 Nieuwsbrief 19',
		'1kz4pD5QWqFzys0QBia5-ehqdL4C5jpLq' => '20100208 Nieuwsbrief 1',
		'1lyq6vyJe2KSEUpN42cd6UFf-ER0bGqNb' => '20110117 Nieuwsbrief 8',
		'1mjSIYbQXahp43A28XDab1yJO4CzcZhzI' => '20190608 Nieuwsbrief',
		'1Q09-M_GQzsynwKUUpevUiizfIwGGDz_1' => '20120417 Nieuwsbrief 13',
		'1QDmFXIlr5H4Zr5t9C7nBIVRpuRB4ga-r' => '20170521 Nieuwsbrief 25',
		'1r6WsveLIAElbLENTKHHLinSbDw8kw35R' => '20200605 Nieuwsbrief 31',
		'1SjMs7sd5O1jAweVth79l8grtUImCWZJo' => '20100909 Nieuwsbrief 6',
		'1UmeUh0mzgmbNSOFROCxfgAY4Y92gGkEO' => '20111213 Nieuwsbrief 12',
		'1UQbWM0_gFbDXyj6d81h5OxWsOyekdsAb' => '20120620 Nieuwsbrief 14',
		'1v2FbAcROF-CWeV_M-Uo9-tLpJiKlVrbn' => '20241230 Nieuwsbrief 39',
		'1WIJFRN2lTKIhkIpJHDe9RH8jwp3Ns3Xe' => '20150624 Nieuwsbrief 21',
		'1XYp-KIPVH9YHS15-Q1qbTHiULbqXXUzh' => '20110922 Nieuwsbrief 11',
		'1YhOilII_s0HvH6Ro8eejifOMsld7xd_4' => '20110328 Nieuwsbrief 9',
		'12VDKs30I-owUDry0utbMTjbLrRh_9FYB' => '20230115 Nieuwsbrief 36',
		'13utwEv3XvQBZs3aoHy9hXifyCxuQJ7Uj' => '20130604 Nieuwsbrief 17',
		'14DYF-Yk8VPpwR2WnsDowv6Ht0wo77o7P' => '20260301 Nieuwsbrief 41',
		'14R78naMy_gn-fcSfosqW5ME3Ps3RsrYs' => '20180412 Nieuwsbrief',
		'15BC4icGXO9xMG5VhNWdbw29nkBbiEc-d' => '20240602 Nieuwsbrief 38',
		'16qoNtp2BiWjhy-sj5gL4p6Zw3QViYgLi' => '20161221 Nieuwsbrief 24',
		'17G7ADEPqWlE29ynKj-mgitB8AE6pITaK' => '20140225 Nieuwsbrief 18',
		'19K8D6dwCBSdq1hsgHeo-LRmIUAwqEYkG' => '20210706 Nieuwsbrief 34',
	);

	/**
	 * Registers the "nieuwsbrief_image" AJAX endpoint. Members-only: no
	 * `wp_ajax_nopriv_*` counterpart is registered.
	 */
	public function __construct() {
		add_action( 'wp_ajax_nieuwsbrief_image', array( self::class, 'handle_ajax' ) );
	}

	/**
	 * Handles errors for the "nieuwsbrief_image" AJAX endpoint.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		Helpers::ajax_wrapper( array( self::class, 'ajax_handler_body' ) );
	}

	/**
	 * Actually handles the "nieuwsbrief_image" AJAX endpoint.
	 *
	 * Resolves the requested Drive file to its current thumbnailLink, asks Drive
	 * to render it at the requested width, and streams the result through the
	 * website server.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public static function ajax_handler_body() {
		if ( ! is_user_logged_in() ) {
			http_response_code( 403 );
			die;
		}

		$file_id = GET_Helpers::get_string_variable( 'id' );
		$width   = GET_Helpers::get_string_variable( 'w' );

		if ( '' === $file_id ) {
			http_response_code( 400 );
			die;
		}

		if ( ! self::is_allowed_file( $file_id ) ) {
			http_response_code( 403 );
			die;
		}

		$url = self::resolve_thumbnail_url( $file_id, $width );

		if ( '' === $url ) {
			http_response_code( 404 );
			die;
		}

		self::stream_image( $url );
	}

	/**
	 * Whether the given Drive file lives directly inside one of the
	 * allowed Nieuwsbrief edition folders.
	 *
	 * @param string $file_id Google Drive file ID.
	 *
	 * @return bool
	 */
	private static function is_allowed_file( $file_id ) {
		list( $parents ) = API_Client::execute( array( API_Facade::get_file_parents( $file_id ) ) );

		return array() !== array_intersect( $parents, array_keys( self::ALLOWED_PARENT_IDS ) );
	}

	/**
	 * Resolves a Drive file to its thumbnailLink, optionally re-sized to
	 * the requested width via Drive's own thumbnail rendering.
	 *
	 * @param string $file_id Google Drive file ID.
	 * @param string $width   Requested width in pixels, or ''.
	 *
	 * @return string The (possibly resized) thumbnail URL, or '' if unavailable.
	 */
	private static function resolve_thumbnail_url( $file_id, $width ) {
		list( $file ) = API_Client::execute( array( API_Facade::get_file( $file_id, array( 'thumbnailLink' ) ) ) );
		$url          = isset( $file['thumbnailLink'] ) ? (string) $file['thumbnailLink'] : '';

		if ( '' === $url ) {
			return '';
		}

		if ( '' !== $width && is_numeric( $width ) ) {
			$url = preg_replace( '/=s\d+[^=]*$/', '', $url ) . '=s' . intval( $width );
		}

		return $url;
	}

	/**
	 * Fetches the image at the given URL and streams it through to the
	 * client, preserving its content type.
	 *
	 * @param string $url Image URL (a Drive thumbnailLink).
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	private static function stream_image( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			http_response_code( 502 );
			die;
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( is_array( $content_type ) ) {
			$content_type = $content_type[0] ?? 'application/octet-stream';
		}

		header( 'Content-Type: ' . $content_type );
		header( 'Cache-Control: private, max-age=86400' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image data, not HTML.
		echo wp_remote_retrieve_body( $response );
		die;
	}
}
