<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\BibtexSerializer;

/**
 * Unit tests for the BibTeX serializer (pure PHP, no MediaWiki needed).
 *
 * @license GPL-2.0-or-later
 */
class BibtexSerializerTest extends TestCase {

	private BibtexSerializer $serializer;

	protected function setUp(): void {
		$this->serializer = new BibtexSerializer();
	}

	private function sampleCsl(): array {
		return [
			'type' => 'article',
			'title' => 'The Analytical Engine',
			'author' => [ [ 'literal' => 'Ada Lovelace' ] ],
			'container-title' => 'Notes',
			'issued' => [ 'date-parts' => [ [ 1843 ] ] ],
			'URL' => 'https://example.org/notes',
		];
	}

	public function testArticleSerialization(): void {
		$out = $this->serializer->serialize( $this->sampleCsl() );
		$this->assertStringStartsWith( '@article{AdaLovelace1843TheAnalyticalEngine,', $out );
		$this->assertStringContainsString( 'author = {Ada Lovelace},', $out );
		$this->assertStringContainsString( 'title = {The Analytical Engine},', $out );
		$this->assertStringContainsString( 'journal = {Notes},', $out );
		$this->assertStringContainsString( 'year = {1843},', $out );
		$this->assertStringContainsString( 'url = {https://example.org/notes},', $out );
		$this->assertStringEndsWith( '}', $out );
	}

	public function testUnknownTypeFallsBackToMisc(): void {
		$out = $this->serializer->serialize( [ 'type' => 'performance', 'title' => 'X' ] );
		$this->assertStringStartsWith( '@misc{', $out );
	}

	public function testBracesInValuesAreProtected(): void {
		$out = $this->serializer->serialize( [ 'type' => 'book', 'title' => 'A {title} with braces' ] );
		$this->assertStringContainsString( 'title = {A \{title\} with braces},', $out );
	}

	public function testEmptyCslStillYieldsEntry(): void {
		$out = $this->serializer->serialize( [] );
		$this->assertStringStartsWith( '@misc{item,', $out );
		$this->assertStringEndsWith( '}', $out );
	}
}
