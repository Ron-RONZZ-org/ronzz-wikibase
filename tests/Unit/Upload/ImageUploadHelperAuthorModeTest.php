<?php

declare( strict_types = 1 );

namespace Tests\Unit\Upload;

use EmbeddableContent\Upload\ImageUploadHelper;
use PHPUnit\Framework\TestCase;

/**
 * The Add* upload section's author mode: the "specify as a semantic entity"
 * checkbox selects which of the two submitted author fields counts.
 *
 * @license GPL-2.0-or-later
 */
class ImageUploadHelperAuthorModeTest extends TestCase {

	public function testTextModeIsTheDefault(): void {
		$values = ImageUploadHelper::authorModeValues(
			[
				'logoAuthor' => '  Ada Lovelace ',
				'logoAuthorIsEntity' => '',
				'logoAuthorEntity' => 'Q42',
			],
			'logo'
		);

		$this->assertSame(
			[ 'useEntity' => false, 'text' => 'Ada Lovelace', 'entity' => '' ],
			$values
		);
	}

	public function testEntityModeIgnoresTheText(): void {
		$values = ImageUploadHelper::authorModeValues(
			[
				'logoAuthor' => 'Ada Lovelace',
				'logoAuthorIsEntity' => '1',
				'logoAuthorEntity' => ' Q42 ',
			],
			'logo'
		);

		$this->assertSame(
			[ 'useEntity' => true, 'text' => '', 'entity' => 'Q42' ],
			$values
		);
	}

	public function testMissingFieldsYieldEmptyValues(): void {
		$this->assertSame(
			[ 'useEntity' => false, 'text' => '', 'entity' => '' ],
			ImageUploadHelper::authorModeValues( [], 'portrait' )
		);
	}
}
