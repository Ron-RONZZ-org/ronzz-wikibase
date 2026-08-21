<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;

/**
 * Special:AddSource — create a work item (book / scholarly article / website /
 * song / film / video) from an external authority (DOI / ISBN / title lookup,
 * or author-filtered search with a Wikidata-entity / free-text toggle),
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
			'author' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-author',
				'required' => false,
				'maxlength' => 250,
				'help-message' => 'embeddablecontent-extsearch-author-help',
			],
			'authorMode' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-extsearch-author-mode',
				'options-messages' => [
					'embeddablecontent-extsearch-author-mode-text' => 'text',
					'embeddablecontent-extsearch-author-mode-entity' => 'entity',
				],
				'default' => 'text',
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
		$author = trim( (string)( $data['author'] ?? '' ) );
		if ( $author !== '' ) {
			// Author-filtered search: entity mode resolves the author(s) by
			// Wikidata Q-ids on the hub, text mode by free-text name across
			// the cascade. Either narrows the results for common titles.
			if ( ( $data['authorMode'] ?? 'text' ) === 'entity' ) {
				$qids = array_values( array_filter(
					ItemIdList::split( $author ),
					static fn ( string $id ): bool => preg_match( '/^Q[1-9]\d*$/i', $id ) === 1
				) );
				if ( $qids === [] ) {
					return new ProviderResult( [], [ 'Entity-mode author search needs Wikidata Q-ids (e.g. Q42, Q179)' ] );
				}
				return $this->client->searchWorksByAuthorEntities( $qids, $title );
			}
			return $this->client->searchWorksByAuthorName( $author, $title );
		}
		if ( $title === '' ) {
			return new ProviderResult( [], [ 'No DOI, ISBN, title or author given' ] );
		}
		return $this->client->searchWorks( $title );
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		return (string)( $record['title'] ?? '' );
	}

	/** @return array<string,string> authority identifiers relevant to works */
	protected function externalIdRecordMap(): array {
		return [
			'wikidata' => 'wikidataId',
			'doi' => 'doi',
			'isbn' => 'isbn',
			'openalex' => 'openalexId',
			'pubmed' => 'pubmedId',
		];
	}

	protected function enrichRecord( array $record ): array {
		if ( !empty( $record['harvested'] ) ) {
			return $record;
		}
		// Harvest on pick: enrich with the full Wikidata work record
		// (container, publisher, pages, volume, issue, DOI, ISBN, …).
		if ( !empty( $record['wikidataId'] ) && ( $record['provider'] ?? '' ) === 'wikidata' ) {
			$harvest = $this->client->harvestWork( $record['wikidataId'] );
			if ( $harvest->records !== [] ) {
				$record = array_merge( $record, (array)$harvest->records[0] );
			}
		}
		$record['harvested'] = true;
		return $record;
	}

	protected function reviewFieldSpecs( array $record ): array {
		return $this->labelFieldSpec( 'title', 'embeddablecontent-extsearch-title', (string)( $record['title'] ?? '' ) )
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				'containerTitle' => $this->plainTextField( 'embeddablecontent-field-publishedin', (string)( $record['containerTitle'] ?? '' ) ),
				'publisher' => $this->plainTextField( 'embeddablecontent-field-publisher', (string)( $record['publisher'] ?? '' ) ),
				'volume' => $this->plainTextField( 'embeddablecontent-field-volume', (string)( $record['volume'] ?? '' ) ),
				'issue' => $this->plainTextField( 'embeddablecontent-field-issue', (string)( $record['issue'] ?? '' ) ),
				'pages' => $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) ),
				'issuedYear' => $this->plainTextField(
					'embeddablecontent-field-year',
					(string)( $record['issuedYear'] ?? '' ),
					4
				),
			]
			+ $this->externalIdFieldSpecs( $record );
	}

	protected function createFromRecord( array $record, string $classItemId ): string {
		$record = $this->enrichRecord( $record );
		$specs = $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
		return $this->createOrSkipItem( $this->primaryLabel( $record ), $classItemId, $specs, $record );
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
