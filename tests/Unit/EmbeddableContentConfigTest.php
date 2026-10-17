<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\EmbeddableContentConfig;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class EmbeddableContentConfigTest extends TestCase {

	private const BASE = [
		'instanceOf' => 'P1',
		'classes' => [ 'quotation' => 'Q2', 'code' => 'Q3', 'math' => 'Q4' ],
		'payloadProperties' => [ 'quotation' => 'P2', 'code' => 'P3', 'math' => 'P4' ],
		'programmingLanguage' => 'P5',
		'fallbackLanguages' => [ 'en' ],
		'provenance' => [ 'attributedTo' => 'P6', 'sourceUrl' => 'P7', 'date' => 'P8' ],
	];

	public function testSourceClassSearchClassesReadsEmittedScope(): void {
		$config = new EmbeddableContentConfig( self::BASE + [
			'sourceClassSearchClasses' => [
				'book' => [ 'Q571', 'Q7725634' ],
				'scholarlyArticle' => [ 'Q13442814' ],
			],
		] );

		$this->assertSame(
			[
				'book' => [ 'Q571', 'Q7725634' ],
				'scholarlyArticle' => [ 'Q13442814' ],
			],
			$config->sourceClassSearchClasses()
		);
	}

	public function testSourceClassSearchClassesDropsMalformedIds(): void {
		$config = new EmbeddableContentConfig( self::BASE + [
			'sourceClassSearchClasses' => [
				'book' => [ 'Q571', 'not-an-id', 'Q0', 'Q7725634' ],
				'empty' => [ 'nope' ],
			],
		] );

		$this->assertSame(
			[ 'book' => [ 'Q571', 'Q7725634' ] ],
			$config->sourceClassSearchClasses()
		);
	}

	public function testSourceClassSearchClassesFallsBackToAlignedSingleClass(): void {
		$config = new EmbeddableContentConfig( self::BASE + [
			'sourceClassByWikidata' => [
				'Q571' => 'book',
				'Q13442814' => 'scholarlyArticle',
			],
		] );

		$this->assertSame(
			[
				'book' => [ 'Q571' ],
				'scholarlyArticle' => [ 'Q13442814' ],
			],
			$config->sourceClassSearchClasses()
		);
	}
}
