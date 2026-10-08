<?php
/**
 * Normalizer stub.
 *
 * @package avpvh-gallery
 *
 * @phan-file-suppress PhanUnusedPublicNoOverrideMethodParameter
 */

// phpcs:ignoreFile -- Normalizer stub for static analysis.

class Normalizer {

	public const NONE = 1;
	public const FORM_D = 2;
	public const NFD = 2;
	public const FORM_KD = 3;
	public const NFKD = 3;
	public const FORM_C = 4;
	public const NFC = 4;
	public const FORM_KC = 5;
	public const NFKC = 5;

	/**
	 * Normalizes a string.
	 *
	 * @param string $string The input string.
	 * @param int    $form   The normalization form.
	 *
	 * @return string|false
	 */
	public static function normalize( $string, $form = self::FORM_C ) {
		return $string;
	}

	/**
	 * Checks if a string is normalized.
	 *
	 * @param string $string The input string.
	 * @param int    $form   The normalization form.
	 *
	 * @return bool
	 */
	public static function isNormalized( $string, $form = self::FORM_C ) {
		return true;
	}
}
