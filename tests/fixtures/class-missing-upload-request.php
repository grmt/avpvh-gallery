<?php
/**
 * Models an SDK resource returning metadata instead of a deferred upload request.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Tests;

use Avpvh\Vendor\Google\Service\Drive\DriveFile;
use Avpvh\Vendor\Google\Service\Drive\Resource\Files;
use PHPUnit\Framework\Assert;

/** Verifies the wrapper rejects an unexpected SDK result before starting a resumable upload. */
final class Missing_Upload_Request extends Files {

	/**
	 * Returns metadata even though the caller requested deferred execution.
	 *
	 * @param DriveFile           $post_body New file metadata.
	 * @param array<string,mixed> $opt_params Request options.
	 * @return DriveFile
	 */
	public function create( DriveFile $post_body, $opt_params = array() ) {
		Assert::assertSame( 'id', $opt_params['fields'] );
		Assert::assertTrue( $opt_params['supportsAllDrives'] );

		return $post_body;
	}
}
