<?php
/**
 * Contains the Plugin_Not_Authorized_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Exceptions\Exception as Avpvh_Exception;

/**
 * The requested path doesn't exist in this gallery.
 */
final class Plugin_Not_Authorized_Exception extends Avpvh_Exception {

	/**
	 * Plugin_Not_Authorized_Exception class constructor
	 */
	public function __construct() {
		parent::__construct(
			sprintf(
				/* translators: 1: Start of link to the settings 2: End of link to the settings */
				esc_html__(
					// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
					'Google Drive gallery hasn\'t been granted permissions yet. If you are the website administrator, you can %1$sconfigure%2$s it in the plugin settings.',
					'avpvh-gallery'
				),
				'<a href="' . esc_url( admin_url( 'admin.php?page=avpvh_basic' ) ) . '">',
				'</a>'
			)
		);
	}
}
