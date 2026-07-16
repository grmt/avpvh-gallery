<?php
/**
 * Contains the Asset_Set_Option class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Avpvh\Frontend\Option;
use Avpvh\Main;

require_once __DIR__ . '/../frontend/class-option.php';

/**
 * Selects and previews the gallery's visual asset set.
 */
final class Asset_Set_Option extends Option {

	/**
	 * Registers the option with WordPress.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			$this->page,
			$this->name,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
				'type'              => 'string',
			)
		);
	}

	/**
	 * Accepts only known asset-set identifiers.
	 *
	 * @param mixed $value Submitted option value.
	 * @return string
	 */
	public function sanitize( $value ) {
		return in_array( $value, array( 'auto', 'branded', 'neutral' ), true ) ? $value : 'auto';
	}

	/**
	 * Renders the selector and current active-set preview.
	 *
	 * @return void
	 */
	public function html() {
		$selected = (string) get_option( $this->name, $this->default_value );
		$active   = Main::uses_branded_assets() ? 'branded' : 'neutral';
		$sets     = array(
			'auto'    => array(
				'description' => __(
					'Use AVPvH icons on avphilipsvanhorne.nl and neutral icons elsewhere.',
					'avpvh-gallery'
				),
				'label'       => __( 'Automatic', 'avpvh-gallery' ),
			),
			'branded' => array(
				'description' => __( 'Trowel navigation, wheelbarrow loader and AVPvH site icons.', 'avpvh-gallery' ),
				'label'       => 'AVPvH',
			),
			'neutral' => array(
				'description' => __(
					'Generic navigation arrow and loading ring; keep the website logo and favicon.',
					'avpvh-gallery'
				),
				'label'       => __( 'Neutral', 'avpvh-gallery' ),
			),
		);

		echo '<div class="avpvh-asset-set-current">';
		echo '<strong>' . esc_html__( 'Currently active:', 'avpvh-gallery' ) . ' ' . esc_html(
			$sets[ $active ]['label']
		) . '</strong>';
		self::preview( $active );

		if ( 'auto' === $selected ) {
			echo '<p class="description">' . esc_html__(
				'Selected automatically for this website.',
				'avpvh-gallery'
			) . '</p>';
		}

		echo '</div><div class="avpvh-asset-set-options">';

		foreach ( $sets as $value => $set ) {
			echo '<label class="avpvh-asset-set-card">';
			echo '<span><input type="radio" name="' . esc_attr( $this->name ) . '" value="' . esc_attr( $value ) . '" ';
			checked( $selected, $value );
			echo '> <strong>' . esc_html( $set['label'] ) . '</strong></span>';

			if ( 'auto' === $value ) {
				self::preview( $active );
			} else {
				self::preview( $value );
			}

			echo '<span class="description">' . esc_html( $set['description'] ) . '</span>';
			echo '</label>';
		}

		echo '</div>';
		self::styles();
	}

	/**
	 * Renders navigation and loader previews for one set.
	 *
	 * @param string $set Asset-set identifier.
	 * @return void
	 */
	private static function preview( $set ) {
		$branded = 'branded' === $set;
		$nav     = plugins_url( '/avpvh-gallery/frontend/images/' . ( $branded ? 'troffel.svg' : 'navigation.svg' ) );
		$loader  = plugins_url( '/avpvh-gallery/frontend/images/' . ( $branded ? 'kruiwagen.svg' : 'loading.svg' ) );
		echo '<span class="avpvh-asset-set-preview">';
		echo '<span><img src="' . esc_url( $nav ) . '" alt="">' .
			esc_html__( 'Navigation', 'avpvh-gallery' ) . '</span>';
		echo '<span><img src="' . esc_url( $loader ) . '" alt="">' .
			esc_html__( 'Loading', 'avpvh-gallery' ) . '</span>';
		echo '</span>';
	}

	/**
	 * Adds the small, page-local preview layout.
	 *
	 * @return void
	 */
	private static function styles() {
		echo '<style>
			.avpvh-asset-set-current{background:#f0f6fc;border-left:4px solid #2271b1;
				margin-bottom:14px;padding:12px 14px}
			.avpvh-asset-set-options{display:grid;gap:10px;max-width:780px}
			.avpvh-asset-set-card{align-items:center;background:#fff;border:1px solid #c3c4c7;
				border-radius:4px;cursor:pointer;display:grid;gap:8px;
				grid-template-columns:130px minmax(220px,max-content) 1fr;padding:10px 12px}
			.avpvh-asset-set-card:has(input:checked){border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
			.avpvh-asset-set-preview{display:inline-flex;gap:14px;margin:7px 0;vertical-align:middle}
			.avpvh-asset-set-preview span{align-items:center;color:#50575e;
				display:inline-flex;font-size:11px;gap:5px}
			.avpvh-asset-set-preview img{height:34px;object-fit:contain;width:42px}
			@media(max-width:782px){.avpvh-asset-set-card{grid-template-columns:1fr}}
		</style>';
	}
}
