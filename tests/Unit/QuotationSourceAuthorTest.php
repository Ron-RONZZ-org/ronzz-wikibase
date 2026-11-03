<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\QuotationSourceAuthor;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * AddQuotation author inheritance: a single-author source supplies the
 * attribution; a multi-author source is never guessed.
 *
 * @license GPL-2.0-or-later
 */
class QuotationSourceAuthorTest extends TestCase {

	private const AUTHOR_PROPERTY = 'P6';

	private function sourceItem( array $authorIds ): Item {
		$item = new Item( new ItemId( 'Q10' ) );
		foreach ( $authorIds as $authorId ) {
			$item->getStatements()->addNewStatement(
				new PropertyValueSnak(
					new PropertyId( self::AUTHOR_PROPERTY ),
					new EntityIdValue( new ItemId( $authorId ) )
				)
			);
		}
		return $item;
	}

	private function lookupFor( ?EntityDocument $entity ): EntityLookup {
		return new class( $entity ) implements EntityLookup {
			public function __construct( private ?EntityDocument $entity ) {
			}

			public function getEntity( EntityId $entityId ): ?EntityDocument {
				return $this->entity !== null && $entityId->equals( $this->entity->getId() )
					? $this->entity
					: null;
			}

			public function hasEntity( EntityId $entityId ): bool {
				return $this->entity !== null && $entityId->equals( $this->entity->getId() );
			}
		};
	}

	private function record( array $overrides = [] ): array {
		return $overrides + [ 'source' => 'Q10' ];
	}

	public function testSingleAuthorIsInherited(): void {
		$record = $this->record();
		$error = QuotationSourceAuthor::inherit(
			$record,
			$this->lookupFor( $this->sourceItem( [ 'Q6' ] ) ),
			self::AUTHOR_PROPERTY
		);
		$this->assertNull( $error );
		$this->assertSame( 'Q6', $record['attributedTo'] );
	}

	public function testMultipleAuthorsAskTheUserToChoose(): void {
		$record = $this->record();
		$error = QuotationSourceAuthor::inherit(
			$record,
			$this->lookupFor( $this->sourceItem( [ 'Q6', 'Q7' ] ) ),
			self::AUTHOR_PROPERTY
		);
		$this->assertSame( QuotationSourceAuthor::ERROR_MULTIPLE_AUTHORS, $error );
		$this->assertArrayNotHasKey( 'attributedTo', $record );
	}

	public function testSourceWithoutAuthorLeavesTheAuthorBlank(): void {
		$record = $this->record();
		$error = QuotationSourceAuthor::inherit(
			$record,
			$this->lookupFor( $this->sourceItem( [] ) ),
			self::AUTHOR_PROPERTY
		);
		$this->assertNull( $error );
		$this->assertArrayNotHasKey( 'attributedTo', $record );
	}

	public function testExplicitAuthorWins(): void {
		$record = [ 'source' => 'Q10', 'attributedTo' => 'Q7' ];
		$error = QuotationSourceAuthor::inherit(
			$record,
			$this->lookupFor( $this->sourceItem( [ 'Q6' ] ) ),
			self::AUTHOR_PROPERTY
		);
		$this->assertNull( $error );
		$this->assertSame( 'Q7', $record['attributedTo'] );
	}

	public function testMissingSourceEntityIsNonFatal(): void {
		$record = $this->record();
		$error = QuotationSourceAuthor::inherit(
			$record,
			$this->lookupFor( null ),
			self::AUTHOR_PROPERTY
		);
		$this->assertNull( $error );
		$this->assertArrayNotHasKey( 'attributedTo', $record );
	}

}
