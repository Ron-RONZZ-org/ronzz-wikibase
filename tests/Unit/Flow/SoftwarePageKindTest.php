<?php

declare( strict_types = 1 );

namespace Tests\Unit\Flow;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SoftwarePageKind;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Flow\SoftwarePageKind
 * @license GPL-2.0-or-later
 */
final class SoftwarePageKindTest extends TestCase {

	private const BASE = [
		'instanceOf' => 'P1',
		'classes' => [ 'quotation' => 'Q2', 'code' => 'Q3', 'math' => 'Q4' ],
		'payloadProperties' => [ 'quotation' => 'P2', 'code' => 'P3', 'math' => 'P4' ],
		'programmingLanguage' => 'P5',
		'fallbackLanguages' => [ 'en' ],
		'provenance' => [ 'attributedTo' => 'P6', 'sourceUrl' => 'P7', 'date' => 'P8' ],
	];

	private function config( bool $withFossLicenseClass ): EmbeddableContentConfig {
		return new EmbeddableContentConfig(
			self::BASE + ( $withFossLicenseClass
				? [ 'fossLicenseClasses' => [ 'fossLicense' => 'Q99' ] ]
				: [] )
		);
	}

	public function testMissingLicenseDefaultsToSoftware(): void {
		$this->assertSame(
			SoftwarePageKind::SOFTWARE,
			SoftwarePageKind::defaultFor( $this->config( true ), '' )
		);
	}

	public function testUnresolvedLicenseLabelDefaultsToSoftware(): void {
		// A harvested Wikidata label that did not resolve to a local license
		// item is not evidence of FOSS-ness (the Q2721 bug).
		$this->assertSame(
			SoftwarePageKind::SOFTWARE,
			SoftwarePageKind::defaultFor( $this->config( true ), 'GNU General Public License v3.0' )
		);
	}

	public function testNoFossLicenseClassConfiguredDefaultsToSoftware(): void {
		$this->assertSame(
			SoftwarePageKind::SOFTWARE,
			SoftwarePageKind::defaultFor( $this->config( false ), 'Q42' )
		);
	}

}
