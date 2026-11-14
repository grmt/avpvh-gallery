<?php
/**
 * Contains the Tag_Import_Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use RuntimeException;

/**
 * A tag import row that can't be imported (unknown user or tag, failed insert).
 */
final class Tag_Import_Exception extends RuntimeException {

	// Only the type is new: it says where the failure came from.
}
