<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;

/**
 * Special:AddSource — create a work item (book / scholarly article / website /
 * song / film / video) from an external authority (DOI / ISBN / title lookup),
 * issue #7.
 *
 * Class is inferred from the harvest (Wikidata instance-of mapped through the
 * config, or a provider default), with a manual class picker fallback.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddSource extends SpecialAddExternalEntity {

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddSource', $config, $client );
	}

	protected function kindKey(): string {
		return 'source';
	}

	protected function buildSearchFields(): array {
		return [
			'title' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-title',
				'required' => false,
				'maxlength' => 250,
			],
			'doi' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-doi',
				'required' => false,
				'maxlength' => 250,
				'placeholder' => '10.1000/xxxx',
			],
			'isbn' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-isbn',
				'required' => false,
				'maxlength' => 17,
				'placeholder' => '978-0-00-000000-0',
			],
		];
	}

	protected function search( array $data ): ProviderResult {
		$doi = trim( (string)( $data['doi'] ?? '' ) );
		if ( $doi !== '' ) {
			return $this->client->byDoi( $doi );
		}
		$isbn = trim( (string)( $data['isbn'] ?? '' ) );
		if ( $isbn !== '' ) {
			return $this->client->byIsbn( $isbn );
		}
		$title = trim( (string)( $data['title'] ?? '' ) );
		if ( $title === '' ) {
			return new ProviderResult( [], [ 'No DOI, ISBN or title given' ] );
		}
		return $this->client->searchWorks( $title );
	}

	protected function candidateOptions( array $records ): array {
		$options = [];
		foreach ( $records as $index => $record ) {
			$summary = implode( ' · ', $this->recordSummary( $record ) );
			$label = (string)$record['title'];
			if ( !empty( $record['issuedYear'] ) ) {
				$label .= ' (' . $record['issuedYear'] . ')';
			}
			$options[ $label . ( $summary !== '' ? " — {$summary}" : '' ) ] = (string)$index;
		}
		return $options;
	}

	protected function createFromRecord( array $record, string $classItemId ): string {
		// Harvest on pick: enrich with the full Wikidata work record
		// (container, publisher, pages, volume, issue, DOI, ISBN, …).
		if ( !empty( $record['wikidataId'] ) && ( $record['provider'] ?? '' ) === 'wikidata' ) {
			$harvest = $this->client->harvestWork( $record['wikidataId'] );
			if ( $harvest->records !== [] ) {
				$record = array_merge( $record, (array)$harvest->records[0] );
			}
		}
		$specs = $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
		return $this->createOrSkipItem( (string)$record['title'], $classItemId, $specs, $record );
	}

	protected function classOptions(): array {
		$options = [];
		foreach ( $this->config->sourceClasses() as $key => $id ) {
			$options[$key] = $id;
		}
		return $options;
	}

	protected function defaultClassItemId( array $record ): ?string {
		// 1. Harvest class hints (Wikidata instance-of → local class).
		foreach ( $record['classWikidataIds'] ?? [] as $qid ) {
			$key = $this->config->sourceClassByWikidata()[$qid] ?? null;
			if ( $key !== null && isset( $this->config->sourceClasses()[$key] ) ) {
				return $this->config->sourceClasses()[$key];
			}
		}
		// 2. Provider defaults.
		$sourceClasses = $this->config->sourceClasses();
		switch ( $record['provider'] ?? '' ) {
			case 'crossref':
			case 'openalex':
				return $sourceClasses['scholarlyArticle'] ?? null;
			case 'openlibrary':
				return $sourceClasses['book'] ?? null;
		}
		// 3. First configured source class.
		return $sourceClasses === [] ? null : reset( $sourceClasses );
	}
}
