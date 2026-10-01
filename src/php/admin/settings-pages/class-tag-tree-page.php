<?php
/**
 * Contains the Tag_Tree_Page class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Settings_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * The "Tags" admin page: edit the subject-tag tree (sections, groups, tags)
 * used by the lightbox's tagging panel and the gallery filter — rename,
 * add, move, reorder, remove, and set a group to one tag per photo. The
 * editing itself happens in the browser (admin/js/tag-tree.min.js) against
 * Tag_Tree_REST.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Tag_Tree_Page {

	/**
	 * Registers the page and its script.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( self::class, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_script' ) );
	}

	/**
	 * Adds "Tags" to the gallery's admin menu.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'avpvh_basic',
			esc_html__( 'Tags', 'avpvh-gallery' ),
			esc_html__( 'Tags', 'avpvh-gallery' ),
			'manage_options',
			'avpvh_tag_tree',
			array( self::class, 'render' )
		);
	}

	/**
	 * Loads the editor script on this page only.
	 *
	 * @param string $hook_suffix The current admin page.
	 *
	 * @return void
	 */
	public static function enqueue_script( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'avpvh_tag_tree' ) ) {
			return;
		}

		$path = plugin_dir_path( __FILE__ ) . '../../admin/js/tag-tree.min.js';
		wp_enqueue_script(
			'avpvh-tag-tree',
			plugin_dir_url( __FILE__ ) . '../../admin/js/tag-tree.min.js',
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : '1',
			true
		);
		wp_localize_script(
			'avpvh-tag-tree',
			'avpvhTagTree',
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'url'   => rest_url( 'avpvh-gallery/v1/tag-tree' ),
			)
		);
	}

	/**
	 * The page: a container the script fills.
	 *
	 * @return void
	 */
	public static function render() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tags', 'avpvh-gallery' ); ?></h1>
			<p>
				<?php
				esc_html_e( 'De tags in het tagpaneel van de lightbox.', 'avpvh-gallery' );
				echo ' ';
				esc_html_e( "Hernoemen en verplaatsen verandert niets aan getagde foto's;", 'avpvh-gallery' );
				echo ' ';
				esc_html_e( "verwijderen haalt de tag ook van de foto's af.", 'avpvh-gallery' );
				?>
			</p>
			<div id="avpvh-tag-tree"><?php esc_html_e( 'Laden…', 'avpvh-gallery' ); ?></div>
		</div>
		<?php
	}
}
