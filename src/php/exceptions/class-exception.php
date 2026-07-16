<?php
/**
 * Contains the Exception class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Exception as Base_Exception;

/**
 * Plugin exception
 */
abstract class Exception extends Base_Exception {

}
