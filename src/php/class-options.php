<?php
/**
 * Contains the Options class
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Admin\Asset_Set_Option;
use Avpvh\Admin\Readonly_String_Option;
use Avpvh\Frontend\Boolean_Option;
use Avpvh\Frontend\Bounded_Integer_Option;
use Avpvh\Frontend\Code_String_Option;
use Avpvh\Frontend\Integer_Option;
use Avpvh\Frontend\Ordering_Option;
use Avpvh\Frontend\Root_Path_Option;
use Avpvh\Frontend\String_Option;

require_once __DIR__ . '/frontend/class-boolean-option.php';
require_once __DIR__ . '/frontend/class-integer-option.php';
require_once __DIR__ . '/frontend/class-bounded-integer-option.php';
require_once __DIR__ . '/frontend/class-string-option.php';
require_once __DIR__ . '/frontend/class-code-string-option.php';
require_once __DIR__ . '/frontend/class-array-option.php';
require_once __DIR__ . '/frontend/class-ordering-option.php';
require_once __DIR__ . '/frontend/class-root-path-option.php';
require_once __DIR__ . '/admin/class-readonly-string-option.php';
require_once __DIR__ . '/admin/class-asset-set-option.php';

/**
 * A container for all the configuration of the plugin.
 *
 * Contains all the options for the plugin as static properties.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.LongVariable")
 * @SuppressWarnings("PHPMD.TooManyFields")
 *
 * phpcs:disable SlevomatCodingStandard.Classes.ForbiddenPublicProperty.ForbiddenPublicProperty
 */
final class Options {

	/**
	 * Gallery icon and branding set: auto, branded or neutral.
	 *
	 * @var Asset_Set_Option $asset_set
	 */
	public static $asset_set;

	/**
	 * Shows the authorized domain which the user needs for registering the Google app.
	 *
	 * @var Readonly_String_Option $authorized_domain
	 */
	public static $authorized_domain;

	/**
	 * Shows the authorized JavaScript origin which the user needs for registering the Google app.
	 *
	 * @var Readonly_String_Option $authorized_origin
	 */
	public static $authorized_origin;

	/**
	 * Shows the authorized redirect URI which the user needs for registering the Google app.
	 *
	 * @var Readonly_String_Option $redirect_uri
	 */
	public static $redirect_uri;

	/**
	 * The client ID of the Google app.
	 *
	 * @var Code_String_Option $client_id
	 */
	public static $client_id;

	/**
	 * The client secret of the Google app.
	 *
	 * @var Code_String_Option $client_secret
	 */
	public static $client_secret;

	/**
	 * The root path of the plugin. This is the only directory the plugin should ever touch.
	 *
	 * @var Root_Path_Option $root_path
	 */
	public static $root_path;

	/**
	 * The height of a row in the image grid.
	 *
	 * @var Bounded_Integer_Option $grid_height
	 */
	public static $grid_height;

	/**
	 * Item spacing in the image grid.
	 *
	 * @var Integer_Option $grid_spacing
	 */
	public static $grid_spacing;

	/**
	 * Directory title size, including CSS units.
	 *
	 * @var String_Option $dir_title_size
	 */
	public static $dir_title_size;

	/**
	 * Whether to show directory item counts. Accepts `true`, `false`.
	 *
	 * @var Boolean_Option $dir_counts
	 */
	public static $dir_counts;

	/**
	 * Number of items per 1 page.
	 *
	 * @var Bounded_Integer_Option $page_size
	 */
	public static $page_size;

	/**
	 * Whether to autoload new images. Accepts `true`, `false`.
	 *
	 * @var Boolean_Option $page_autoload
	 */
	public static $page_autoload;

	/**
	 * How to order images and videos in the gallery.
	 *
	 * @var Ordering_Option $image_ordering
	 */
	public static $image_ordering;

	/**
	 * How to order directories in the gallery.
	 *
	 * @var Ordering_Option $dir_ordering
	 */
	public static $dir_ordering;

	/**
	 * A prefix separator to cut a prefix from the start of all directory names.
	 *
	 * @var String_Option $dir_prefix
	 */
	public static $dir_prefix;

	/**
	 * Maximum size of an image in the lightbox.
	 *
	 * @var Bounded_Integer_Option $preview_size
	 */
	public static $preview_size;

	/**
	 * Lightbox animation speed.
	 *
	 * @var Bounded_Integer_Option $preview_speed
	 */
	public static $preview_speed;

	/**
	 * Whether to show lightbox navigation arrows.
	 *
	 * @var Boolean_Option $preview_arrows
	 */
	public static $preview_arrows;

	/**
	 * Whether to show lightbox close button.
	 *
	 * @var Boolean_Option $preview_close_button
	 */
	public static $preview_close_button;

	/**
	 * Whether to loop the images in the lightbox.
	 *
	 * @var Boolean_Option $preview_loop
	 */
	public static $preview_loop;

	/**
	 * Whether to show an activity indicator while the lightbox is loading.
	 *
	 * @var Boolean_Option $preview_activity_indicator
	 */
	public static $preview_activity_indicator;

