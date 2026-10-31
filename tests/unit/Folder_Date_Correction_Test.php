<?php
/**
 * A folder's EXIF date correction: ignored or shifted dates.
 *
 * @package avpvh-gallery
 */

use Avpvh\Frontend\Folder_Date_Correction;
use Avpvh\Photo_Corrections_DB;

/** Tests the date arithmetic and the stored setting, without Drive. */
final class Folder_Date_Correction_Test extends WP_UnitTestCase {

	private const FOLDER_ID = 'fixture-folder-date-correction';

	/**
	 * A scan from a film scanner whose clock starts at 2022-05-01 12:00.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- PHPCSUtils false positive on an array value.
	private const RECORD = array(
		'createdTime'        => '2026-10-20T10:00:00.000Z',
		'imageMediaMetadata' => array(
			'time'  => '2022:05:01 12:03:38',
			'width' => 100,
		),
		'name'               => 'PICT0250.JPG',
	);

	/**
	 * Creates the plugin tables.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Photo_Corrections_DB::create_tables();
	}

	/**
	 * Without a correction a date is kept; ignored it's gone.
	 *
	 * @return void
	 */
	public function test_none_and_ignore() {
		$this->assertSame( '2022-05-01 12:03:38', Folder_Date_Correction::apply( '2022-05-01 12:03:38', null ) );
		$this->assertSame( '', Folder_Date_Correction::apply( '2022-05-01 12:03:38', self::fix( 'ignore', 0 ) ) );
	}

	/**
	 * A shift adds its offset, across a date boundary and backwards.
	 *
	 * @return void
	 */
	public function test_shift() {
		$this->assertSame(
			'2022-05-02 01:03:38',
			Folder_Date_Correction::apply( '2022-05-01 12:03:38', self::fix( 'shift', 13 * HOUR_IN_SECONDS ) )
		);
		$this->assertSame(
			'2021-05-01 12:03:38',
			Folder_Date_Correction::apply( '2022-05-01 12:03:38', self::fix( 'shift', -365 * DAY_IN_SECONDS ) )
		);
	}

	/**
	 * Ignored, a Drive record loses its capture and upload time, so it's
	 * dated by its name or ordered by name; shifted, its capture time moves.
	 *
	 * @return void
	 */
	public function test_drive_records() {
		$ignored = Folder_Date_Correction::apply_to_record( self::RECORD, self::fix( 'ignore', 0 ) );
		$this->assertArrayNotHasKey( 'time', $ignored['imageMediaMetadata'] );
		$this->assertArrayNotHasKey( 'createdTime', $ignored );
		$this->assertSame( 100, $ignored['imageMediaMetadata']['width'] );

		$shifted = Folder_Date_Correction::apply_to_record( self::RECORD, self::fix( 'shift', 60 ) );
		$this->assertSame( '2022:05:01 12:04:38', $shifted['imageMediaMetadata']['time'] );
		$this->assertSame( self::RECORD['createdTime'], $shifted['createdTime'] );
	}

	/**
	 * A saved correction is read back; saving no mode removes it.
	 *
	 * @return void
	 */
	public function test_save_and_remove() {
		Folder_Date_Correction::save( self::FOLDER_ID, 'shift', -3600 );
		$this->assertSame( self::fix( 'shift', -3600 ), Folder_Date_Correction::for_folder( self::FOLDER_ID ) );

		Folder_Date_Correction::save( self::FOLDER_ID, '', 0 );
		$this->assertNull( Folder_Date_Correction::for_folder( self::FOLDER_ID ) );
	}

	/**
	 * A correction.
	 *
	 * @param string $mode   'ignore' or 'shift'.
	 * @param int    $offset Seconds.
	 *
	 * @return array{mode: string, offset: int}
	 */
	private static function fix( $mode, $offset ) {
		return array(
			'mode'   => $mode,
			'offset' => $offset,
		);
	}
}
