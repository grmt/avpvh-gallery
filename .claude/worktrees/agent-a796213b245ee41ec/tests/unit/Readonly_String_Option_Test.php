<?php
/**
 * Contains the Readonly_String_Option_Test class
 *
 * @package avpvh-gallery
 */

use Avpvh\Admin\Readonly_String_Option;

/**
 * Contains unit tests for the Readonly_String_Option class
 */
final class Readonly_String_Option_Test extends WP_UnitTestCase {

	/**
	 * Tests the constructor
	 *
	 * @covers Avpvh\Admin\Readonly_String_Option::__construct()
	 *
	 * @return void
	 */
	public function test_ctor() {
		$option = new Readonly_String_Option( 'name', 'value', 'page', 'section', 'title' );
		$this->assertInstanceOf( '\Avpvh\Admin\Readonly_String_Option', $option );
	}
}
