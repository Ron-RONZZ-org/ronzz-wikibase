<?php

declare( strict_types = 1 );

namespace Tests\Unit\Content;

use EmbeddableContent\Content\ContentWikitext;
use EmbeddableContent\Content\ProvenanceWikitext;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\SiteLink;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * @covers \EmbeddableContent\Content\ProvenanceWikitext
 * @license GPL-2.0-or-later
 */
class ProvenanceWikitextTest extends TestCase {

	private const AUTHOR_PROP = 'P6';
	private const SOURCE_PROP = 'P8';

	private function lookup(): EntityLookup {
		return new class implements EntityLookup {
			public function getEntity( EntityId $entityId ) {
				switch ( $entityId->getSerialization() ) {
					case 'Q10':
						$item = new Item( new ItemId( 'Q10' ) );
						$item->setLabel( 'en', 'Ada Lovelace' );
						return $item;
					case 'Q20':
						// A source WITH a classic page (sitelink).
						$item = new Item( new ItemId( 'Q20' ) );
						$item->setLabel( 'en', 'Notes by the Translator' );
						$item->getSiteLinkList()->addSiteLink(
							new SiteLink( 'wikibase', 'Source:Notes by the Translator' )
						);
						return $item;
					case 'Q30':
						// A source WITHOUT a page.
						$item = new Item( new ItemId( 'Q30' ) );
						$item->setLabel( 'en', 'Unlinked Source' );
						return $item;
				}
				return null;
			}

			public function hasEntity( EntityId $entityId ) {
				return in_array( $entityId->getSerialization(), [ 'Q10', 'Q20', 'Q30' ], true );
			}
		};
	}

	private function itemWith( ?string $author, ?string $source ): Item {
		$item = new Item();
		if ( $author !== null ) {
			$item->getStatements()->addNewStatement(
				new PropertyValueSnak( new PropertyId( self::AUTHOR_PROP ), new EntityIdValue( new ItemId( $author ) ) )
			);
		}
		if ( $source !== null ) {
			$item->getStatements()->addNewStatement(
				new PropertyValueSnak( new PropertyId( self::SOURCE_PROP ), new EntityIdValue( new ItemId( $source ) ) )
			);
		}
		return $item;
	}

	public function testEntityIdsAreReturnedInStatementOrder(): void {
		$item = $this->itemWith( 'Q10', 'Q20' );
		$this->assertSame( [ 'Q10' ], ProvenanceWikitext::entityIds( $item, self::AUTHOR_PROP ) );
		$this->assertSame( [ 'Q20' ], ProvenanceWikitext::entityIds( $item, self::SOURCE_PROP ) );
		$this->assertSame( [], ProvenanceWikitext::entityIds( $item, null ) );
	}

	public function testAuthorLabelAndSourcelink(): void {
		$item = $this->itemWith( 'Q10', 'Q20' );
		$this->assertSame(
			[ 'Ada Lovelace' ],
			ProvenanceWikitext::authorLabels( $item, self::AUTHOR_PROP, $this->lookup() )
		);
		$this->assertSame(
			[ "''[[Source:Notes by the Translator|Notes by the Translator]]''" ],
			ProvenanceWikitext::sourceWikitexts( $item, self::SOURCE_PROP, $this->lookup() )
		);
	}

	public function testSourceWithoutPageFallsBackToItalicLabel(): void {
		$item = $this->itemWith( null, 'Q30' );
		$this->assertSame(
			[ "''Unlinked Source''" ],
			ProvenanceWikitext::sourceWikitexts( $item, self::SOURCE_PROP, $this->lookup() )
		);
	}

	public function testMissingEntityDegradesToId(): void {
		$item = $this->itemWith( 'Q999', null );
		$this->assertSame(
			[ 'Q999' ],
			ProvenanceWikitext::authorLabels( $item, self::AUTHOR_PROP, $this->lookup() )
		);
	}

	public function testAttributionJoinMatchesContentWikitext(): void {
		$item = $this->itemWith( 'Q10', 'Q20' );
		$lookup = $this->lookup();
		$attribution = ContentWikitext::quotationAttribution(
			ProvenanceWikitext::authorLabels( $item, self::AUTHOR_PROP, $lookup ),
			ProvenanceWikitext::sourceWikitexts( $item, self::SOURCE_PROP, $lookup )
		);
		$this->assertSame(
			"-Ada Lovelace, ''[[Source:Notes by the Translator|Notes by the Translator]]''",
			$attribution
		);
	}

}
