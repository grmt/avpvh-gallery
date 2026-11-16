<?php
/**
 * Administrative folder helpers preserve empty paths and shortcut targets.
 *
 * @package avpvh-gallery
 */

use Avpvh\Admin\Exif_Dates_CLI;
use Avpvh\Admin\Settings_Pages\Basic\Root_Selection\List_Ajax_Endpoint;
use Avpvh\Admin\TinyMCE_Plugin;
use Avpvh\API_Client;
use Avpvh\Frontend\Page\Directories;
use Avpvh\Tests\Google_Transport;
use Avpvh\Vendor\Psr\Http\Message\RequestInterface;

require_once dirname( __DIR__, 2 ) . '/dist/admin/class-exif-dates-cli.php';
require_once dirname( __DIR__ ) . '/fixtures/class-google-transport.php';

/** Verifies the results used by the editor, folder picker and EXIF walker. */
final class Admin_Path_Test extends WP_UnitTestCase {

	/**
	 * Empty folders have no subfolder preview; populated folders use a small thumbnail.
	 *
	 * @return void
	 */
	public function test_subfolder_previews_handle_empty_and_populated_folders() {
		foreach ( array( array(), array( array( 'thumbnailLink' => 'https://lh3.googleusercontent.com/fixture=s220' ) ) ) as $files ) {
			Google_Transport::run(
				static function ( RequestInterface $request ) use ( $files ) {
					return Google_Transport::batch( $request, 200, array( 'files' => $files ) );
				},
				function () use ( $files ) {
					$method  = new ReflectionMethod( Directories::class, 'subdir_thumbnail_images' );
					$promise = $method->invoke( null, array( 'fixture-folder' ) );
					$this->assertSame(
						array( array( array() === $files ? false : 'https://lh3.googleusercontent.com/fixture=h48' ) ),
						API_Client::execute( array( $promise ) )
					);
				}
			);
		}
	}

	/**
	 * Shortcut IDs must resolve to their target; incomplete records are ignored.
	 *
	 * @return void
	 */
	public function test_exif_walker_descends_into_shortcut_targets() {
		$method = new ReflectionMethod( Exif_Dates_CLI::class, 'child_folder_ids' );
		$this->assertSame(
			array( 'folder', 'target', 'fallback' ),
			$method->invoke(
				null,
				array(
					array( 'id' => 'folder' ),
					array(
						'id'              => 'shortcut',
						'shortcutDetails' => array( 'targetId' => 'target' ),
					),
					array(
						'id'              => 'fallback',
						'shortcutDetails' => array( 'targetId' => null ),
					),
					array(),
				)
			)
		);
	}

	/**
	 * The unselected folder picker is empty; My Drive has its own label.
	 *
	 * @return void
	 */
	public function test_folder_picker_empty_and_my_drive_paths() {
		$method = new ReflectionMethod( List_Ajax_Endpoint::class, 'path_ids_to_names' );
		$this->assertSame( array(), $method->invoke( null, array() )->wait() );
		$this->assertSame( array( 'My Drive' ), $method->invoke( null, array( 'root' ) )->wait() );
	}

	/**
	 * The editor can list directly below the root and recurse down a selected path.
	 *
	 * @return void
	 */
	public function test_editor_lists_root_and_nested_folders() {
		Google_Transport::run(
			static function ( RequestInterface $request ) {
				return Google_Transport::batch(
					$request,
					200,
					array(
						'files' => array(
							array(
								'id'   => 'album',
								'name' => 'Album',
							),
						),
					)
				);
			},
			function () {
				$method = new ReflectionMethod( TinyMCE_Plugin::class, 'list_directories_in_path' );

				foreach ( array( array(), array( 'Album' ) ) as $path ) {
					$promise = $method->invoke( null, $path, 'fixture-root' );
					$this->assertSame( array( array( 'Album' ) ), API_Client::execute( array( $promise ) ) );
				}
			}
		);
	}
}
