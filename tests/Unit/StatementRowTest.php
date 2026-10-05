<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\ParserFunctions\StatementRow;
use PHPUnit\Framework\TestCase;

/**
 * Pure assembly test for the conditional infobox row (`{{#statement-row:}}`).
 * The MediaWiki-bound decision (property resolution + statement presence via
 * the entity lookup) is covered by the dev-stack / production parse E2E.
 *
 * @license GPL-2.0-or-later
 */
class StatementRowTest extends TestCase {

	public function testBuildRowEmitsACompleteTableRow(): void {
		$this->assertSame(
			"\n|-\n| Date of birth || {{#statements:P569}}",
			StatementRow::buildRow( 'Date of birth', '{{#statements:P569}}' )
		);
	}

	public function testBuildRowKeepsAnArbitraryValueExpression(): void {
		$this->assertSame(
			"\n|-\n| Place of birth || {{#osm-place:birth}}",
			StatementRow::buildRow( 'Place of birth', '{{#osm-place:birth}}' )
		);
	}
}
