<?php
/**
 * Contains the Share_Pdf class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use RuntimeException;

/**
 * A PDF of a share's A4 photos for the print shop (see Share_Image): one
 * upright A4 page per photo, filled edge to edge; photos lying down are
 * turned a quarter so they fill the page too. The JPEGs go in as they are
 * (no re-encoding), and the file is written to disk as it grows, so a share
 * of hundreds of photos never has to fit in memory.
 */
final class Share_Pdf {

	/**
	 * A4 in points (1/72 inch).
	 */
	private const WIDTH = '595.28';

	/**
	 * A4 in points (1/72 inch).
	 */
	private const HEIGHT = '841.89';

	/**
	 * The file being written.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Its handle.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Byte offset of each object, by object number.
	 *
	 * @var array<int, int>
	 */
	private $offsets = array();

	/**
	 * The last object number used (1 and 2 are kept for the end).
	 *
	 * @var int
	 */
	private $last = 2;

	/**
	 * Object numbers of the pages.
	 *
	 * @var array<int>
	 */
	private $pages = array();

	/**
	 * Starts a PDF in a temporary file. Objects 1 and 2 (catalog, page
	 * tree) are written last, when all pages are known.
	 *
	 * @throws RuntimeException The file can't be made.
	 */
	public function __construct() {
		$this->path = (string) tempnam( get_temp_dir(), 'avpvh-share-pdf' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed: far too big to build in memory.
		$handle = fopen( $this->path, 'wb' );

		if ( false === $handle ) {
			throw new RuntimeException( 'Could not write the PDF' );
		}

		$this->handle = $handle;
		$this->write( "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" );
	}

	/**
	 * Removes unfinished temporary files too, including after a failed
	 * download or upload. A finished file belongs to the caller.
	 */
	public function __destruct() {
		if ( ! is_resource( $this->handle ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see the constructor.
		fclose( $this->handle );
		wp_delete_file( $this->path );
	}

	/**
	 * Adds a page with a JPEG on it.
	 *
	 * @param string $jpeg A JPEG file's contents.
	 *
	 * @return void
	 */
	public function add( $jpeg ) {
		$size = self::jpeg_size( $jpeg );

		if ( null === $size ) {
			return;
		}

		list( $width, $height, $components ) = $size;
		$image                               = $this->next_number();
		$this->object(
			$image,
			sprintf(
				'<< /Type /XObject /Subtype /Image /Width %d /Height %d /BitsPerComponent 8 %s'
					. ' /Filter /DCTDecode /Length %d >>',
				$width,
				$height,
				self::color_space( $components ),
				strlen( $jpeg )
			),
			$jpeg
		);

		// Upright: fill the page. Lying: turned a quarter, its width along
		// the page's height.
		$place   = $width > $height
			? sprintf( '0 %1$s -%2$s 0 %2$s 0 cm', self::HEIGHT, self::WIDTH )
			: sprintf( '%s 0 0 %s 0 0 cm', self::WIDTH, self::HEIGHT );
		$drawing = 'q ' . $place . ' /Im Do Q';
		$content = $this->next_number();
		$this->object( $content, sprintf( '<< /Length %d >>', strlen( $drawing ) ), $drawing );

		$page          = $this->next_number();
		$this->pages[] = $page;
		$this->object(
			$page,
			sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /XObject << /Im %d 0 R >> >>'
					. ' /Contents %d 0 R >>',
				self::WIDTH,
				self::HEIGHT,
				$image,
				$content
			)
		);
	}

	/**
	 * How many pages it has.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->pages );
	}

	/**
	 * Whether another JPEG fits, including the page objects and the final
	 * page tree/xref/trailer. The conservative reserve avoids filling a part
	 * with image bytes and then exceeding the limit when it is closed.
	 *
	 * @param string $jpeg  JPEG contents.
	 * @param int    $limit Maximum finished size in bytes.
	 *
	 * @return bool
	 */
	public function can_add( $jpeg, $limit ) {
		return (int) ftell( $this->handle ) + strlen( $jpeg ) + 2048 + 128 * ( $this->count() + 1 ) <= $limit;
	}

	/**
	 * Writes the catalog, page tree and cross-reference table and closes
	 * the file.
	 *
	 * @return string The file's path (the caller removes it).
	 */
	public function finish() {
		$kids = implode(
			' ',
			array_map(
				static function ( $page ) {
					return $page . ' 0 R';
				},
				$this->pages
			)
		);
		$this->object( 1, '<< /Type /Catalog /Pages 2 0 R >>' );
		$this->object( 2, sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', $kids, count( $this->pages ) ) );

		ksort( $this->offsets );
		$xref  = ftell( $this->handle );
		$count = $this->last + 1;
		$table = "xref\n0 " . $count . "\n0000000000 65535 f \n";

		for ( $number = 1; $number < $count; ++$number ) {
			$table .= sprintf( "%010d 00000 n \n", $this->offsets[ $number ] ?? 0 );
		}

		$this->write(
			$table . sprintf( "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", $count, $xref )
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see the constructor.
		fclose( $this->handle );

		return $this->path;
	}

	/**
	 * The next free object number.
	 *
	 * @return int
	 */
	private function next_number() {
		++$this->last;

		return $this->last;
	}

	/**
	 * Writes one object, with a stream if given.
	 *
	 * @param int    $number     Object number.
	 * @param string $dictionary Its dictionary.
	 * @param string $stream     Stream contents, if any.
	 *
	 * @return void
	 */
	private function object( $number, $dictionary, $stream = null ) {
		$this->offsets[ $number ] = (int) ftell( $this->handle );
		$body                     = null === $stream ? '' : "\nstream\n" . $stream . "\nendstream";
		$this->write( $number . " 0 obj\n" . $dictionary . $body . "\nendobj\n" );
	}

	/**
	 * Appends to the file.
	 *
	 * @param string $bytes What to write.
	 *
	 * @return void
	 */
	private function write( $bytes ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see the constructor.
		fwrite( $this->handle, $bytes );
	}

	/**
	 * A JPEG's width, height and number of colour components (from its
	 * start-of-frame marker), or null if it isn't one.
	 *
	 * @param string $jpeg A JPEG file's contents.
	 *
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private static function jpeg_size( $jpeg ) {
		$length = strlen( $jpeg );

		for ( $at = 2; $at + 9 < $length; ) {
			$marker = ord( $jpeg[ $at + 1 ] );

			if ( 0xC0 <= $marker && 0xCF >= $marker && ! in_array( $marker, array( 0xC4, 0xC8, 0xCC ), true ) ) {
				$sizes = (array) unpack( 'nheight/nwidth/Ccomponents', substr( $jpeg, $at + 5, 5 ) );

				return array( (int) $sizes['width'], (int) $sizes['height'], (int) $sizes['components'] );
			}

			$at += 2 + (int) ( (array) unpack( 'n', substr( $jpeg, $at + 2, 2 ) ) )[1];
		}

		return null;
	}

	/**
	 * The PDF colour space for a JPEG with this many components (Adobe
	 * CMYK JPEGs are stored inverted).
	 *
	 * @param int $components 1, 3 or 4.
	 *
	 * @return string
	 */
	private static function color_space( $components ) {
		if ( 1 === $components ) {
			return '/ColorSpace /DeviceGray';
		}

		return 4 === $components
			? '/ColorSpace /DeviceCMYK /Decode [1 0 1 0 1 0 1 0]'
			: '/ColorSpace /DeviceRGB';
	}
}
