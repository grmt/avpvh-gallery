<?php
/**
 * Contains the Main class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

use Avpvh\Admin\Settings_Pages;
use Avpvh\Admin\TinyMCE_Plugin;
use Avpvh\Frontend\Block;
use Avpvh\Frontend\Gallery;
use Avpvh\Frontend\Members_API;
use Avpvh\Frontend\Page;
use Avpvh\Frontend\Photo_Tags;
use Avpvh\Frontend\Shortcode;
use Avpvh\Frontend\Video_Proxy;
use const PHP_URL_HOST;

/**
 * Main plugin class.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Main {

	/**
	 * Initializes the plugin
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array( self::class, 'activate' ) );
		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );
		add_action( 'init', array( '\\Avpvh\\Options', 'init' ), 1 );
		add_action( 'admin_notices', array( self::class, 'activation_notice' ) );
		add_action( 'init', array( '\\Avpvh\\Photo_Corrections_DB', 'maybe_migrate' ) );
		add_filter( 'get_site_icon_url', array( self::class, 'filter_site_icon_url' ), 10, 3 );
		add_filter( 'get_custom_logo', array( self::class, 'filter_custom_logo' ), 10, 2 );
		new Shortcode();
		new Block();
		new Page();
		new Gallery();
		new Video_Proxy();
		new Photo_Tags();
		new Members_API();
		new Settings_Pages();
		new TinyMCE_Plugin();
	}

	/**
	 * Loads the plugin textdomain on the init hook.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'avpvh-gallery', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Uses the rasterized trowel for browser and device icons.
	 *
	 * @param string $url     Current site icon URL.
	 * @param int    $size    Requested square icon size.
	 * @param int    $blog_id Site ID.
	 * @return string
	 */
	public static function filter_site_icon_url( $url, $size, $blog_id ) {
		unset( $blog_id );

		if ( ! self::uses_branded_assets() ) {
			return $url;
		}

		$available_sizes = array( 32, 150, 180, 192, 270, 512 );
		$selected_size   = 512;

		foreach ( $available_sizes as $available_size ) {
			if ( (int) $size <= $available_size ) {
				$selected_size = $available_size;

				break;
			}
		}

		return self::trowel_png_url( $selected_size );
	}

	/**
	 * Replaces the uploaded raster logo while retaining theme dimensions and alt text.
	 *
	 * @param string $html    Current custom logo HTML.
	 * @param int    $blog_id Site ID.
	 * @return string
	 */
	public static function filter_custom_logo( $html, $blog_id ) {
		unset( $blog_id );

		if ( '' === $html || ! self::uses_branded_assets() ) {
			return $html;
		}

		$filtered_html = preg_replace( '/\\s+(?:srcset|sizes)=(\"|\\\').*?\\1/i', '', $html );

		if ( null === $filtered_html ) {
			return $html;
		}

		$filtered_html = preg_replace(
			'/\\s+src=(\"|\\\').*?\\1/i',
			' src="' . esc_url( self::trowel_png_url( 150 ) ) . '"',
			$filtered_html,
			1
		);

		return null === $filtered_html ? $html : $filtered_html;
	}

	/**
	 * Whether this site should use the AVPvH-specific visual assets.
	 *
	 * @return bool
	 */
	public static function uses_branded_assets() {
		$host       = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$brand_host = 'avphilipsvanhorne.nl';
		$setting    = (string) get_option( 'avpvh_asset_set', 'auto' );
		$branded    = 'branded' === $setting || (
			'neutral' !== $setting && ( $host === $brand_host || str_ends_with( $host, '.' . $brand_host ) )
		);

		return (bool) apply_filters( 'avpvh_gallery_use_branded_assets', $branded, $host, $setting );
	}

	/**
	 * Plugin activation function
	 *
	 * This function is called on plugin activation (i.e. usually once right after the user has installed the plugin). It checks whether the version of PHP and WP is sufficient and deactivates the plugin if they aren't.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! isset( $GLOBALS['wp_version'] ) || version_compare( $GLOBALS['wp_version'], '6.5', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'Google Drive gallery requires at least WordPress 6.5', 'avpvh-gallery' )
			);
		}

		if ( version_compare( phpversion(), '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Google Drive gallery requires at least PHP 8.1', 'avpvh-gallery' ) );
		}

		// Create photo tagging tables.
		Photo_Tags_DB::create_tables();
		Photo_Corrections_DB::create_tables();

		set_transient( 'avpvh_activation_notice', true, 30 );
	}

	/**
	 * Renders the post-activation notice
	 *
	 * This function is called after the plugin has been successfully activated and points the user to the docs.
	 *
	 * @return void
	 */
	public static function activation_notice() {
		if ( false === get_transient( 'avpvh_activation_notice' ) ) {
			return;
		}

		echo '<div class="notice notice-info is-dismissible"><p>';
		$help_link = 'https://github.com/grmt/avpvh-gallery';
		printf(
			/* translators: 1: Start of a link to the settings 2: End of the link to the settings 3: Start of a help link 4: End of the help link */
			esc_html__(
                // phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
				'Google Drive gallery needs to be %1$sconfigured%2$s before it can be used. See the %3$sdocumentation%4$s for more information.',
				'avpvh-gallery'
			),
			'<a href="' . esc_url( admin_url( 'admin.php?page=avpvh_basic' ) ) . '">',
			'</a>',
			'<a href="' . esc_url( $help_link ) . '" target="_blank">',
			'</a>'
		);
		echo '</p></div>';
		delete_transient( 'avpvh_activation_notice' );
	}

	/**
	 * Returns a built-in trowel PNG URL.
	 *
	 * @param int $size Square image size.
	 * @return string
	 */
	private static function trowel_png_url( $size ) {
		return plugins_url( '/avpvh-gallery/frontend/images/troffel-' . (int) $size . '.png' );
	}
}
