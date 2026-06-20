<?php
/**
 * Contains the Shortcode class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

use Avpvh\API_Client;
use Avpvh\API_Facade;
use Avpvh\Exceptions\API_Exception;
use Avpvh\Exceptions\API_Rate_Limit_Exception;
use Avpvh\Exceptions\Directory_Not_Found_Exception;
use Avpvh\Exceptions\Exception as Avpvh_Exception;
use Avpvh\Exceptions\Internal_Exception;
use Avpvh\Exceptions\Not_Found_Exception;
use Avpvh\Exceptions\Plugin_Not_Authorized_Exception;
use Avpvh\Exceptions\Root_Not_Found_Exception;
use Avpvh\Frontend\Options_Proxy;
use Avpvh\Helpers;
use Avpvh\Options;
use Avpvh\Script_And_Style_Helpers;
use Avpvh\Vendor\GuzzleHttp\Promise\PromiseInterface;
use Avpvh\Vendor\GuzzleHttp\Promise\RejectedPromise;
use Exception as Base_Exception;
use const DAY_IN_SECONDS;

/**
 * Contains all the functions for the shortcode the plugin provides
 *
 * @phan-constructor-used-for-side-effects
 */
final class Shortcode {

	/**
	 * Registers all the hooks for the shortcode.
	 */
	public function __construct() {
		add_action( 'init', array( self::class, 'init' ) );
	}

	/**
	 * Registers all the scripts and styles used by the shortcode and adds the shortcode.
	 *
	 * @return void
	 */
	public static function init() {
		Script_And_Style_Helpers::register_script(
			'avpvh_gallery_init',
			'frontend/js/shortcode.min.js',
			array( 'jquery' )
		);
		Script_And_Style_Helpers::register_style( 'avpvh_gallery_css', 'frontend/css/shortcode.min.css' );

		Script_And_Style_Helpers::register_style( 'avpvh_photoswipe_style', 'bundled/photoswipe.min.css' );
		Script_And_Style_Helpers::register_script(
			'avpvh_imagesloaded',
			'bundled/imagesloaded.pkgd.min.js',
			array( 'jquery' )
		);
		Script_And_Style_Helpers::register_script( 'avpvh_justified-layout', 'bundled/justified-layout.min.js' );
		add_shortcode( 'avpvh', array( self::class, 'render' ) );
	}

	/**
	 * Renders the shortcode.
	 *
	 * This function is a wrapper around the `html()` function which converts a slash-delimited path into an array
	 *
	 * @see html()
	 * @see \Avpvh\Frontend\Options_Proxy
	 *
	 * @param array<string, mixed> $atts A list of option overrides, as documented in the Options_Proxy class plus the `path` attribute, which is a slash-delimited string.
	 *
	 * @return string The HTML code for the shortcode.
	 */
	public static function render( $atts ) {
		if ( isset( $atts['path'] ) && '' !== $atts['path'] ) {
			$atts['path'] = explode( '/', trim( $atts['path'], " /\t\n\r\0\x0B" ) );
		}

		try {
			return self::html( $atts );
		} catch ( Avpvh_Exception $e ) {
			return '<div class="avpvh-gallery-container">' . $e->getMessage() . '</div>';
			// @phpstan-ignore catch.neverThrown (Here for safety, even though it should never actually be thrownvariable.undefined)
		} catch ( Base_Exception $e ) {
			if ( Helpers::is_debug_display() ) {
				return '<div class="avpvh-gallery-container">' . $e->getMessage() . '</div>';
			}

			return '<div class="avpvh-gallery-container">' .
				esc_html__( 'Unknown error.', 'avpvh-gallery' ) .
				'</div>';
		}
	}

