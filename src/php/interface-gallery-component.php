<?php
/**
 * Contains the Gallery_Component interface.
 *
 * @package avpvh-gallery
 */

namespace Avpvh;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * A part of the plugin that hooks itself into WordPress. Creating one has no
 * side effects; register() adds its hooks (and creates the parts it owns).
 */
interface Gallery_Component {

	/**
	 * Adds the component's hooks.
	 */
	public function register(): void;
}
