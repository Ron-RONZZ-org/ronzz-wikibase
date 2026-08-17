<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;

/**
 * Special:AddCollective — create a non-person agent item (organization,
 * company, band, collective, institution) from Wikidata, issue #7.
 *
 * Class is inferred from the harvested instance-of hints (mapped through the
 * config), with a manual class picker fallback.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddCollective extends SpecialAddExternalEntity {

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddCollective', $config, $client );
	}

	protected function kindKey(): string {
		return 'collective';
	}

	protected function buildSearchFields(): array {
		return [
			'name' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-name',
				'required' => true,
				'maxlength' => 250,
			],
		];
	}

	protected function search( array $data ): ProviderResult {
		$name = trim( (string)( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return new ProviderResult( [], [ 'No name given' ] );
		}
		return $this->client->searchEntities( $name );
	}

	protected function candidateOptions( array $records ): array {
		$options = [];
		foreach ( $records as $index => $record ) {
			$summary = implode( ' · ', $this->recordSummary( $record ) );
			$label = (string)$record['label'];
			if ( !empty( $record['description'] ) ) {
				$label .= ' — ' . $record['description'];
			}
			$options[ $label . ( $summary !== '' ? " ({$summary})" : '' ) ] = (string)$index;
		}
		return $options;
	}

	protected function createFromRecord( array $record, string $classItemId ): string {
		// Harvest on pick: enrich with the full Wikidata record (class hints
		// for the inference, description).
		if ( !empty( $record['wikidataId'] ) && ( $record['provider'] ?? '' ) === 'wikidata' ) {
			$harvest = $this->client->harvestEntity( $record['wikidataId'] );
			if ( $harvest->records !== [] ) {
				$record = array_merge( $record, (array)$harvest->records[0] );
			}
		}
		$specs = $this->externalIdStatements( $record );
		return $this->createOrSkipItem( (string)$record['label'], $classItemId, $specs, $record );
	}

	protected function classOptions(): array {
		$options = [];
		foreach ( $this->config->agentClasses() as $key => $id ) {
			if ( $key !== 'person' ) {
				$options[$key] = $id;
			}
		}
		return $options;
	}

	protected function defaultClassItemId( array $record ): ?string {
		foreach ( $record['classWikidataIds'] ?? [] as $qid ) {
			$key = $this->config->agentClassByWikidata()[$qid] ?? null;
			if ( $key !== null && isset( $this->config->agentClasses()[$key] ) ) {
				return $this->config->agentClasses()[$key];
			}
		}
		return $this->config->agentClasses()['organization'] ?? null;
	}
}
