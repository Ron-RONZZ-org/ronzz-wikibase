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
			'placeOfDeath' => 'P53', 'placeOfBirthOsm' => 'P61', 'placeOfDeathOsm' => 'P62',
			'placeOfBirthLabel' => 'P64', 'placeOfDeathLabel' => 'P65',
			'officialWebsite' => 'P36', 'image' => 'P63',
		],
		'fossProperties' => [
			'developer' => 'P33', 'license' => 'P34', 'operatingSystem' => 'P35',
			'officialWebsite' => 'P36', 'sourceRepository' => 'P37', 'hasUse' => 'P39',
			'userInterface' => 'P41', 'documentationUrl' => 'P43', 'image' => 'P63',
		],
		'collectiveProperties' => [ 'parentOrganization' => 'P60', 'officialWebsite' => 'P36', 'image' => 'P63' ],
		'fictionalCharacterProperties' => [ 'appearsIn' => 'P59' ],
		'externalIds' => [
			'wikidata' => 'P12', 'orcid' => 'P13', 'viaf' => 'P14', 'isni' => 'P15',
			'openalexAuthor' => 'P58',
		],
		'citationMetadata' => [ 'givenName' => 'P25', 'familyName' => 'P26' ],
		'agentClasses' => [ 'person' => 'Q6', 'organization' => 'Q7' ],
		'fossClasses' => [ 'foss' => 'Q14' ],
		'softwareClasses' => [ 'software' => 'Q23' ],
		'fictionalCharacterClasses' => [ 'fictionalCharacter' => 'Q22' ],
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
			'dateOfBirth' => '1815-12-10',
			'placeOfBirthOsm' => 'node/123', 'placeOfDeathOsm' => 'relation/456',
			// The parallel human-readable place labels (osm-places
			// follow-up): written next to their OSM ids.
			'placeOfBirthLabel' => 'London, England', 'placeOfDeathLabel' => 'Paris, France',
			'orcid' => '0000-0001-0002-0003', 'officialWebsite' => 'https://example.org/ada',
			'imageFileUrl' => 'https://example.org/File:Ada-portrait.png',
		];

		$this->assertNull( $service->prepare( 'person', $record, true ) );
		$this->assertSame( 'Ada Lovelace', $service->labelFor( 'person', $record ) );

		$specs = $service->statementSpecs( 'person', $record );
		$this->assertSame( 'Q6', $specs['P1']->getEntityId()->getSerialization() );
		$this->assertSame( 'Ada', $specs['P25']->getValue() );
		$this->assertSame( 'Lovelace', $specs['P26']->getValue() );
		$this->assertSame( 'node/123', $specs['P61']->getValue() );
		$this->assertSame( 'relation/456', $specs['P62']->getValue() );
		$this->assertSame( 'London, England', $specs['P64']->getValue() );
		$this->assertSame( 'Paris, France', $specs['P65']->getValue() );
		$this->assertInstanceOf( TimeValue::class, $specs['P50'] );
		$this->assertSame( '0000-0001-0002-0003', $specs['P13']->getValue() );
		$this->assertSame( 'https://example.org/ada', $specs['P36']->getValue() );
		$this->assertSame( 'https://example.org/File:Ada-portrait.png', $specs['P63']->getValue() );
	}

	public function testPersonPlaceLabelRequiresItsOsmId(): void {
		// A label without its OSM id is dropped (never a floating display
		// name that names nothing) — and an OSM id without a label is fine
		// (older data falls back to the raw id on the Person: page).
		$service = $this->makeService();

		$orphan = [
			'givenName' => 'Ada', 'familyName' => 'Lovelace',
			'placeOfBirthLabel' => 'London, England',
			'placeOfDeathOsm' => 'relation/456',
		];
		$this->assertNull( $service->prepare( 'person', $orphan, true ) );
		$specs = $service->statementSpecs( 'person', $orphan );
		$this->assertArrayNotHasKey( 'P64', $specs, 'an orphan place label must be dropped' );
		$this->assertSame( 'relation/456', $specs['P62']->getValue() );
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
		$this->assertSame( 'Q14', $specs['P1']->getEntityId()->getSerialization() );
		$this->assertSame( [ 'Q6' ], array_map(
			static fn ( $v ) => $v->getEntityId()->getSerialization(),
			$specs['P33']
		) );
		$this->assertSame( 'Q42', $specs['P5']->getEntityId()->getSerialization() );
		$this->assertSame( 'https://github.com/x', $specs['P37']->getValue() );
	}

	public function testSoftwareClassFollowsPageKind(): void {
		// FOSS:/Software: split — a Software: page means the item is NOT
		// free/open-source and must not carry the FOSS class (Q14); it gets
		// the plain software class (Q23) instead. Default (no pageKind, or
		// pageKind=foss) keeps the FOSS class.
		$service = $this->makeService();

		$foss = [ 'label' => 'Flameshot', 'pageKind' => 'foss' ];
		$this->assertNull( $service->prepare( 'software', $foss, true ) );
		$this->assertSame( 'Q14', $service->statementSpecs( 'software', $foss )['P1']->getEntityId()->getSerialization() );

		$nonfree = [ 'label' => 'Proprietary Thing', 'pageKind' => 'software' ];
		$this->assertNull( $service->prepare( 'software', $nonfree, true ) );
		$this->assertSame( 'Q23', $service->statementSpecs( 'software', $nonfree )['P1']->getEntityId()->getSerialization() );
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
		$this->assertSame( 'Q22', $specs['P1']->getEntityId()->getSerialization() );
		$this->assertSame( [ 'Q42' ], array_map(
			static fn ( $v ) => $v->getEntityId()->getSerialization(),
			$specs['P59']
		) );
	}

	public function testFictionalCharacterAliasesAreWritten(): void {
		$service = $this->makeService();
		$record = [
			'givenName' => 'Sherlock', 'familyName' => 'Holmes',
			'alias' => 'The Detective, Holmes',
		];
		$this->assertNull( $service->prepare( 'fictional-character', $record, true ) );
		$item = $service->buildItem( 'fictional-character', $record );
		$this->assertSame(
			[ 'The Detective', 'Holmes' ],
			$item->getAliasGroups()->toTextArray()['en'] ?? []
		);
	}

	public function testApplyUpdateAliasIsNoClobber(): void {
		$service = $this->makeService();
		$item = $service->buildItem( 'fictional-character', [
			'givenName' => 'Sherlock', 'familyName' => 'Holmes', 'alias' => 'The Detective',
		] );
		$item->setId( new ItemId( 'Q42' ) );

		// A blank alias field keeps the stored set.
		$service->applyUpdate( 'fictional-character', $item, [ 'givenName' => 'Sherlock', 'familyName' => 'Holmes' ] );
		$this->assertSame( [ 'The Detective' ], $item->getAliasGroups()->toTextArray()['en'] ?? [] );

		// A non-empty field replaces it.
		$service->applyUpdate( 'fictional-character', $item, [ 'alias' => 'Sherlock Holmes' ] );
		$this->assertSame( [ 'Sherlock Holmes' ], $item->getAliasGroups()->toTextArray()['en'] ?? [] );
	}

	public function testSplitAliasesTrimsAndDedupes(): void {
		$this->assertSame(
			[ 'The Detective', 'Holmes' ],
			SemanticEntityFlowService::splitAliases( ' The Detective , Holmes ,, The Detective ' )
		);
	}

	public function testOtherRequiresInstanceOfAndWritesIt(): void {		$service = $this->makeService();
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
		// applyUpdate runs on a LOADED item (the caller fetched it from the
		// store) — it needs the id to assign statement GUIDs.
		$item->setId( new ItemId( 'Q42' ) );

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
				// The replaced statement carries a GUID (the entity-page
				// client matches statements to the DOM by GUID — a
				// GUID-less statement renders as an empty edit-mode row).
				$this->assertNotNull( $statement->getGuid() );
			}
		}
		$this->assertSame( '0000-0002', $orcid->getValue() );
	}

	public function testBuildItemUsesLabelLanguage(): void {
		$service = $this->makeService();
		$record = [
			'givenName' => 'Ada', 'familyName' => 'Lovelace',
			'labelLanguage' => 'fr', 'description' => 'mathématicienne',
		];
		$this->assertNull( $service->prepare( 'person', $record, true ) );

		$item = $service->buildItem( 'person', $record );
		$this->assertSame( [ 'fr' ], array_keys( $item->getLabels()->toTextArray() ) );
		$this->assertSame( 'Ada Lovelace', $item->getLabels()->getByLanguage( 'fr' )->getText() );
		$this->assertSame( 'mathématicienne', $item->getDescriptions()->getByLanguage( 'fr' )->getText() );
		$this->assertFalse( $item->getLabels()->hasTermForLanguage( 'en' ) );
	}

	public function testBuildItemLabelLanguageDefaultsToEnglish(): void {
		$service = $this->makeService();
		$item = $service->buildItem( 'other', [ 'label' => 'Anything', 'instanceOf' => 'Q42' ] );
		$this->assertTrue( $item->getLabels()->hasTermForLanguage( 'en' ) );
	}

	public function testPrepareRejectsInvalidLabelLanguage(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'X', 'labelLanguage' => 'not a language code' ];
		$error = $service->prepare( 'software', $record, true );
		$this->assertIsString( $error );
		$this->assertStringContainsString( 'labelLanguage', $error );
	}

	public function testApplyUpdateMovesTermLanguage(): void {
		$service = $this->makeService();
		$item = $service->buildItem( 'person', [
			'givenName' => 'Radcliffe', 'familyName' => 'Brown', 'description' => 'mathematician',
		] );
		// applyUpdate runs on a LOADED item — it needs the id for GUIDs.
		$item->setId( new ItemId( 'Q42' ) );

		$service->applyUpdate( 'person', $item, [
			'givenName' => 'Radcliffe', 'familyName' => 'Brown',
			'labelLanguage' => 'fr', 'description' => 'mathématicien',
		] );

		// The chosen-language-only contract: the label/description now live
		// in French, and the previous English term is gone.
		$this->assertTrue( $item->getLabels()->hasTermForLanguage( 'fr' ) );
		$this->assertFalse( $item->getLabels()->hasTermForLanguage( 'en' ) );
		$this->assertSame( 'mathématicien', $item->getDescriptions()->getByLanguage( 'fr' )->getText() );
		$this->assertFalse( $item->getDescriptions()->hasTermForLanguage( 'en' ) );
	}

	public function testApplyUpdateKeepsTermLanguageWhenBlank(): void {
		$service = $this->makeService();
		$item = $service->buildItem( 'person', [
			'givenName' => 'Ada', 'familyName' => 'Lovelace', 'labelLanguage' => 'fr',
		] );
		$item->setId( new ItemId( 'Q42' ) );

		// A blank labelLanguage keeps the item's current (fr) language.
		$service->applyUpdate( 'person', $item, [ 'givenName' => 'Ada', 'familyName' => 'Lovelace' ] );
		$this->assertTrue( $item->getLabels()->hasTermForLanguage( 'fr' ) );
		$this->assertFalse( $item->getLabels()->hasTermForLanguage( 'en' ) );
	}
}
