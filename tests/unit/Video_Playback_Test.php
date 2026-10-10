<?php
/**
 * Viewing copies use protected media URLs; missing copies retain Drive playback.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Page\Videos;
use Avpvh\Vendor\GuzzleHttp\Promise\PromiseInterface;

/** Tests source selection without downloading video or calling Drive. */
final class Video_Playback_Test extends WP_UnitTestCase {

	private const VIDEO_ID = 'fixture-video-playback';

	/**
	 * An installed viewing copy is protected, and deleting it restores Drive.
	 *
	 * @return void
	 */
	public function test_installed_copy_and_drive_fallback() {
		$name = hash( 'sha256', self::VIDEO_ID ) . '.mp4';
		$path = WP_CONTENT_DIR . '/uploads/private/gallery-video/' . $name;
		wp_mkdir_p( dirname( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- synthetic local fixture, never a real video.
		file_put_contents( $path, 'fixture video' );

		try {
			$this->assertSame(
				content_url( '/uploads/private/gallery-video/' . $name ),
				self::resolve( self::VIDEO_ID )
			);
		} finally {
			wp_delete_file( $path );
		}

		$this->assertStringContainsString( 'action=video_proxy', self::resolve( self::VIDEO_ID ) );
	}

	/**
	 * File IDs never become filesystem paths supplied by a visitor.
	 *
	 * @return void
	 */
	public function test_file_id_cannot_escape_viewing_copy_directory() {
		$this->assertStringContainsString( 'action=video_proxy', self::resolve( '../../outside.mp4' ) );
	}

	/**
	 * Resolve a large video's source, exercising the normal Drive fallback.
	 *
	 * @param string $id A synthetic Drive ID.
	 * @return string The resolved URL.
	 * @throws UnexpectedValueException If source resolution does not return a promise.
	 */
	private static function resolve( $id ) {
		$method = new ReflectionMethod( Videos::class, 'resolve_url' );
		$method->setAccessible( true );

		$promise = $method->invoke( null, $id, 'video/mp4', 2664954401, '', '', false, array() );

		if ( ! $promise instanceof PromiseInterface ) {
			throw new UnexpectedValueException( 'Video source resolution must return a promise.' );
		}

		return (string) $promise->wait();
	}
}
