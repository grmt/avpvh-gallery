<?php
/**
 * PHPUnit bootstrap file
 *
 * @package avpvh-gallery
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Tells WordPress core's own bundled test bootstrap to use the Polyfills'
// compat shims instead of its baked-in phpunit6/compat.php, which references
// PHPUnit classes removed in PHPUnit 10.
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- this is the documented mechanism yoast/phpunit-polyfills uses to signal WP core's test bootstrap; there's no WordPress API equivalent.
putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( '' === $_tests_dir || false === $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?";
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 *
 * @return void
 */
$_manually_load_plugin = static function () {
	require dirname( __DIR__ ) . '/dist/avpvh-gallery.php';
};
tests_add_filter( 'muplugins_loaded', $_manually_load_plugin );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
