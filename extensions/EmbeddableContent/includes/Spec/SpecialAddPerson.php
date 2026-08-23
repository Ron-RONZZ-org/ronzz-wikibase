<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;
use Wikibase\DataModel\Entity\EntityIdValue;

/**
 * Special:AddPerson — create a person item from an external authority
 * (ORCID / VIAF / ISNI / Wikidata Q / name lookup), issue #7.
 *
 * Class is fixed to `person`; given/family names, authority IDs and the
 * birth/death facts are harvested where the provider returns them. The
 * review step adds date-of-birth/place-of-birth fields and a "deceased"
 * toggle revealing the date/place of death.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddPerson extends SpecialAddExternalEntity {

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddPerson', $config, $client );
	}

	protected function kindKey(): string {
		return 'person';
	}

	protected function buildSearchFields(): array {
		return [
			'name' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-name',
				'required' => false,
				'maxlength' => 250,
			],
			'orcid' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-orcid',
				'required' => false,
				'maxlength' => 19,
				'placeholder' => '0000-0000-0000-0000',
			],
			'viaf' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-viaf',
				'required' => false,
				'maxlength' => 32,
				'placeholder' => 'e.g. 29500134',
			],
			'isni' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-isni',
				'required' => false,
				'maxlength' => 32,
				'placeholder' => 'e.g. 0000 0001 2345 6789',
			],
		];
	}

	protected function search( array $data ): ProviderResult {
		$viaf = trim( (string)( $data['viaf'] ?? '' ) );
		if ( $viaf !== '' ) {
			return $this->client->byViaf( $viaf );
		}
		$isni = trim( (string)( $data['isni'] ?? '' ) );
		if ( $isni !== '' ) {
			return $this->client->byIsni( $isni );
		}
		$orcid = trim( (string)( $data['orcid'] ?? '' ) );
		if ( $orcid !== '' ) {
			return $this->client->byOrcid( $orcid );
		}
		$name = trim( (string)( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return new ProviderResult( [], [ 'No name, ORCID, VIAF or ISNI given' ] );
		}
		return $this->client->searchPersons( $name );
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		return (string)( $record['label'] ?? '' );
	}

	/** @return array<string,string> authority identifiers relevant to persons */
	protected function externalIdRecordMap(): array {
		return [
			'wikidata' => 'wikidataId',
			'orcid' => 'orcid',
			'viaf' => 'viafId',
			'isni' => 'isni',
		];
	}

	protected function harvest( string $qid ): ProviderResult {
		return $this->client->harvestPerson( $qid );
	}

	/**
	 * Persons harvest from ANY provider that resolved a Wikidata id (the
	 * dblp/OpenAlex candidates carry hub-derived Q-ids and are enriched from
	 * Wikidata) — unlike the other kinds, which only harvest hub records.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function canHarvest( array $record ): bool {
		return true;
	}

	protected function reviewFieldSpecs( array $record ): array {
		$deceased = !empty( $record['dateOfDeath'] ) || !empty( $record['placeOfDeath'] );
		return $this->labelFieldSpec( 'label', 'embeddablecontent-add-label', (string)( $record['label'] ?? '' ) )
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				'givenName' => $this->plainTextField( 'embeddablecontent-field-givenname', (string)( $record['givenName'] ?? '' ) ),
				'familyName' => $this->plainTextField( 'embeddablecontent-field-familyname', (string)( $record['familyName'] ?? '' ) ),
				'dateOfBirth' => [
					'type' => 'date',
					'label-message' => 'embeddablecontent-field-dateofbirth',
					'default' => (string)( $record['dateOfBirth'] ?? '' ),
				],
				'placeOfBirth' => $this->entityComboboxSpec(
					'embeddablecontent-field-placeofbirth',
					(string)( $record['placeOfBirth'] ?? '' )
				),
				'deceased' => [
					'type' => 'check',
					'label-message' => 'embeddablecontent-field-deceased',
					'default' => $deceased,
				],
				'dateOfDeath' => [
					'type' => 'date',
					'label-message' => 'embeddablecontent-field-dateofdeath',
					'default' => (string)( $record['dateOfDeath'] ?? '' ),
					'hide-if' => [ '!==', 'deceased', '1' ],
				],
				'placeOfDeath' => $this->entityComboboxSpec(
					'embeddablecontent-field-placeofdeath',
					(string)( $record['placeOfDeath'] ?? '' ),
					[ 'hide-if' => [ '!==', 'deceased', '1' ] ]
				),
			]
			+ $this->externalIdFieldSpecs( $record );
	}

	/**
	 * Entity combobox referencing an existing local item (place of birth /
	 * place of death). The default is a harvested QID, corrected by hand.
	 */
	private function entityComboboxSpec( string $messageKey, string $default, array $extra = [] ): array {
		return array_merge( [
			'type' => 'combobox',
			'options' => [],
			'label-message' => $messageKey,
			'cssclass' => 'wb-entity-combobox',
			'default' => $default,
		], $extra );
	}

	/**
	 * Person statement specs: the base authority/citation facts plus the
	 * birth/death facts — dates as day-precision TimeValues, places as
	 * entity values referencing existing local items.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue|\Wikibase\DataModel\DataValue[]>
	 */
	protected function statementSpecs( array $record ): array {
		$specs = parent::statementSpecs( $record );
		$props = $this->config->personPropertyIds();

		foreach ( [ 'dateOfBirth', 'dateOfDeath' ] as $field ) {
			if ( !isset( $props[$field] ) || empty( $record[$field] ) ) {
				continue;
			}
			$time = $this->dateToTimeValue( (string)$record[$field] );
			if ( $time !== null ) {
				$specs[$props[$field]] = $time;
			}
		}
		foreach ( [ 'placeOfBirth', 'placeOfDeath' ] as $field ) {
			if ( !isset( $props[$field] ) || empty( $record[$field] ) ) {
				continue;
			}
			$itemId = $this->parseItemId( (string)$record[$field] );
			if ( $itemId !== null ) {
				$specs[$props[$field]] = new EntityIdValue( $itemId );
			}
		}
		return $specs;
	}

	// ------------------------------------------------------------- classic page
	// The base afterCreate() writes a sitelinked Person:<label> page (the
	// issue-#26 AddSoftware pattern); this class declares the page facts.

	protected function pageNamespace(): ?int {
		return defined( 'NS_PERSON' ) ? NS_PERSON : null;
	}

	protected function pageTemplate(): string {
		return 'Person';
	}

	/**
	 * Person: page skeleton — prose lives on the page, facts in the item.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		return "{{Person}}\n\n== Biography ==\n\n<!-- Life, work, legacy. -->\n\n"
			. "== Works ==\n\n== See also ==\n" . $marker;
	}

	protected function classOptions(): array {
		$classes = $this->config->agentClasses();
		$options = [];
		foreach ( $classes as $key => $id ) {
			if ( $key === 'person' ) {
				$options['person'] = $id;
			}
		}
		return $options;
	}

	protected function defaultClassItemId( array $record ): ?string {
		return $this->config->agentClasses()['person'] ?? null;
	}
}
