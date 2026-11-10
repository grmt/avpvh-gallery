<?php
/**
 * @package wordpress-stubs
 */

declare(strict_types = 1);

abstract class WP_Ajax_UnitTestCase extends WP_UnitTestCase {

	/**
	 * Output of the last AJAX handler run.
	 *
	 * @var string
	 */
	protected $_last_response = '';

	/**
	 * Runs an AJAX action's handlers.
	 *
	 * @param string $action The action name.
	 * @return void
	 */
	protected function _handleAjax( $action ) {}
}

class WPAjaxDieContinueException extends Exception {}
