<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use EmbeddableContent\Fetch\ProviderResult;

/**
 * Special:AddPerson — create a person item from an external authority
 * (ORCID / Wikidata Q / name lookup), issue #7.
 *
 * Class is fixed to `person`; given/family names and authority IDs are
 * harvested where the provider returns them.
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
		];
	}

	protected function search( array $data ): ProviderResult {
		$orcid = trim( (string)( $data['orcid'] ?? '' ) );
		if ( $orcid !== '' ) {
			return $this->client->byOrcid( $orcid );
		}
		$name = trim( (string)( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return new ProviderResult( [], [ 'No name or ORCID given' ] );
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

	protected function enrichRecord( array $record ): array {
		if ( !empty( $record['harvested'] ) ) {
			return $record;
		}
		// Harvest on pick: enrich the light search record with the full
		// Wikidata record (given/family names, ORCID, VIAF, ISNI).
		if ( !empty( $record['wikidataId'] ) ) {
			$harvest = $this->client->harvestPerson( $record['wikidataId'] );
			if ( $harvest->records !== [] ) {
				$record = array_merge( $record, (array)$harvest->records[0] );
			}
		}
		$record['harvested'] = true;
		return $record;
	}

	protected function reviewFieldSpecs( array $record ): array {
		return $this->labelFieldSpec( 'label', 'embeddablecontent-add-label', (string)( $record['label'] ?? '' ) )
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				'givenName' => $this->plainTextField( 'embeddablecontent-field-givenname', (string)( $record['givenName'] ?? '' ) ),
				'familyName' => $this->plainTextField( 'embeddablecontent-field-familyname', (string)( $record['familyName'] ?? '' ) ),
			]
			+ $this->externalIdFieldSpecs( $record );
	}

	protected function createFromRecord( array $record, string $classItemId ): string {
		$record = $this->enrichRecord( $record );
		$specs = $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
		return $this->createOrSkipItem( $this->primaryLabel( $record ), $classItemId, $specs, $record );
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
