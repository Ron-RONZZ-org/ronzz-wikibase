<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Flow\StatementGuidAssigner;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;

/**
 * Unit tests for the flow-layer statement-GUID assignment.
 *
 * GUID-less statements break the entity page for logged-in users: the client
 * matches server-rendered statement DOM to the entity JSON BY GUID, and an
 * unmatched statement renders as an empty edit-mode row (the 2026-08-31 bug).
 *
 * @license GPL-2.0-or-later
 */
class StatementGuidAssignerTest extends TestCase {

	public function testAssignsGuidsToGuidLessStatements(): void {
		$item = new Item( new ItemId( 'Q42' ) );
		$item->getStatements()->addNewStatement(
			new PropertyValueSnak( new PropertyId( 'P1' ), new \DataValues\StringValue( 'x' ) )
		);

		StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );

		$guids = [];
		foreach ( $item->getStatements() as $statement ) {
			$guids[] = $statement->getGuid();
		}
		$this->assertCount( 1, $guids );
		$this->assertNotNull( $guids[0] );
		$this->assertStringStartsWith( 'Q42$', $guids[0] );
	}

	public function testLeavesExistingGuidsUntouched(): void {
		$item = new Item( new ItemId( 'Q42' ) );
		$item->getStatements()->addStatement(
			new Statement(
				new PropertyValueSnak( new PropertyId( 'P1' ), new \DataValues\StringValue( 'x' ) ),
				null,
				null,
				'Q42$EXISTING-GUID'
			)
		);
		$item->getStatements()->addNewStatement(
			new PropertyValueSnak( new PropertyId( 'P2' ), new \DataValues\StringValue( 'y' ) )
		);

		StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );

		$byProperty = [];
		foreach ( $item->getStatements() as $statement ) {
			$byProperty[$statement->getPropertyId()->getSerialization()] = $statement->getGuid();
		}
		$this->assertSame( 'Q42$EXISTING-GUID', $byProperty['P1'] );
		$this->assertNotNull( $byProperty['P2'] );
		$this->assertStringStartsWith( 'Q42$', $byProperty['P2'] );
	}

	public function testThrowsWithoutEntityId(): void {
		$item = new Item();
		$item->getStatements()->addNewStatement(
			new PropertyValueSnak( new PropertyId( 'P1' ), new \DataValues\StringValue( 'x' ) )
		);

		$this->expectException( \LogicException::class );
		StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
	}

}
