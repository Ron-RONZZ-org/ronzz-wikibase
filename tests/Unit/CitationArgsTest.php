<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\ParserFunctions\CitationArgs;

/**
 * Parser-function argument parsing (issue #24/#25): MW 1.46 passes named
 * args as literal "key=value" strings; positional entity ids (`Q\d+`) are
 * collected for the v2 multi-entity / explicit-args syntax, while other
 * positional args keep the v1 style/output meaning.
 *
 * @license GPL-2.0-or-later
 */
class CitationArgsTest extends TestCase {

	public function testNoArgs(): void {
		$this->assertSame(
			[ 'entities' => [], 'style' => '', 'output' => '' ],
			CitationArgs::parse( [] )
		);
	}

	public function testSingleEntityIdIsCollected(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42' ], 'style' => '', 'output' => '' ],
			CitationArgs::parse( [ 'Q42' ] )
		);
	}

	public function testMultiEntityIdsAreCollectedInOrder(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42', 'Q7' ], 'style' => '', 'output' => '' ],
			CitationArgs::parse( [ 'Q42', 'Q7' ] )
		);
	}

	public function testLowercaseEntityIdsAreNormalizedAsEntities(): void {
		// The arg list keeps the spelling; the engine normalizes on parse.
		$this->assertSame(
			[ 'entities' => [ 'q42' ], 'style' => '', 'output' => '' ],
			CitationArgs::parse( [ 'q42' ] )
		);
	}

	public function testEntityIdAndNamedStyle(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42' ], 'style' => 'vancouver', 'output' => '' ],
			CitationArgs::parse( [ 'Q42', 'style=vancouver' ] )
		);
	}

	public function testMultiEntityWithNamedStyleAndOutput(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42', 'Q7' ], 'style' => 'apa', 'output' => 'html' ],
			CitationArgs::parse( [ 'Q42', 'Q7', 'style=apa', 'output=html' ] )
		);
	}

	public function testV1PositionalStyleStillWorks(): void {
		// 'vancouver' is not an entity id → keeps the v1 meaning.
		$this->assertSame(
			[ 'entities' => [ 'Q42' ], 'style' => 'vancouver', 'output' => '' ],
			CitationArgs::parse( [ 'Q42', 'vancouver' ] )
		);
	}

	public function testV1PositionalStyleAndOutputStillWorks(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42' ], 'style' => 'apa', 'output' => 'html' ],
			CitationArgs::parse( [ 'Q42', 'apa', 'html' ] )
		);
	}

	public function testMixedEntityAndPositionalStyle(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42', 'Q7' ], 'style' => 'bibtex', 'output' => '' ],
			CitationArgs::parse( [ 'Q42', 'Q7', 'bibtex' ] )
		);
	}

	public function testNamedValuesAreTrimmed(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42' ], 'style' => 'apa', 'output' => '' ],
			CitationArgs::parse( [ '  Q42 ', '  style =  apa  ' ] )
		);
	}

	public function testUnknownNamedKeysAreIgnored(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42' ], 'style' => 'apa', 'output' => '' ],
			CitationArgs::parse( [ 'Q42', 'style=apa', 'group=main' ] )
		);
	}

	public function testEmptyAndNonStringArgsAreSkipped(): void {
		$this->assertSame(
			[ 'entities' => [], 'style' => '', 'output' => '' ],
			CitationArgs::parse( [ '', null, '   ' ] )
		);
	}

	public function testNamedWinsOverPositional(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42' ], 'style' => 'vancouver', 'output' => '' ],
			CitationArgs::parse( [ 'Q42', 'apa', 'style=vancouver' ] )
		);
	}

	public function testCaseInsensitiveKeys(): void {
		$this->assertSame(
			[ 'entities' => [ 'Q42' ], 'style' => 'apa', 'output' => '' ],
			CitationArgs::parse( [ 'Q42', 'STYLE=apa' ] )
		);
	}

	public function testPropertyIdsAreAlsoEntities(): void {
		// P-ids parse as entities too; the engine rejects non-item ids.
		$this->assertSame(
			[ 'entities' => [ 'P12' ], 'style' => '', 'output' => '' ],
			CitationArgs::parse( [ 'P12' ] )
		);
	}
}
