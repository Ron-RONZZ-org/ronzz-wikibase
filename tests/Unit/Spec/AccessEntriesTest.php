<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\AccessEntries;
use PHPUnit\Framework\TestCase;

/** Normalization of the multi-access record (pure). */
final class AccessEntriesTest extends TestCase {

	public function testNormalizeDecodesJsonAndDropsEmptyRows(): void {
		$entries = AccessEntries::normalize( json_encode( [
			[ 'type' => 'url', 'url' => ' https://example.org ', 'public' => '1', 'archive' => '1' ],
			[ 'type' => 'url', 'url' => '' ],
			[ 'type' => 'file', 'fileTitle' => 'File:A.pdf', 'public' => '', 'license' => 'Q1' ],
			[ 'type' => 'nope' ],
		] ) );
		$this->assertCount( 2, $entries );
		$this->assertSame( 'https://example.org', $entries[0]['url'] );
		$this->assertSame( '1', $entries[0]['archive'] );
		$this->assertSame( 'File:A.pdf', $entries[1]['fileTitle'] );
		$this->assertSame( '', $entries[1]['public'] );
	}

	public function testNormalizeKeepsLanguageAndFileId(): void {
		$entries = AccessEntries::normalize( [
			[ 'type' => 'file', 'fileTitle' => 'File:A.pdf', 'public' => '1', 'license' => 'Q1',
				'language' => ' EN ', 'fileId' => 'q42' ],
		] );
		$this->assertSame( 'EN', $entries[0]['language'] );
		$this->assertSame( 'Q42', $entries[0]['fileId'] );
	}

	public function testNormalizeDropsInvalidFileId(): void {
		$entries = AccessEntries::normalize( [
			[ 'type' => 'file', 'fileTitle' => 'File:A.pdf', 'fileId' => 'not-a-qid' ],
		] );
		$this->assertArrayNotHasKey( 'fileId', $entries[0] );
	}

	public function testNormalizeAcceptsArray(): void {
		$entries = AccessEntries::normalize( [
			[ 'type' => 'url', 'url' => 'https://example.org/x', 'public' => '1' ],
		] );
		$this->assertSame( 'https://example.org/x', $entries[0]['url'] );
	}

	public function testNormalizeDropsEmptyAndInvalidTypes(): void {
		$this->assertSame( [], AccessEntries::normalize( [] ) );
		$this->assertSame( [], AccessEntries::normalize( 'not json' ) );
		$this->assertSame( [], AccessEntries::normalize( null ) );
	}

	public function testHttpUrlValidation(): void {
		$this->assertTrue( AccessEntries::isHttpUrl( 'https://example.org' ) );
		$this->assertTrue( AccessEntries::isHttpUrl( 'http://example.org' ) );
		$this->assertFalse( AccessEntries::isHttpUrl( 'ftp://example.org' ) );
		$this->assertFalse( AccessEntries::isHttpUrl( '' ) );
	}
}
