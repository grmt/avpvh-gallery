<?php
/**
 * PDF size and ordering regression tests using synthetic JPEGs only.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Share_Pdf_Parts;
use PHPUnit\Framework\TestCase;

/** Tests the completed PDF files, not just their JPEG payloads. */
final class Share_Pdf_Parts_Test extends TestCase {

	/** A generated white JPEG; no personal data or real photos. */
	private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsO'
		. 'CwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAAeABUBAREA/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAA'
		. 'AAAA/9oACAEBAAA/AKpgAAA//9k=';

	/**
	 * Uploaded filenames.
	 *
	 * @var array<string>
	 */
	private $names = array();

	/**
	 * Uploaded temporary paths.
	 *
	 * @var array<string>
	 */
	private $paths = array();

	/**
	 * Ordered synthetic page IDs.
	 *
	 * @var array<string>
	 */
	private $pages = array();

	/**
	 * Completed file sizes.
	 *
	 * @var array<int|false>
	 */
	private $sizes = array();

	/**
	 * Number of uploads.
	 *
	 * @var int
	 */
	private $uploads = 0;

	/**
	 * The path tested for failure cleanup.
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * Empty selections must not create a PDF.
	 *
	 * @return void
	 */
	public function test_empty_selection() {
		$parts = new Share_Pdf_Parts(
			function ( $name, $path ) {
				++$this->uploads;
				$this->names[] = $name;
				$this->paths[] = $path;
			}
		);
		$parts->finish();
		self::assertSame( 0, $this->uploads );
	}

	/**
	 * Numbering, order, cleanup and closing overhead at a small limit.
	 *
	 * @return void
	 */
	public function test_numbered_parts_preserve_every_page() {
		$parts = new Share_Pdf_Parts(
			function ( $name, $path ) {
				self::assertLessThanOrEqual( 120000, filesize( $path ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test PDF.
				$bytes = (string) file_get_contents( $path );
				self::assertStringContainsString( '/MediaBox [0 0 595.28 841.89]', $bytes );
				preg_match_all( '/fixture-page-(\d+)/', $bytes, $found );
				$this->pages   = array_merge( $this->pages, $found[1] );
				$this->names[] = $name;
				$this->paths[] = $path;
			},
			120000
		);

		for ( $page = 1; $page <= 5; ++$page ) {
			$parts->add( self::jpeg() . 'fixture-page-' . $page . str_repeat( 'x', 50000 ) );
		}

		$parts->finish();
		self::assertCount( 3, $this->names );
		$parts->finish();
		self::assertSame( array( '1', '2', '3', '4', '5' ), $this->pages );
		self::assertCount( 3, $this->names );
		self::assertSame( '000 Foto’s (A4) - deel 003.pdf', $this->names[2] );

		foreach ( $this->paths as $path ) {
			self::assertFileDoesNotExist( $path );
		}
	}

	/**
	 * Exercise the real 80-MB boundary and forbid a larger configured cap.
	 *
	 * @return void
	 */
	public function test_real_eighty_mb_limit_includes_pdf_overhead() {
		$parts = new Share_Pdf_Parts(
			function ( $name, $path ) {
				self::assertStringEndsWith( '.pdf', $name );
				$this->sizes[] = filesize( $path );
				self::assertLessThanOrEqual( 80000000, end( $this->sizes ) );
			},
			160000000
		);
		$jpeg  = self::jpeg() . str_repeat( 'x', 79996000 );
		$parts->add( $jpeg );
		unset( $jpeg );
		$parts->add( self::jpeg() . str_repeat( 'y', 10000 ) );
		$parts->finish();
		self::assertCount( 2, $this->sizes );
		self::assertGreaterThan( 79996000, $this->sizes[0] );
	}

	/**
	 * An upload failure must remove the local finished PDF.
	 *
	 * @return void
	 */
	public function test_upload_failure_removes_temporary_file() {
		$parts = new Share_Pdf_Parts(
			/**
			 * Throws the expected synthetic upload error.
			 *
			 * @throws RuntimeException Synthetic upload failure.
			 */
			function ( $name, $file ) {
				self::assertStringEndsWith( '.pdf', $name );
				$this->path = $file;

				throw new RuntimeException( 'Synthetic upload failure' );
			}
		);
		$parts->add( self::jpeg() );

		try {
			$parts->finish();
			self::fail( 'Expected an upload failure' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Synthetic upload failure', $error->getMessage() );
		}

		self::assertFileDoesNotExist( $this->path );
	}

	/**
	 * A real, generated JPEG for the PDF streams.
	 *
	 * @return string
	 */
	private static function jpeg() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- synthetic image fixture.
		return (string) base64_decode( self::JPEG, true );
	}
}
