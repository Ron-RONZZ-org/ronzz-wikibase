<?php

declare( strict_types = 1 );

namespace Tests\Unit\Law;

use DataValues\StringValue;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use WikibaseCitation\CitationMapLookup;
use WikibaseCitation\CslTypeMapper;
use WikibaseCitation\StatementToCslConverter;

/**
 * A legal provision cites as "Article <n>" in its parent law, with the
 * reference code as the CSL section and the parent's legislation number as
 * the CSL number. Regression for the Q2063 "type: article" gap.
 *
 * @license GPL-2.0-or-later
 */
class LegalCitationConverterTest extends TestCase {

	private function map(): CitationMapLookup {
		return new class implements CitationMapLookup {
			public function getPropertyForField( string $field ): ?string {
				return null;
			}

			public function getSourcePropertyForField( string $field ): ?string {
				return [
					'authority' => 'P67',
					'jurisdiction' => 'P69',
					'section' => 'P78',
					'number' => 'P70',
				][$field] ?? null;
			}

			public function getTypeForClass( string $classId ): ?string {
				return null;
			}

			public function getTypeForSourceClass( string $classId ): ?string {
				return $classId === 'Q2034' ? 'legislation' : null;
			}
		};
	}

	private function lookup(): EntityLookup {
		return new class implements EntityLookup {
			public function getEntity( EntityId $entityId ) {
				if ( $entityId->getSerialization() === 'Q200' ) {
					$law = new Item( new ItemId( 'Q200' ) );
					$law->setLabel( 'en', "Code de l'éducation" );
					$law->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q1723' ) ) )
					);
					$law->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P73' ), new StringValue( 'LEGITEXT000006071191' ) )
					);
					return $law;
				}
				if ( $entityId->getSerialization() === 'Q100' ) {
					$provision = new Item( new ItemId( 'Q100' ) );
					$provision->setLabel( 'en', "Article L111-1 — Code de l'éducation" );
					$provision->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q2034' ) ) )
					);
					$provision->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P45' ), new EntityIdValue( new ItemId( 'Q200' ) ) )
					);
					$provision->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P78' ), new StringValue( 'L111-1' ) )
					);
					return $provision;
				}
				return null;
			}

			public function hasEntity( EntityId $entityId ) {
				return in_array( $entityId->getSerialization(), [ 'Q100', 'Q200' ], true );
			}
		};
	}

	public function testProvisionCitesAsArticleInItsLaw(): void {
		$converter = new StatementToCslConverter(
			$this->lookup(),
			$this->map(),
			new CslTypeMapper( $this->map() ),
			'P31',
			[ 'Q2034', 'Q1723' ],
			'P45',
			null,
			'Q2034',
			'P78',
			'P73'
		);
		$provision = $this->lookup()->getEntity( new ItemId( 'Q100' ) );
		$this->assertInstanceOf( Item::class, $provision );
		$csl = $converter->toCslJson( $provision, 'en' );

		$this->assertSame( 'legislation', $csl['type'] );
		$this->assertSame( 'Article L111-1', $csl['title'] );
		$this->assertSame( "Code de l'éducation", $csl['container-title'] );
		$this->assertSame( 'L111-1', $csl['section'] );
		$this->assertSame( 'LEGITEXT000006071191', $csl['number'] );
	}
}
