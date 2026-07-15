<?php
/**
 * Contains the Makernote_Tags class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Admin\Exif_Inspector;

/**
 * Renames UndefinedTag:0xXXXX keys in a flat EXIF array using per-manufacturer tag tables.
 * Tag names sourced from ExifTool (https://github.com/exiftool/exiftool).
 */
final class Makernote_Tags {

	/**
	 * Renames UndefinedTag:0xXXXX keys in a flat EXIF array using per-manufacturer tag tables.
	 *
	 * @param array<string, mixed> $flat Flat EXIF array with SECTION:key entries.
	 *
	 * @return array<string, mixed>
	 */
	public static function rename( array $flat ) {
		$make    = self::exif_make( $flat );
		$tag_map = self::tag_map_for_make( $make );

		if ( null === $tag_map ) {
			return $flat;
		}

		$result = array();

		foreach ( $flat as $key => $value ) {
			$renamed = self::rename_key( $key, $tag_map, $result );

			if ( null !== $renamed ) {
				$result[ $renamed ] = $value;
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Reads the camera make from a flat EXIF array (IFD0 first, then FILE as fallback).
	 *
	 * @param array<string, mixed> $flat Flat EXIF array with SECTION:key entries.
	 *
	 * @return string Lowercased camera make, or '' if unknown.
	 */
	private static function exif_make( array $flat ) {
		if ( isset( $flat['IFD0:Make'] ) ) {
			$raw_make = $flat['IFD0:Make'];
		} elseif ( isset( $flat['FILE:Make'] ) ) {
			$raw_make = $flat['FILE:Make'];
		} else {
			$raw_make = '';
		}

		return strtolower( (string) $raw_make );
	}

	/**
	 * Resolves the correct new key name for one MakerNote tag, if it's mapped and not already
	 * used by an existing named key.
	 *
	 * @param string                $key     The flat EXIF key ("SECTION:tag").
	 * @param array<string, string> $tag_map UndefinedTag:0xXXXX → human-readable name.
	 * @param array<string, mixed>  $result  The result array being built (checked to avoid overwrites).
	 *
	 * @return string|null The renamed key, or null to keep the original key unchanged.
	 */
	private static function rename_key( $key, array $tag_map, array $result ) {
		$colon = strpos( $key, ':' );

		if ( false === $colon ) {
			return null;
		}

		$section = substr( $key, 0, $colon );
		$tag     = substr( $key, $colon + 1 );
		// Normalize hex to uppercase for lookup.
		$tag_upper = preg_replace_callback(
			'/0x([0-9a-fA-F]+)/',
			static function ( $m ) {
				return '0x' . strtoupper( $m[1] );
			},
			$tag
		);

		if ( ! isset( $tag_map[ $tag_upper ] ) ) {
			return null;
		}

		$new_key = $section . ':' . $tag_map[ $tag_upper ];

		// Avoid overwriting an existing named key.
		return isset( $result[ $new_key ] ) ? null : $new_key;
	}

	/**
	 * Returns the MakerNote tag-rename table for a camera make, or null if unrecognized.
	 *
	 * @param string $make Lowercased camera make.
	 *
	 * @return array<string, string>|null
	 */
	private static function tag_map_for_make( $make ) {
		if ( str_contains( $make, 'canon' ) ) {
			return self::canon_tag_map();
		}

		if ( str_contains( $make, 'nikon' ) ) {
			return self::nikon_tag_map();
		}

		if ( str_contains( $make, 'sony' ) ) {
			return self::sony_tag_map();
		}

		return null;
	}

	/**
	 * Canon MakerNote tag-rename table. Only scalar-valued tags that survive binary filtering are listed.
	 *
	 * @return array<string, string>
	 */
	private static function canon_tag_map() {
		return array(
			'UndefinedTag:0x00B4' => 'ColorSpace',
			'UndefinedTag:0x000C' => 'CameraSerialNumber',
			'UndefinedTag:0x0006' => 'CanonImageType',
			'UndefinedTag:0x0007' => 'CanonFirmwareVersion',
			'UndefinedTag:0x0008' => 'FileNumber',
			'UndefinedTag:0x0009' => 'OwnerName',
			'UndefinedTag:0x001A' => 'InternalSerialNumber',
			'UndefinedTag:0x0010' => 'CanonModelID',
			'UndefinedTag:0x0019' => 'LensModel',
			'UndefinedTag:0x0035' => 'TimeZone',
			'UndefinedTag:0x0036' => 'TimeZoneCity',
			'UndefinedTag:0x0037' => 'DaylightSavings',
			'UndefinedTag:0x0095' => 'LensModel',
			'UndefinedTag:0x0096' => 'LensSerialNumber',
		);
	}

	/**
	 * Nikon MakerNote tag-rename table. Only scalar-valued tags that survive binary filtering are listed.
	 *
	 * @return array<string, string>
	 */
	private static function nikon_tag_map() {
		return array(
			'UndefinedTag:0x00A0' => 'SerialNumber',
			'UndefinedTag:0x00A7' => 'ShutterCount',
			'UndefinedTag:0x00A9' => 'ImageOptimization',
			'UndefinedTag:0x00AB' => 'VariProgram',
			'UndefinedTag:0x000B' => 'WhiteBalanceFineTune',
			'UndefinedTag:0x0001' => 'MakerNoteVersion',
			'UndefinedTag:0x0002' => 'ISO',
			'UndefinedTag:0x0004' => 'Quality',
			'UndefinedTag:0x0005' => 'WhiteBalance',
			'UndefinedTag:0x0007' => 'Focus',
			'UndefinedTag:0x001D' => 'SerialNumber',
			'UndefinedTag:0x001E' => 'ColorSpace',
			'UndefinedTag:0x008D' => 'ColorHue',
			'UndefinedTag:0x008F' => 'SceneMode',
			'UndefinedTag:0x0080' => 'ImageAdjustment',
			'UndefinedTag:0x0081' => 'ToneComp',
			'UndefinedTag:0x0082' => 'AuxiliaryLens',
			'UndefinedTag:0x0083' => 'LensType',
			'UndefinedTag:0x0084' => 'Lens',
			'UndefinedTag:0x0085' => 'ManualFocusDistance',
			'UndefinedTag:0x0086' => 'DigitalZoom',
			'UndefinedTag:0x0087' => 'FlashMode',
			'UndefinedTag:0x0094' => 'Saturation',
			'UndefinedTag:0x0095' => 'NoiseReduction',
		);
	}

	/**
	 * Sony MakerNote tag-rename table. Only scalar-valued tags that survive binary filtering are listed.
	 *
	 * @return array<string, string>
	 */
	private static function sony_tag_map() {
		return array(
			'UndefinedTag:0x0102' => 'Quality',
			'UndefinedTag:0x0104' => 'FlashExposureComp',
			'UndefinedTag:0x0105' => 'Teleconverter',
			'UndefinedTag:0x011A' => 'PictureEffect',
			'UndefinedTag:0x011B' => 'SoftSkinEffect',
			'UndefinedTag:0x0112' => 'WhiteBalanceFineTune',
			'UndefinedTag:0x0115' => 'WhiteBalance',
			'UndefinedTag:0x0116' => 'LongExposureNoiseReduction',
			'UndefinedTag:0x0117' => 'HighISONoiseReduction',
			'UndefinedTag:0x0118' => 'HDR',
			'UndefinedTag:0x0119' => 'MultiFrameNoiseReduction',
			// SonyInfo2 sub-tags (0x2xxx).
			'UndefinedTag:0x200B' => 'NormalWhiteBalance',
			'UndefinedTag:0x200D' => 'WBShiftAB_GM',
			'UndefinedTag:0x200E' => 'AFMicroAdjValue',
			'UndefinedTag:0x200F' => 'AFMicroAdjOn',
			'UndefinedTag:0x201A' => 'AFTracking',
			'UndefinedTag:0x2011' => 'VignettingCorrection',
			'UndefinedTag:0x2012' => 'LateralChromaticAberration',
			'UndefinedTag:0x2013' => 'DistortionCorrectionSetting',
			'UndefinedTag:0x2015' => 'HighISONoiseReduction2',
			'UndefinedTag:0x2016' => 'AutoPortraitFramed',
			'UndefinedTag:0x2017' => 'FaceDetection',
			'UndefinedTag:0x2018' => 'FlashAction',
			'UndefinedTag:0x2019' => 'FocusMode2',
			'UndefinedTag:0x2023' => 'FocusArea',
			// Rational tags.
			'UndefinedTag:0x5001' => 'BatteryLevel',
			'UndefinedTag:0x5002' => 'SequenceImageNumber',
			// SonyInfo (0xBxxx).
			'UndefinedTag:0xB000' => 'FileFormat',
			'UndefinedTag:0xB020' => 'ColorReproduction',
			'UndefinedTag:0xB021' => 'ColorTemperature',
			'UndefinedTag:0xB023' => 'SceneMode',
			'UndefinedTag:0xB024' => 'ZoneMatching',
			'UndefinedTag:0xB025' => 'DynamicRangeOptimizer',
			'UndefinedTag:0xB04C' => 'ExposureStandardAdjustment',
			'UndefinedTag:0xB04D' => 'PictureProfile',
			'UndefinedTag:0xB040' => 'LensID',
			'UndefinedTag:0xB041' => 'MinAperture',
			'UndefinedTag:0xB045' => 'FlashAction2',
			'UndefinedTag:0xB046' => 'PrivateIFDOffset',
			'UndefinedTag:0xB047' => 'LightSource',
			'UndefinedTag:0xB051' => 'ReleaseMode2',
			'UndefinedTag:0xB053' => 'SelfTimer',
		);
	}
}
