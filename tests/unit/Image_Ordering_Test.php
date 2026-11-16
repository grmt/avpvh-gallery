<?php
/**
 * Gallery date ordering uses capture dates and creation-date fallbacks.
 *
 * @package avpvh-gallery
 */

use Avpvh\API_Client;
use Avpvh\Frontend\Options_Proxy;
use Avpvh\Frontend\Page\Images;
use Avpvh\Frontend\Paging_Pagination_Helper;
use Avpvh\Tests\Google_Transport;
use Avpvh\Vendor\Psr\Http\Message\RequestInterface;

require_once dirname( __DIR__ ) . '/fixtures/class-google-transport.php';

/** Exercises the public gallery API, including Google's batch response parser. */
final class Image_Ordering_Test extends WP_UnitTestCase {

	/**
	 * Capture time takes precedence; null metadata falls back to Drive's creation date.
	 *
	 * @return void
	 */
	public function test_capture_and_creation_dates_order_gallery_images() {
		Google_Transport::run(
			static function ( RequestInterface $request ) {
				$files = array(
					self::image( 'later', array( 'time' => '2024:06:01 12:00:00' ), '2020-01-01T00:00:00.000Z' ),
					self::image( 'earlier', array( 'time' => null ), '2023-06-01T12:00:00.000Z' ),
					self::image( 'undated', array(), '' ),
				);

				return Google_Transport::batch( $request, 200, array( 'files' => $files ) );
			},
			function () {
				foreach ( array( 'ascending', 'descending' ) as $order ) {
					$options        = new Options_Proxy(
						array(
							'image_ordering_by'    => 'time',
							'image_ordering_order' => $order,
						)
					);
					$promise        = Images::get(
						'fixture-root',
						( new Paging_Pagination_Helper() )->withValues( 0, 10 ),
						$options
					);
					list( $images ) = API_Client::execute( array( $promise ) );
					$expected       = array( 'earlier', 'later', 'undated' );
					$this->assertSame(
						'ascending' === $order ? $expected : array_reverse( $expected ),
						array_column( $images, 'id' )
					);
				}
			}
		);
	}

	/**
	 * A synthetic Drive record sufficient to render a gallery image.
	 *
	 * @param string              $image_id Image ID.
	 * @param array<string,mixed> $metadata Capture metadata.
	 * @param string              $created Creation timestamp.
	 * @return array<string,mixed>
	 */
	private static function image( $image_id, array $metadata, $created ) {
		return array(
			'createdTime'        => $created,
			'id'                 => $image_id,
			'imageMediaMetadata' => $metadata + array(
				'height' => 30,
				'width'  => 20,
			),
			'name'               => $image_id . '.jpg',
			'thumbnailLink'      => 'https://lh3.googleusercontent.com/fixture=s220',
		);
	}
}
