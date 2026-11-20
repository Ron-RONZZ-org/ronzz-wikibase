<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use DataValues\StringValue;
use EmbeddableContent\Spec\StatementSnapshot;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use EmbeddableContent\PropertyIdFactory;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;

/**
 * Unit tests for the Update* form-open statement snapshot
 * (Spec\StatementSnapshot).
 *
 * @covers \EmbeddableContent\Spec\StatementSnapshot
 * @license GPL-2.0-or-later
 */
final class StatementSnapshotTest extends TestCase {

	private function itemWith( array $values ): Item {
		$item = new Item( new ItemId( 'Q42' ) );
		$i = 0;
		foreach ( $values as $propertyId => $value ) {
			$i++;
			$item->getStatements()->addStatement( new Statement(
				new PropertyValueSnak( PropertyIdFactory::create( $propertyId ), new StringValue( $value ) ),
				null,
				null,
				sprintf( 'Q42$00000000-0000-0000-0000-%012d', $i )
			) );
		}
		return $item;
	}

	public function testRowsCapturePopulatedPropertiesOnly(): void {
		$item = $this->itemWith( [ 'P31' => 'note', 'P32' => 'other' ] );

		$rows = StatementSnapshot::rowsFor( $item, [
			'note' => 'P31',
			'missing' => 'P99',
			'sourceUrl' => 'P32',
		] );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'note', $rows[0]['field'] );
		$this->assertSame( 'P31', $rows[0]['property'] );
		$this->assertNotEmpty( $rows[0]['guids'] );
		$this->assertNotEmpty( $rows[0]['hashes'] );
	}

	public function testMatchesOnlyTheUnchangedStatementSet(): void {
		$item = $this->itemWith( [ 'P31' => 'note' ] );
		$rows = StatementSnapshot::rowsFor( $item, [ 'note' => 'P31' ] );

		$this->assertTrue( StatementSnapshot::matches( $item, $rows[0] ) );

		// A changed value (new GUID + new hash) must not match.
		$item->getStatements()->clear();
		$item->getStatements()->addStatement( new Statement(
			new PropertyValueSnak( PropertyIdFactory::create( 'P31' ), new StringValue( 'changed' ) ),
			null,
			null,
			'Q42$11111111-1111-1111-1111-111111111111'
		) );
		$this->assertFalse( StatementSnapshot::matches( $item, $rows[0] ) );
	}

	public function testClearPropertiesRequiresABlankFieldAndAMatchingSnapshot(): void {
		$item = $this->itemWith( [ 'P31' => 'note', 'P32' => 'keep' ] );
		$rows = StatementSnapshot::rowsFor( $item, [
			'note' => 'P31',
			'keep' => 'P32',
		] );

		$this->assertSame(
			[ 'P31' ],
			StatementSnapshot::clearProperties( $item, $rows, static fn ( string $field ): bool => $field === 'note' )
		);
		$this->assertSame(
			[],
			StatementSnapshot::clearProperties( $item, $rows, static fn ( string $field ): bool => false )
		);
	}

	public function testClearPropertiesKeepsAConcurrentEdit(): void {
		$item = $this->itemWith( [ 'P31' => 'note' ] );
		$rows = StatementSnapshot::rowsFor( $item, [ 'note' => 'P31' ] );
		$item->getStatements()->clear();
		$item->getStatements()->addStatement( new Statement(
			new PropertyValueSnak( PropertyIdFactory::create( 'P31' ), new StringValue( 'newer' ) ),
			null,
			null,
			'Q42$22222222-2222-2222-2222-222222222222'
		) );

		$this->assertSame(
			[],
			StatementSnapshot::clearProperties( $item, $rows, static fn ( string $field ): bool => true )
		);
	}

	public function testDecodeDropsMalformedRows(): void {
		$rows = StatementSnapshot::decode( json_encode( [
			[ 'field' => 'note', 'property' => 'P31', 'guids' => [ 'g' ], 'hashes' => [ 'h' ] ],
			[ 'field' => 'broken' ],
			'not-a-row',
		] ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'note', $rows[0]['field'] );
		$this->assertSame( [], StatementSnapshot::decode( 'not json' ) );
	}

}
