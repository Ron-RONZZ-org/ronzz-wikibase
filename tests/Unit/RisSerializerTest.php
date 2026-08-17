<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\RisSerializer;

/**
 * Unit tests for the RIS serializer (pure PHP, no MediaWiki needed).
 *
 * @license GPL-2.0-or-later
 */
class RisSerializerTest extends TestCase {

	private RisSerializer $serializer;

	protected function setUp(): void {
		$this->serializer = new RisSerializer();
	}

	public function testArticleSerialization(): void {
		$out = $this->serializer->serialize( [
			'type' => 'article',
			'title' => 'The Analytical Engine',
			'author' => [ [ 'literal' => 'Ada Lovelace' ] ],
			'container-title' => 'Notes',
			'issued' => [ 'date-parts' => [ [ 1843 ] ] ],
			'URL' => 'https://example.org/notes',
		] );
		$this->assertStringStartsWith( 'TY  - JOUR', $out );
		$this->assertStringContainsString( 'AU  - Ada Lovelace', $out );
		$this->assertStringContainsString( 'TI  - The Analytical Engine', $out );
		$this->assertStringContainsString( 'JO  - Notes', $out );
		$this->assertStringContainsString( 'PY  - 1843', $out );
		$this->assertStringContainsString( 'UR  - https://example.org/notes', $out );
		$this->assertStringEndsWith( 'ER  - ', $out );
	}

	public function testSoftwareTypeMapsToComp(): void {
		$out = $this->serializer->serialize( [ 'type' => 'software', 'title' => 'x' ] );
		$this->assertStringStartsWith( 'TY  - COMP', $out );
	}

	public function testUnknownTypeFallsBackToGen(): void {
		$out = $this->serializer->serialize( [ 'type' => 'hearing', 'title' => 'x' ] );
		$this->assertStringStartsWith( 'TY  - GEN', $out );
	}

	public function testIssuedLiteralYear(): void {
		$out = $this->serializer->serialize( [ 'type' => 'webpage', 'issued' => [ 'literal' => '1843' ] ] );
		$this->assertStringContainsString( 'PY  - 1843', $out );
	}
}
