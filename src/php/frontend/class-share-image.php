<?php
/**
 * Contains the Share_Image class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;

/**
 * Shared copies with captions (see Photo_Shares): each photo is downloaded,
 * turned upright the way the lightbox shows it (its EXIF orientation, then
 * the gallery's correction, see Share_Orientation), given its caption in
 * the lower right corner (see Share_Caption) and uploaded into the share's
 * folder. Photos that need neither, or that Imagick can't edit (videos,
 * HEIC, RAW …), are copied as they are.
 */
final class Share_Image {

	/**
	 * Media types that are edited, with Imagick's format for them.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const FORMATS = array(
		'image/jpeg' => 'jpeg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	/**
	 * Caption height as a share of the photo's short side.
	 */
	private const TEXT_SIZE = 0.035;

	/**
	 * Whether photos can be edited here (Imagick is installed and reads
	 * JPEG — not every ImageMagick build does).
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( Imagick::class ) && array() !== Imagick::queryFormats( 'JPEG' );
	}

	/**
	 * Puts the photos into a folder, numbered in the given order
	 * ("001 PICT1346.JPG"), edited where needed.
	 *
	 * @param array<string>      $ids       Drive file IDs.
	 * @param string             $folder_id Target folder.
	 * @param callable(int):void $progress  Called now and then with the number done.
	 *
	 * @return void
	 */
	public static function copy_into( array $ids, $folder_id, callable $progress ) {
		$ids         = array_values( $ids );
		$details     = Share_Drive::details( $ids );
		$captions    = Share_Caption::for_photos( $ids );
		$corrections = Share_Orientation::for_photos( $ids );

		foreach ( $ids as $index => $file_id ) {
			$file  = $details[ $file_id ] ?? array(
				'mime' => '',
				'name' => $file_id,
			);
			$name  = sprintf( '%03d %s', $index + 1, $file['name'] );
			$edit  = array(
				'caption'    => $captions[ $file_id ] ?? '',
				'correction' => $corrections[ $file_id ] ?? Share_Orientation::NONE,
				'format'     => self::FORMATS[ $file['mime'] ] ?? '',
			);
			$bytes = '' === $edit['format'] ? null : self::edited( Share_Drive_Files::download( $file_id ), $edit );

			if ( null === $bytes ) {
				Share_Drive_Files::copy( $file_id, $name, $folder_id );
			} else {
				Share_Drive_Files::upload( $folder_id, $name, $file['mime'], $bytes );
			}

			if ( 0 === ( $index + 1 ) % 10 ) {
				$progress( $index + 1 );
			}
		}
	}

	/**
	 * A photo upright and captioned, or null when it needs no change or
	 * can't be read.
	 *
	 * @param string                                                                                               $bytes The photo.
	 * @param array{caption: string, correction: array{h_flip: bool, rotation: int, v_flip: bool}, format: string} $edit  What to do.
	 *
	 * @return string|null
	 */
	private static function edited( $bytes, array $edit ) {
		try {
			$image = new Imagick();
			$image->readImageBlob( $bytes );
		} catch ( ImagickException $e ) {
			// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- unreadable: copied as it is.
			return null;
		}

		$sideways = Imagick::ORIENTATION_UNDEFINED !== $image->getImageOrientation()
			&& Imagick::ORIENTATION_TOPLEFT !== $image->getImageOrientation();

		if ( ! $sideways && Share_Orientation::NONE === $edit['correction'] && '' === $edit['caption'] ) {
			return null;
		}

		$image->autoOrient();
		self::correct( $image, $edit['correction'] );
		$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );

		if ( '' !== $edit['caption'] ) {
			self::write( $image, $edit['caption'] );
		}

		$image->setImageFormat( $edit['format'] );
		$image->setImageCompressionQuality( 92 );
		$edited = $image->getImageBlob();
		$image->clear();

		return $edited;
	}

	/**
	 * Applies the gallery's correction as the lightbox does: turned
	 * clockwise first, then mirrored.
	 *
	 * @param Imagick                                          $image      The photo.
	 * @param array{h_flip: bool, rotation: int, v_flip: bool} $correction The correction.
	 *
	 * @return void
	 */
	private static function correct( Imagick $image, array $correction ) {
		if ( 0 !== $correction['rotation'] % 360 ) {
			$image->rotateImage( new ImagickPixel( 'black' ), $correction['rotation'] );
		}

		if ( $correction['h_flip'] ) {
			$image->flopImage();
		}

		if ( $correction['v_flip'] ) {
			$image->flipImage();
		}
	}

	/**
	 * Writes the caption in the lower right corner: white with a dark edge,
	 * so it reads on light and dark photos alike.
	 *
	 * @param Imagick $image   The photo, upright.
	 * @param string  $caption The text.
	 *
	 * @return void
	 */
	private static function write( Imagick $image, $caption ) {
		$size   = max( 14, (int) round( min( $image->getImageWidth(), $image->getImageHeight() ) * self::TEXT_SIZE ) );
		$margin = (int) round( $size * 0.6 );
		$draw   = new ImagickDraw();
		$draw->setFont( __DIR__ . '/fonts/DejaVuSans-Bold.ttf' );
		$draw->setFontSize( $size );
		$draw->setGravity( Imagick::GRAVITY_SOUTHEAST );
		$draw->setFillColor( new ImagickPixel( 'rgba(0, 0, 0, 0.75)' ) );
		$draw->setStrokeColor( new ImagickPixel( 'rgba(0, 0, 0, 0.75)' ) );
		$draw->setStrokeWidth( max( 2, $size / 8 ) );
		$image->annotateImage( $draw, $margin, $margin, 0, $caption );

		$draw->setFillColor( new ImagickPixel( 'white' ) );
		$draw->setStrokeColor( new ImagickPixel( 'transparent' ) );
		$draw->setStrokeWidth( 0 );
		$image->annotateImage( $draw, $margin, $margin, 0, $caption );
	}
}