	/**
	 * Whether to show image captions in the lightbox.
	 *
	 * @var Boolean_Option $preview_captions
	 */
	public static $preview_captions;

	/**
	 * Options class initializer.
	 *
	 * Initializes all the properties of this class. Serves as a sort-of static constructor.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
	 */
	public static function init() {
		self::$asset_set         = new Asset_Set_Option(
			'asset_set',
			'auto',
			'advanced',
			'appearance',
			esc_html__( 'Icon set', 'avpvh-gallery' )
		);
		$url                     = wp_parse_url( get_site_url() );
		self::$authorized_domain = new Readonly_String_Option(
			'authorized_domain',
			$url['host'],
			'basic',
			'auth',
			esc_html__( 'Authorised domain', 'avpvh-gallery' )
		);
		self::$authorized_origin = new Readonly_String_Option(
			'origin',
			$url['scheme'] . '://' . $url['host'],
			'basic',
			'auth',
			esc_html__( 'Authorised JavaScript origin', 'avpvh-gallery' )
		);
		self::$redirect_uri      = new Readonly_String_Option(
			'redirect_uri',
			esc_url_raw( admin_url( 'admin.php?page=avpvh_basic&action=oauth_redirect' ) ),
			'basic',
			'auth',
			esc_html__( 'Authorised redirect URI', 'avpvh-gallery' )
		);
		self::$client_id         = new Code_String_Option(
			'client_id',
			'',
			'basic',
			'auth',
			esc_html__( 'Client ID', 'avpvh-gallery' )
		);
		self::$client_secret     = new Code_String_Option(
			'client_secret',
			'',
			'basic',
			'auth',
			esc_html__( 'Client secret', 'avpvh-gallery' ),
			true
		);

		self::$root_path = new Root_Path_Option( 'root_path', array( 'root' ), 'basic', 'root_selection', '' );

		self::$grid_height    = new Bounded_Integer_Option(
			'grid_height',
			250,
			1,
			'advanced',
			'grid',
			esc_html__( 'Row height', 'avpvh-gallery' )
		);
		self::$grid_spacing   = new Integer_Option(
			'grid_spacing',
			4,
			'advanced',
			'grid',
			esc_html__( 'Item spacing', 'avpvh-gallery' )
		);
		self::$dir_title_size = new String_Option(
			'dir_title_size',
			'1.2em',
			'advanced',
			'grid',
			esc_html__( 'Directory title size', 'avpvh-gallery' )
		);
		self::$dir_counts     = new Boolean_Option(
			'dir_counts',
			true,
			'advanced',
			'grid',
			esc_html__( 'Directory item counts', 'avpvh-gallery' )
		);
		self::$page_size      = new Bounded_Integer_Option(
			'page_size',
			50,
			1,
			'advanced',
			'grid',
			esc_html__( 'Items per page', 'avpvh-gallery' )
		);
		self::$page_autoload  = new Boolean_Option(
			'page_autoload',
			true,
			'advanced',
			'grid',
			esc_html__( 'Autoload new images', 'avpvh-gallery' )
		);
		self::$image_ordering = new Ordering_Option(
			'image_ordering',
			'time',
			'ascending',
			'advanced',
			'grid',
			esc_html__( 'Image and video ordering', 'avpvh-gallery' )
		);
		self::$dir_ordering   = new Ordering_Option(
			'dir_ordering',
			'time',
			'descending',
			'advanced',
			'grid',
			esc_html__( 'Directory ordering', 'avpvh-gallery' )
		);
		self::$dir_prefix     = new String_Option(
			'dir_prefix',
			'',
			'advanced',
			'grid',
			esc_html__( 'In folder names, hide everything before the first occurence of', 'avpvh-gallery' )
		);

		self::$preview_size               = new Bounded_Integer_Option(
			'preview_size',
			1920,
			1,
			'advanced',
			'lightbox',
			esc_html__( 'Image size', 'avpvh-gallery' )
		);
		self::$preview_speed              = new Bounded_Integer_Option(
			'preview_speed',
			250,
			0,
			'advanced',
			'lightbox',
			esc_html__( 'Animation speed (ms)', 'avpvh-gallery' )
		);
		self::$preview_arrows             = new Boolean_Option(
			'preview_arrows',
			true,
			'advanced',
			'lightbox',
			esc_html__( 'Navigation arrows', 'avpvh-gallery' )
		);
		self::$preview_close_button       = new Boolean_Option(
			'preview_closebutton',
			true,
			'advanced',
			'lightbox',
			esc_html__( 'Close button', 'avpvh-gallery' )
		);
		self::$preview_loop               = new Boolean_Option(
			'preview_loop',
			false,
			'advanced',
			'lightbox',
			esc_html__( 'Loop images', 'avpvh-gallery' )
		);
		self::$preview_activity_indicator = new Boolean_Option(
			'preview_activity',
			true,
			'advanced',
			'lightbox',
			esc_html__( 'Activity indicator', 'avpvh-gallery' )
		);
		self::$preview_captions           = new Boolean_Option(
			'preview_captions',
			true,
			'advanced',
			'lightbox',
			esc_html__( 'Show captions', 'avpvh-gallery' )
		);
	}
}