	/**
	 * Turns the shorcode into HTML.
	 *
	 * @see \Avpvh\Frontend\Options_Proxy
	 *
	 * @param array<string, mixed> $atts A list of option overrides, as documented in the Options_Proxy class plus the `path` attribute, which is an array of directory names.
	 *
	 * @return string The HTML code for the block.
	 *
	 * @throws API_Exception A wrapped API exception.
	 * @throws API_Rate_Limit_Exception Rate limit exceeded.
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Not_Found_Exception The requested resource couldn't be found.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Root_Not_Found_Exception The root directory of the gallery couldn't be found.
	 */
	public static function html( $atts ) {
		wp_enqueue_style( 'avpvh_photoswipe_style' );
		wp_enqueue_script( 'avpvh_imagesloaded' );
		wp_enqueue_script( 'avpvh_justified-layout' );

		$options = new Options_Proxy( $atts );

		wp_enqueue_script( 'avpvh_gallery_init' );
		Script_And_Style_Helpers::add_script_configuration(
			'avpvh_gallery_init',
			'avpvhShortcodeLocalize',
			array(
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'breadcrumbs_top'     => esc_html__( 'Gallery', 'avpvh-gallery' ),
				'empty_gallery'       => esc_html__( 'The gallery is empty.', 'avpvh-gallery' ),
				'error_header'        => esc_html__(
					'The AVPVH Gallery plugin has encountered an error. Error message:',
					'avpvh-gallery'
				),
				'error_trace_header'  => esc_html__( 'Stack trace:', 'avpvh-gallery' ),
				'grid_height'         => $options->get( 'grid_height' ),
				'grid_spacing'        => $options->get( 'grid_spacing' ),
				'load_more'           => esc_html__( 'Load more', 'avpvh-gallery' ),
				'page_autoload'       => $options->get( 'page_autoload' ),
				'preview_activity'    => $options->get( 'preview_activity_indicator' ),
				'preview_arrows'      => $options->get( 'preview_arrows' ),
				'preview_captions'    => $options->get( 'preview_captions' ),
				'preview_closebutton' => $options->get( 'preview_close_button' ),
				'preview_quitOnEnd'   => 'true' === $options->get( 'preview_loop' ) ? 'false' : 'true',
				'preview_speed'       => $options->get( 'preview_speed' ),
			)
		);
		wp_enqueue_style( 'avpvh_gallery_css' );
		wp_add_inline_style(
			'avpvh_gallery_css',
			'.avpvh-dir-name {font-size: ' . $options->get( 'dir_title_size' ) . ';}'
		);

		$root_path = Options::$root_path->get();
		$root      = end( $root_path );

		if ( isset( $atts['path'] ) && '' !== $atts['path'] && count( $atts['path'] ) > 0 ) {
			$root_promise = self::find_dir( $root, $atts['path'] );
			$root         = API_Client::execute( array( $root_promise ) )[0];
		}

		$hash = hash( 'sha256', $root );
		set_transient(
			'avpvh_hash_' . $hash,
			array(
				'overriden' => $options->export_overriden(),
				'root'      => $root,
			),
			30 * DAY_IN_SECONDS
		);

		return '<div class="avpvh-gallery-container" data-avpvh-hash="' .
			$hash .
			'"><div class="avpvh-loading"><div></div></div></div>';
	}

	/**
	 * Finds the ID of a the last directory in `$path` starting from `$root`.
	 *
	 * @param string        $root The ID of the root directory of the path.
	 * @param array<string> $path An array of directory names forming a path starting from $root and ending with the directory whose ID is to be returned.
	 *
	 * @return PromiseInterface The ID of the directory.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	private static function find_dir( $root, array $path ) {
		return API_Facade::get_directory_id( $root, $path[0] )->then(
			static function ( $next_dir_id ) use ( $path ) {
				if ( 1 === count( $path ) ) {
					return $next_dir_id;
				}

				array_shift( $path );

				return self::find_dir( $next_dir_id, $path );
			},
			static function ( $exception ) {
				if ( $exception instanceof Directory_Not_Found_Exception ) {
					return new RejectedPromise(
						new Root_Not_Found_Exception()
					);
				}

				return new RejectedPromise( $exception );
			}
		);
	}
}
