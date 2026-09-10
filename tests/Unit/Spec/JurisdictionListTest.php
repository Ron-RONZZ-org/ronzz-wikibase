<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\JurisdictionList;
use PHPUnit\Framework\TestCase;

/**
 * Multi-value territorial-jurisdiction helpers (the legal-text source
 * classes): id-list splitting, the JSON label map (with the legacy
 * plain-string fallback) and the rendered links.
 *
 * @covers \EmbeddableContent\Spec\JurisdictionList
 * @license GPL-2.0-or-later
 */
final class JurisdictionListTest extends TestCase {

	public function testSplitTrimsDedupesAndDropsInvalid(): void {
		$this->assertSame(
			[ 'relation/123', 'node/45' ],
			JurisdictionList::split( ' relation/123 , node/45 ; relation/123 ' )
		);
		// A raw place name is not an id — dropped (the caller rejects it via
		// allValid()).
		$this->assertSame( [], JurisdictionList::split( 'France' ) );
		$this->assertSame( [], JurisdictionList::split( '' ) );
	}

	public function testAllValid(): void {
		$this->assertTrue( JurisdictionList::allValid( '' ) );
		$this->assertTrue( JurisdictionList::allValid( 'relation/123, node/45' ) );
		$this->assertFalse( JurisdictionList::allValid( 'relation/123, France' ) );
	}

	public function testLabelsFromJsonMapArePrunedToPresentIds(): void {
		$raw = '{"relation/123":"France","relation/456":"Germany","relation/999":"Stale"}';
		$this->assertSame(
			[ 'relation/123' => 'France', 'relation/456' => 'Germany' ],
			JurisdictionList::labels( $raw, [ 'relation/123', 'relation/456' ] )
		);
	}

	public function testLabelsLegacyPlainStringAttachesToFirstId(): void {
		$this->assertSame(
			[ 'relation/123' => 'United States' ],
			JurisdictionList::labels( 'United States', [ 'relation/123' ] )
		);
		$this->assertSame( [], JurisdictionList::labels( '', [ 'relation/123' ] ) );
		$this->assertSame( [], JurisdictionList::labels( 'France', [] ) );
	}

	public function testEncodeLabelsRoundTrips(): void {
		$this->assertSame( '', JurisdictionList::encodeLabels( [] ) );
		$encoded = JurisdictionList::encodeLabels( [ 'relation/123' => 'France' ] );
		$this->assertSame( [ 'relation/123' => 'France' ], json_decode( $encoded, true ) );
	}

	public function testLinksRendersOneLinkPerIdWithRawIdFallback(): void {
		$this->assertSame(
			'[https://www.openstreetmap.org/relation/123 France], '
			. '[https://www.openstreetmap.org/node/45 node/45]',
			JurisdictionList::links(
				[ 'relation/123', 'node/45' ],
				[ 'relation/123' => 'France' ]
			)
		);
	}

	public function testLinksSanitizesLabelWikitext(): void {
		// [ ] | { } < > are stripped (a Nominatim display name must never
		// break the [url text] syntax or the template's table).
		$this->assertSame(
			'[https://www.openstreetmap.org/relation/123 Paris 1]',
			JurisdictionList::links( [ 'relation/123' ], [ 'relation/123' => 'Paris [1]' ] )
		);
	}
}
