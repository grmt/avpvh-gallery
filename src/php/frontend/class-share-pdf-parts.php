<?php
/**
 * Contains the Share_Pdf_Parts class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use RuntimeException;

/**
 * Streams A4 pages into numbered PDFs, uploading each completed part
 * before starting the next. Neither all photos nor all PDFs are buffered.
 */
final class Share_Pdf_Parts {

	/**
	 * Decimal MB: never exceed an upload limit of 80,000,000 bytes.
	 */
	public const MAX_BYTES = 80000000;

	/**
	 * Current part.
	 *
	 * @var Share_Pdf|null
	 */
	private $pdf = null;

	/**
	 * Number of parts completed.
	 *
	 * @var int
	 */
	private $part = 0;

	/**
	 * Uploads a filename and local path.
	 *
	 * @var callable(string, string):void
	 */
	private $upload;

	/**
	 * Finished file limit, including PDF overhead.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Starts a multipart export.
	 *
	 * @param callable(string, string):void $upload Upload callback.
	 * @param int                           $limit  Byte limit (smaller in tests).
	 */
	public function __construct( callable $upload, $limit = self::MAX_BYTES ) {
		$this->upload = $upload;
		$this->limit  = min( self::MAX_BYTES, $limit );
	}

	/**
	 * Adds one JPEG, starting a new part before the size limit is reached.
	 *
	 * @param string $jpeg JPEG contents, already cropped and captioned.
	 *
	 * @return void
	 * @throws RuntimeException A single photo cannot fit in an empty part.
	 */
	public function add( $jpeg ) {
		$this->pdf ??= new Share_Pdf();

		if ( ! $this->pdf->can_add( $jpeg, $this->limit ) && 0 < $this->pdf->count() ) {
			$this->finish();
			$this->pdf = new Share_Pdf();
		}

		if ( ! $this->pdf->can_add( $jpeg, $this->limit ) ) {
			throw new RuntimeException( 'Een foto is te groot voor een PDF-deel van maximaal 80 MB.' );
		}

		$this->pdf->add( $jpeg );
	}

	/**
	 * Uploads the remaining part and always removes its temporary file.
	 * Empty selections never produce an empty PDF.
	 *
	 * @return void
	 * @throws RuntimeException The actual file exceeds the maximum size.
	 */
	public function finish() {
		if ( null === $this->pdf ) {
			return;
		}

		$pages     = $this->pdf->count();
		$path      = $this->pdf->finish();
		$this->pdf = null;

		try {
			clearstatcache( true, $path );
			$bytes = filesize( $path );

			if ( false === $bytes || $bytes > $this->limit ) {
				throw new RuntimeException( 'Het PDF-deel overschrijdt de limiet van 80 MB.' );
			}

			if ( 0 < $pages ) {
				++$this->part;
				( $this->upload )( sprintf( '000 Foto’s (A4) - deel %03d.pdf', $this->part ), $path );
			}
		} finally {
			wp_delete_file( $path );
		}
	}
}
