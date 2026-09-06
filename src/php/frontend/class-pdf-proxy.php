<?php
/**
 * Contains the PDF_Proxy class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\GET_Helpers;
use Avpvh\Helpers;

/**
 * Contains all the functions used to handle the "nieuwsbrief_pdf" AJAX endpoint.
 *
 * Streams one of the original scanned/archived newsletter PDFs (stored under
 * uploads/private/, which nginx blocks from direct access unconditionally --
 * see class-media-protection.php's own header comment) through the website
 * server instead. Restricted to logged-in members and to a fixed allow-list
 * of relative paths, so this can't become a generic "read any file under
 * uploads/private/" oracle.
 *
 * @phan-constructor-used-for-side-effects
 */
final class PDF_Proxy {

	/**
	 * Relative paths (under wp-content/uploads/private/) of the newsletter
	 * PDFs this endpoint will serve.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PATHS = array(
		'2010/02/nieuwsbrief-avpvh-1.pdf',
		'2010/03/nieuwsbrief-avpvh-2.pdf',
		'2010/05/nieuwsbrief-avpvh-3.pdf',
		'2010/05/nieuwsbrief-avpvh-4.pdf',
		'2010/06/nieuwsbrief-avpvh-5.pdf',
		'2010/09/nieuwsbrief-avpvh-6.pdf',
		'2010/11/nieuwsbrief-avpvh-7.pdf',
		'2011/01/nieuwsbrief-avpvh-8.pdf',
		'2011/03/nieuwsbrief-avpvh-9.pdf',
		'2011/05/nieuwsbrief-avpvh-10.pdf',
		'2011/09/nieuwsbrief-avpvh-11.pdf',
		'2011/12/nieuwsbrief-avpvh-12.pdf',
		'2012/04/nieuwsbrief-avpvh-13.pdf',
		'2012/06/nieuwsbrief-avpvh-14.pdf',
		'2012/09/nieuwsbrief-avpvh-15.pdf',
		'2013/01/nieuwsbrief-avpvh-16.pdf',
		'2013/06/nieuwsbrief-avpvh-17.pdf',
		'2014/02/nieuwsbrief-avpvh-18.pdf',
		'2014/04/nieuwsbrief-avpvh-19.pdf',
		'2015/01/nieuwsbrief-avpvh-20.pdf',
		'2015/06/nieuwsbrief-avpvh-21.pdf',
		'2015/12/nieuwsbrief-avpvh-22.pdf',
		'2016/04/nieuwsbrief-avpvh-23.pdf',
		'2016/12/nieuwsbrief-avpvh-24.pdf',
		'2017/05/nieuwsbrief-avpvh-25.pdf',
		'2017/11/nieuwsbrief-avpvh-26.pdf',
		'2018/04/nieuwsbrief-avpvh-27.pdf',
		'2019/06/nieuwsbrief-avpvh-28.pdf',
		'2019/11/nieuwsbrief-avpvh-29.pdf',
		'2020/02/nieuwsbrief-avpvh-30.pdf',
		'2020/06/nieuwsbrief-avpvh-31.pdf',
		'2020/11/nieuwsbrief-avpvh-32.pdf',
		'2021/02/nieuwsbrief-avpvh-33.pdf',
		'2021/06/nieuwsbrief-avpvh-34.pdf',
		'2021/12/nieuwsbrief-avpvh-35.pdf',
		'2023/01/nieuwsbrief-avpvh-36.pdf',
		'2023/06/nieuwsbrief-avpvh-37.pdf',
	);

	/**
	 * Registers the "nieuwsbrief_pdf" AJAX endpoint. Members-only: no
	 * `wp_ajax_nopriv_*` counterpart is registered.
	 */
	public function __construct() {
		add_action( 'wp_ajax_nieuwsbrief_pdf', array( self::class, 'handle_ajax' ) );
	}

	/**
	 * Handles errors for the "nieuwsbrief_pdf" AJAX endpoint.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		Helpers::ajax_wrapper( array( self::class, 'ajax_handler_body' ) );
	}

	/**
	 * Actually handles the "nieuwsbrief_pdf" AJAX endpoint: streams the
	 * requested PDF from local disk.
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

		$path = GET_Helpers::get_string_variable( 'path' );
		if ( ! in_array( $path, self::ALLOWED_PATHS, true ) ) {
			http_response_code( 403 );
			die;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = str_replace( '/wp-content/', '/wp-content-pvh/', $upload_dir['basedir'] );
		$full_path  = $base_dir . '/private/' . $path;

		if ( ! file_exists( $full_path ) ) {
			http_response_code( 404 );
			die;
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . basename( $full_path ) . '"' );
		header( 'Content-Length: ' . filesize( $full_path ) );
		ob_end_clean();
		readfile( $full_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a local, allow-listed file; no remote/user-controlled path.
		die;
	}
}
