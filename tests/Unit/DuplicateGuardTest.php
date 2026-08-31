<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Spec\DuplicateGuard;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the duplication-guard pair assembly (the record's signal
 * pairs — external ids + web URLs — from the config property maps).
 *
 * @license GPL-2.0-or-later
 */
class DuplicateGuardTest extends TestCase {

	private const CONFIG = [
		'instanceOf' => 'P1',
		'classes' => [ 'quotation' => 'Q2', 'code' => 'Q3', 'math' => 'Q4' ],
		'payloadProperties' => [ 'quotation' => 'P2', 'code' => 'P3', 'math' => 'P4' ],
		'programmingLanguage' => 'P5',
		'provenance' => [ 'sourceUrl' => 'P7', 'date' => 'P8' ],
		'fallbackLanguages' => [ 'en' ],
		'externalIds' => [
			'wikidata' => 'P12', 'orcid' => 'P13', 'openalex' => 'P59',
			'openalexAuthor' => 'P58',
		],
		'personProperties' => [ 'officialWebsite' => 'P36' ],
		'collectiveProperties' => [ 'officialWebsite' => 'P36' ],
		'fossProperties' => [
			'officialWebsite' => 'P36', 'sourceRepository' => 'P37',
			'documentationUrl' => 'P43',
		],
		'sourceProperties' => [ 'url' => 'P44', 'accessUrl' => 'P55' ],
	];

	public function testEmitsExternalIdPairsFromRecord(): void {
		$pairs = DuplicateGuard::pairsFor( new EmbeddableContentConfig( self::CONFIG ), [
			'wikidataId' => 'Q28771536',
			'orcid' => '0000-0002-1234',
		] );
		$this->assertSame( 'Q28771536', $pairs['P12'] );
		$this->assertSame( '0000-0002-1234', $pairs['P13'] );
		$this->assertCount( 2, $pairs );
	}

	public function testEmitsUrlPairsAcrossKinds(): void {
		$pairs = DuplicateGuard::pairsFor( new EmbeddableContentConfig( self::CONFIG ), [
			'officialWebsite' => 'https://example.org',
			'sourceCodeRepository' => 'https://github.com/x/y',
			'documentationUrl' => 'https://docs.example.org',
			'url' => 'https://site.example.org/page',
		] );
		$this->assertSame( 'https://example.org', $pairs['P36'] );
		$this->assertSame( 'https://github.com/x/y', $pairs['P37'] );
		$this->assertSame( 'https://docs.example.org', $pairs['P43'] );
		$this->assertSame( 'https://site.example.org/page', $pairs['P44'] );
		$this->assertCount( 4, $pairs );
	}

	public function testSkipsAbsentConfigKeysAndEmptyValues(): void {
		// 'pubmed' is not in this config slice → the record's pubmedId field
		// must produce no pair; the empty orcid is skipped too.
		$pairs = DuplicateGuard::pairsFor( new EmbeddableContentConfig( self::CONFIG ), [
			'wikidataId' => 'Q1',
			'pubmedId' => '12345',
			'orcid' => '',
			'accessUrl' => 'https://download.example.org/x.pdf',
		] );
		$this->assertSame( [ 'P12' => 'Q1', 'P55' => 'https://download.example.org/x.pdf' ], $pairs );
	}

	public function testTrimsValuesAndRequiresStrings(): void {
		$pairs = DuplicateGuard::pairsFor( new EmbeddableContentConfig( self::CONFIG ), [
			'wikidataId' => '  Q42  ',
			'orcid' => 42,
		] );
		$this->assertSame( [ 'P12' => 'Q42' ], $pairs );
	}

}
