<?php
/**
 * Contains the Share_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use RuntimeException;

/**
 * Sharing photos failed: reading, uploading or writing a file, or an unclear Drive folder.
 */
final class Share_Exception extends RuntimeException {}
