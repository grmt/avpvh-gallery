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
 * The photos of a share (see Photo_Shares), always as JPEG files: JPEGs
 * that need no change are copied as they are; other photos are downloaded,
 * turned upright the way the lightbox shows them (EXIF orientation, then the
 * gallery's correction, see Share_Orientation), with captions on also given
 * their caption in the lower right corner (see Share_Caption), and uploaded
 * as JPEG. Images Imagick can't read here (HEIF) or that are too big to edit
 * in memory are taken from Google's own large rendering instead. Videos
 * and other files are left out. Only the copies change, never the originals.
 */
final class Share_Image {

	/**
	 * Files bigger than this (bytes) are taken from Google's rendering.
	 */
	private const MAX_BYTES = 40 * 1024 * 1024;

	/**
	 * Longest side of Google's rendering, in pixels.
	 */
	private const RENDERING_SIZE = 4000;

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
	 * Puts the photos into a folder as JPEG files, numbered in the given
	 * order ("001 PICT1346.JPG").
	 *
	 * @param array<string>                   $ids       Drive file IDs.
	 * @param string                          $folder_id Target folder.
	 * @param array{captions: bool, a4: bool} $options   Captions: turn them upright and caption them; a4: crop
	 *                                                  them to A4 proportions (before the caption is written).
	 * @param callable(int):void              $progress  Called now and then with the number done.
	 *
	 * @return int How many photos were put in.
	 */
	public static function copy_into( array $ids, $folder_id, array $options, callable $progress ) {
		$captions    = $options['captions'];
		$ids         = array_values( $ids );
		$details     = Share_Drive::details( $ids );
		$texts       = $captions ? Share_Caption::for_photos( $ids ) : array();
		$corrections = Share_Orientation::for_photos( $ids );
		$done        = 0;

		foreach ( $ids as $file_id ) {
			$file = $details[ $file_id ] ?? null;

			if ( null === $file || 0 !== strpos( $file['mime'], 'image/' ) ) {
				continue;
			}

			$edit = array(
				'a4'         => $options['a4'],
				'caption'    => $texts[ $file_id ] ?? '',
				'correction' => $corrections[ $file_id ] ?? Share_Orientation::NONE,
				'upright'    => $captions,
			);

			if ( self::put(
				$file_id,
				$file,
				sprintf( '%03d %s', $done + 1, self::jpeg_name( $file['name'] ) ),
				$folder_id,
				$edit
			) ) {
				++$done;
			}

			if ( 0 === $done % 10 ) {
				$progress( $done );
			}
		}

		return $done;
	}

	/**
	 * Puts one photo in the folder: copied when it's a JPEG needing no
	 * change, else converted. False when it couldn't be read at all.
	 *
	 * @param string                                                                                                        $file_id   Drive file ID.
	 * @param array{name: string, mime: string, size: int, thumb: string}                                                   $file      Its details.
	 * @param string                                                                                                        $name      The copy's name.
	 * @param string                                                                                                        $folder_id Target folder.
	 * @param array{caption: string, correction: array{h_flip: bool, rotation: int, v_flip: bool}, upright: bool, a4: bool} $edit      What to do.
	 *
	 * @return bool
	 */
	private static function put( $file_id, array $file, $name, $folder_id, array $edit ) {
		$jpeg = 'image/jpeg' === $file['mime'];

		if ( $jpeg && ! $edit['upright'] && ! $edit['a4'] ) {
			Share_Drive_Files::copy( $file_id, $name, $folder_id );

			return true;
		}

		$image   = $file['size'] > self::MAX_BYTES ? null : self::read( Share_Drive_Files::download( $file_id ) );
		$image ??= self::read( Share_Drive_Files::rendering( $file['thumb'], self::RENDERING_SIZE ) );

		if ( null === $image ) {
			return false;
		}

		if ( $jpeg && ! self::needs_change( $image, $edit ) ) {
			$image->clear();
			Share_Drive_Files::copy( $file_id, $name, $folder_id );

			return true;
		}

		Share_Drive_Files::upload( $folder_id, $name, 'image/jpeg', self::jpeg( $image, $edit ) );

		return true;
	}

	/**
	 * A file name with a .jpg extension (kept when it is one already).
	 *
	 * @param string $name The original name.
	 *
	 * @return string
	 */
	private static function jpeg_name( $name ) {
		if ( 1 === preg_match( '/\.jpe?g$/i', $name ) ) {
			return $name;
		}

		return (string) preg_replace( '/\.[^.\/]{1,5}$/', '', $name ) . '.jpg';
	}

	/**
	 * An image's first frame, or null when it can't be read.
	 *
	 * @param string $bytes The file's contents.
	 *
	 * @return Imagick|null
	 */
	private static function read( $bytes ) {
		if ( '' === $bytes ) {
			return null;
		}

		try {
			$all = new Imagick();
			$all->readImageBlob( $bytes );
			$all->setIteratorIndex( 0 );
			$image = $all->getImage();
			$all->clear();
		} catch ( ImagickException $e ) {
			// @phan-suppress-previous-line PhanUnusedVariableCaughtException -- unreadable: Google's rendering is tried instead.
			return null;
		}

		return $image;
	}

	/**
	 * Whether a JPEG must be redone: lying on its side by EXIF, with a
	 * correction or a caption.
	 *
	 * @param Imagick                                                                                                       $image The photo.
	 * @param array{caption: string, correction: array{h_flip: bool, rotation: int, v_flip: bool}, upright: bool, a4: bool} $edit  What to do.
	 *
	 * @return bool
	 */
	private static function needs_change( Imagick $image, array $edit ) {
		$orientation = $image->getImageOrientation();

		return ( Imagick::ORIENTATION_UNDEFINED !== $orientation && Imagick::ORIENTATION_TOPLEFT !== $orientation )
			|| Share_Orientation::NONE !== $edit['correction']
			|| '' !== $edit['caption']
			|| $edit['a4'];
	}

	/**
	 * The photo as a JPEG: flattened onto white (transparency), in sRGB,
	 * upright, with its caption.
	 *
	 * @param Imagick                                                                                                       $image The photo.
	 * @param array{caption: string, correction: array{h_flip: bool, rotation: int, v_flip: bool}, upright: bool, a4: bool} $edit  What to do.
	 *
	 * @return string
	 */
	private static function jpeg( Imagick $image, array $edit ) {
		if ( Imagick::COLORSPACE_CMYK === $image->getImageColorspace() ) {
			$image->transformImageColorspace( Imagick::COLORSPACE_SRGB );
		}

		$image->setImageBackgroundColor( new ImagickPixel( 'white' ) );
		$image->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
		$image->autoOrient();
		self::correct( $image, $edit['correction'] );
		$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );

		if ( $edit['a4'] ) {
			self::crop_a4( $image );
		}

		if ( '' !== $edit['caption'] ) {
			self::write( $image, $edit['caption'] );
		}

		$image->setImageDepth( 8 );
		$image->setImageFormat( 'jpeg' );
		$image->setImageCompressionQuality( 92 );
		$jpeg = $image->getImageBlob();
		$image->clear();

		return $jpeg;
	}

	/**
	 * Crops the photo from its middle to A4 proportions (√2 : 1), upright or
	 * lying as the photo is, and marks it 300 dpi or more at A4 size — so a
	 * print shop can print it on A4 without cutting anything off.
	 *
	 * @param Imagick $image The photo, upright.
	 *
	 * @return void
	 */
	private static function crop_a4( Imagick $image ) {
		$width  = $image->getImageWidth();
		$height = $image->getImageHeight();
		$long   = max( $width, $height );
		$short  = min( $width, $height );
		$ratio  = sqrt( 2 );

		if ( $long / $short > $ratio ) {
			$long = (int) round( $short * $ratio );
		} else {
			$short = (int) round( $long / $ratio );
		}

		$new_width  = $width >= $height ? $long : $short;
		$new_height = $width >= $height ? $short : $long;
		$image->cropImage(
			$new_width,
			$new_height,
			intdiv( $width - $new_width, 2 ),
			intdiv( $height - $new_height, 2 )
		);
		$image->setImagePage( 0, 0, 0, 0 );
		// A4's long side is 297 mm = 11.69 inch.
		$dpi = $long / 11.69;
		$image->setImageUnits( Imagick::RESOLUTION_PIXELSPERINCH );
		$image->setImageResolution( $dpi, $dpi );
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
		$right  = (int) round( $size * 2.4 );
		$bottom = (int) round( $size * 1.2 );
		$draw   = new ImagickDraw();
		$draw->setFont( __DIR__ . '/fonts/DejaVuSans-Bold.ttf' );
		$draw->setFontSize( $size );
		$draw->setGravity( Imagick::GRAVITY_SOUTHEAST );
		$draw->setFillColor( new ImagickPixel( 'rgba(0, 0, 0, 0.75)' ) );
		$draw->setStrokeColor( new ImagickPixel( 'rgba(0, 0, 0, 0.75)' ) );
		$draw->setStrokeWidth( max( 2, $size / 8 ) );
		$image->annotateImage( $draw, $right, $bottom, 0, $caption );

		$draw->setFillColor( new ImagickPixel( 'white' ) );
		$draw->setStrokeColor( new ImagickPixel( 'transparent' ) );
		$draw->setStrokeWidth( 0 );
		$image->annotateImage( $draw, $right, $bottom, 0, $caption );
	}
}
