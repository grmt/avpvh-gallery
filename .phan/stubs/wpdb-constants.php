<?php
/**
 * Wpdb's query-return-format constants (defined by WordPress core in
 * wp-includes/wp-db.php), missing from both wordpress-stubs packages
 * since they're plain top-level define() calls, not function/class
 * signatures. This file is only scanned for static analysis; it's never
 * required by the plugin itself, so redeclaring WordPress core's own
 * (unprefixed) constant names here is safe and unavoidable.
 *
 * @package avpvh-gallery
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- redeclaring WordPress core's own reserved constant names for static-analysis purposes only; see file docblock.
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
