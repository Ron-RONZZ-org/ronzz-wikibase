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
		$options = [];
		foreach ( $records as $index => $record ) {
			$summary = implode( ' · ', $this->recordSummary( $record ) );
			$options[ $record['label'] . ( $summary !== '' ? " — {$summary}" : '' ) ] = (string)$index;
		}
		return $options;
	}

	protected function createFromRecord( array $record, string $classItemId ): string {
		$specs = $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
		return $this->createOrSkipItem( (string)$record['label'], $classItemId, $specs, $record );
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
