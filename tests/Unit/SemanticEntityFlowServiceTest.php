<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SemanticEntityFlowService;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;

/**
 * Unit tests for the entity-mode semantic-entity pipeline.
 *
 * @license GPL-2.0-or-later
 */
class SemanticEntityFlowServiceTest extends TestCase {

	private const CONFIG = [
		'instanceOf' => 'P1',
		'classes' => [ 'quotation' => 'Q2', 'code' => 'Q3', 'math' => 'Q4' ],
		'payloadProperties' => [ 'quotation' => 'P2', 'code' => 'P3', 'math' => 'P4' ],
		'programmingLanguage' => 'P5',
		'fallbackLanguages' => [ 'en' ],
		'personProperties' => [
			'dateOfBirth' => 'P50', 'placeOfBirth' => 'P51', 'dateOfDeath' => 'P52',
			'placeOfDeath' => 'P53', 'officialWebsite' => 'P36',
		],
		'fossProperties' => [
			'developer' => 'P33', 'license' => 'P34', 'operatingSystem' => 'P35',
			'officialWebsite' => 'P36', 'sourceRepository' => 'P37', 'hasUse' => 'P39',
			'userInterface' => 'P41', 'documentationUrl' => 'P43',
		],
		'collectiveProperties' => [ 'parentOrganization' => 'P60', 'officialWebsite' => 'P36' ],
		'fictionalCharacterProperties' => [ 'appearsIn' => 'P59' ],
		'externalIds' => [
			'wikidata' => 'P12', 'orcid' => 'P13', 'viaf' => 'P14', 'isni' => 'P15',
			'openalexAuthor' => 'P58',
		],
		'agentClasses' => [ 'person' => 'Q6', 'organization' => 'Q7' ],
	];

	private function makeService(): SemanticEntityFlowService {
		$lookup = new class implements EntityLookup {
			public function getEntity( EntityId $entityId ) {
				if ( $entityId->getSerialization() === 'Q42' ) {
					$item = new Item( new ItemId( 'Q42' ) );
					$item->setLabel( 'en', 'The Hobbit' );
					return $item;
				}
				return null;
			}

			public function hasEntity( EntityId $entityId ) {
				return $entityId->getSerialization() === 'Q42';
			}
		};
		return new SemanticEntityFlowService(
			new EmbeddableContentConfig( self::CONFIG ),
			$lookup,
			static fn ( string $key, array $params ) => $key
		);
	}

	public function testPersonLabelAndStatements(): void {
		$service = $this->makeService();
		$record = [
			'givenName' => 'Ada', 'familyName' => 'Lovelace',
			'dateOfBirth' => '1815-12-10', 'placeOfBirth' => 'Q42',
			'orcid' => '0000-0001-0002-0003', 'officialWebsite' => 'https://example.org/ada',
		];

		$this->assertNull( $service->prepare( 'person', $record, true ) );
		$this->assertSame( 'Ada Lovelace', $service->labelFor( 'person', $record ) );

		$specs = $service->statementSpecs( 'person', $record );
		$this->assertSame( 'Q42', $specs['P51']->getEntityId()->getSerialization() );
		$this->assertInstanceOf( TimeValue::class, $specs['P50'] );
		$this->assertSame( '0000-0001-0002-0003', $specs['P13']->getValue() );
		$this->assertSame( 'https://example.org/ada', $specs['P36']->getValue() );
	}

	public function testPersonRequiresANamePart(): void {
		$service = $this->makeService();
		$record = [ 'description' => 'no name here' ];
		$this->assertSame(
			SemanticEntityFlowService::ERROR_NAME_REQUIRED,
			$service->prepare( 'person', $record, true )
		);
	}

