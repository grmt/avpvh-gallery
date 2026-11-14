<?php
/**
 * Contains the Drive_Path_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use RuntimeException;

/**
 * A Drive path that can't be resolved: invalid, not found or ambiguous.
 */
final class Drive_Path_Exception extends RuntimeException {}
