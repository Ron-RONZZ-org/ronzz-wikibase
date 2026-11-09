<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\MediaEntries;
use PHPUnit\Framework\TestCase;

/** Normalization of the multi-image record (pure). */
final class MediaEntriesTest extends TestCase {

	public function testNormalizeDecodesJsonAndDeduplicates(): void {
		$entries = MediaEntries::normalize( json_encode( [
			[ 'fileTitle' => 'File:A.png' ],
			[ 'fileTitle' => 'File:A.png' ],
			[ 'fileTitle' => '' ],
		] ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'File:A.png', $entries[0]['fileTitle'] );
	}

	public function testNormalizeAcceptsArray(): void {
		$entries = MediaEntries::normalize( [ [ 'fileTitle' => 'B.png' ] ] );
		$this->assertSame( 'File:B.png', $entries[0]['fileTitle'] );
	}

	public function testNormalizeDropsInvalid(): void {
		$this->assertSame( [], MediaEntries::normalize( 'not json' ) );
		$this->assertSame( [], MediaEntries::normalize( null ) );
	}
}