	public function testSoftwareStatements(): void {
		$service = $this->makeService();
		$record = [
			'label' => 'Flameshot', 'developer' => 'Q6', 'license' => 'Q7',
			'programmingLanguage' => 'Q42', 'sourceCodeRepository' => 'https://github.com/x',
		];

		$this->assertNull( $service->prepare( 'software', $record, true ) );
		$specs = $service->statementSpecs( 'software', $record );
		$this->assertSame( 'Q6', $specs['P33']->getEntityId()->getSerialization() );
		$this->assertSame( 'Q42', $specs['P5']->getEntityId()->getSerialization() );
		$this->assertSame( 'https://github.com/x', $specs['P37']->getValue() );
	}

	public function testCollectiveClassPresetResolves(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'UN', 'collectiveClass' => 'organization', 'parentOrganization' => 'Q42' ];

		$this->assertNull( $service->prepare( 'collective', $record, true ) );
		$this->assertSame( 'Q7', $record['collectiveClass'] );
		$specs = $service->statementSpecs( 'collective', $record );
		$this->assertSame( 'Q7', $specs['P1']->getEntityId()->getSerialization() );
		$this->assertSame( 'Q42', $specs['P60']->getEntityId()->getSerialization() );
	}

	public function testCollectiveClassPresetKeyUnknown(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'X', 'collectiveClass' => 'not-a-preset' ];
		$this->assertIsString( $service->prepare( 'collective', $record, true ) );
	}

	public function testFictionalCharacterLabelDescriptionAndAppearsIn(): void {
		$service = $this->makeService();
		$record = [ 'givenName' => 'Sherlock', 'familyName' => 'Holmes', 'presentInWork' => 'Q42' ];

		$this->assertNull( $service->prepare( 'fictional-character', $record, true ) );
		$this->assertSame(
			'Sherlock Holmes (fictional character)',
			$service->labelFor( 'fictional-character', $record )
		);
		// Description auto-generated from the work label (best-effort).
		$this->assertSame( 'fictional character in The Hobbit', $record['description'] );
		$specs = $service->statementSpecs( 'fictional-character', $record );
		$this->assertSame( [ 'Q42' ], array_map(
			static fn ( $v ) => $v->getEntityId()->getSerialization(),
			$specs['P59']
		) );
	}

	public function testOtherRequiresInstanceOfAndWritesIt(): void {
		$service = $this->makeService();
		$noInstance = [ 'label' => 'Anything' ];
		$this->assertSame(
			SemanticEntityFlowService::ERROR_INSTANCE_OF_REQUIRED,
			$service->prepare( 'other', $noInstance, true )
		);

		$record = [ 'label' => 'Anything', 'instanceOf' => 'Q42' ];
		$this->assertNull( $service->prepare( 'other', $record, true ) );
		$specs = $service->statementSpecs( 'other', $record );
		$this->assertSame( 'Q42', $specs['P1']->getEntityId()->getSerialization() );
	}

	public function testRejectsFieldTheKindDoesNotAccept(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'x', 'presentInWork' => 'Q42' ];
		$error = $service->prepare( 'software', $record, true );
		$this->assertIsString( $error );
		$this->assertStringContainsString( 'does not accept the field(s) presentInWork', $error );
	}

	public function testApplyUpdateIsNoClobber(): void {
		$service = $this->makeService();
		$record = [ 'givenName' => 'Ada', 'familyName' => 'Lovelace', 'orcid' => '0000-0001' ];
		$item = $service->buildItem( 'person', $record );

		$service->applyUpdate( 'person', $item, [ 'orcid' => '0000-0002' ] );

		$properties = [];
		foreach ( $item->getStatements() as $statement ) {
			$properties[] = $statement->getPropertyId()->getSerialization();
		}
		$this->assertSame( 1, count( array_keys( $properties, 'P13', true ) ) );
		$orcid = null;
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getPropertyId()->getSerialization() === 'P13' ) {
				$orcid = $statement->getMainSnak()->getDataValue();
			}
		}
		$this->assertSame( '0000-0002', $orcid->getValue() );
	}
}
