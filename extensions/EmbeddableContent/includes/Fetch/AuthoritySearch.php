<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SourceFieldMap;
use EmbeddableContent\Search\EntityTermSearch;

/**
 * Read-only authority search for API/MCP clients: the same provider cascade
 * the browser Add* forms use, plus an optional local-resolution pass that
 * suggests the existing publisher/journal/author items (the form's
 * autofill-confirm behaviour).
 *
 * This class is the machine-facing orchestration; the providers in this
 * directory remain the single source of truth for the external lookups.
 * It never writes to the wiki.
 *
 * @license GPL-2.0-or-later
 */
class AuthoritySearch {

	/**
	 * Classes the browser AddSource picker sends straight to the manual form
	 * because no usable external authority covers them (mirrors
	 * SpecialAddSource::MANUAL_ONLY_CLASSES, in API class-key spelling).
	 */
	private const MANUAL_ONLY_CLASSES = [
		'website', 'webpage', 'book-excerpt', 'document', 'manuscript', 'patent',
		'legal-case', 'legislation', 'bill', 'treaty', 'interview', 'map',
		'presentation', 'dataset', 'transcript', 'text', 'law',
	];

	private LocalAuthorityResolver $resolver;

	public function __construct(
		private ProviderClient $client,
		private PageMetadataFetcher $pageMetadata,
		EntityTermSearch $entitySearch,
		private EmbeddableContentConfig $config,
		?callable $entityLookup = null
	) {
		$this->resolver = new LocalAuthorityResolver( $entitySearch, $config, $entityLookup );
	}

	/**
	 * @param array<string,mixed> $params the API parameters (see ApiAuthoritySearch)
	 * @return array{records: array<int,array<string,mixed>>, warnings: string[]}
	 */
	public function search( string $kind, string $mode, array $params ): array {
		$result = $this->dispatch( $kind, $mode, $params );
		$language = (string)( $params['language'] ?? 'en' );
		$class = trim( (string)( $params['class'] ?? '' ) );
		$resolveLocal = !empty( $params['resolveLocal'] );

		$records = [];
		foreach ( $result->records as $record ) {
			$local = $resolveLocal ? $this->resolver->resolve( $kind, $record, $language ) : null;
			$entry = [
				'kind' => $kind,
				'provider' => $record->provider ?? null,
				'providerId' => $record->providerId ?? null,
				'record' => array_filter(
					$this->recordToArray( $record ),
					static fn ( $value ): bool => $value !== null
				),
				'suggestedAddFields' => $this->suggestedFields( $kind, $record, $class, $local ),
			];
			if ( $local !== null ) {
				$entry['local'] = $local;
			}
			$records[] = $entry;
		}

		return [ 'records' => $records, 'warnings' => $result->warnings ];
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function dispatch( string $kind, string $mode, array $params ): ProviderResult {
		return match ( $kind ) {
			'work' => $this->dispatchWork( $mode, $params ),
			'person' => $this->dispatchPerson( $mode, $params ),
			'entity' => $this->dispatchEntity( $mode, $params ),
			'software' => $this->dispatchSoftware( $mode, $params ),
			default => new ProviderResult( [], [ "unknown kind: {$kind}" ] ),
		};
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function dispatchWork( string $mode, array $params ): ProviderResult {
		$wikidataId = trim( (string)( $params['wikidataId'] ?? '' ) );
		if ( $mode === 'harvest' ) {
			return $wikidataId === ''
				? new ProviderResult( [], [ 'wikidataId is required for mode=harvest' ] )
				: $this->client->harvestWork( $wikidataId );
		}

		$doi = trim( (string)( $params['doi'] ?? '' ) );
		$isbn = trim( (string)( $params['isbn'] ?? '' ) );
		$youtubeUrl = trim( (string)( $params['youtubeUrl'] ?? '' ) );
		$url = trim( (string)( $params['url'] ?? '' ) );
		$query = trim( (string)( $params['query'] ?? '' ) );
		$author = trim( (string)( $params['author'] ?? '' ) );
		$classKey = trim( (string)( $params['class'] ?? '' ) );

		if ( $mode === 'lookup' && $doi === '' && $isbn === '' && $youtubeUrl === '' && $url === '' ) {
			return new ProviderResult( [], [ 'mode=lookup needs doi, isbn, youtubeUrl or url' ] );
		}
		if ( $doi !== '' ) {
			return $this->client->byDoi( $doi );
		}
		if ( $isbn !== '' ) {
			return $this->client->byIsbn( $isbn );
		}
		if ( $youtubeUrl !== '' ) {
			return $this->client->byYouTubeUrl( $youtubeUrl );
		}
		if ( $url !== '' && in_array( $classKey, [ 'website', 'webpage' ], true ) ) {
			if ( ( $params['fetchPage'] ?? true ) ) {
				return $this->pageResult( $url );
			}
			return new ProviderResult( [ new WorkRecord(
				title: $url,
				url: $url,
				provider: 'url',
				providerId: $url
			) ] );
		}

		if ( in_array( $classKey, self::MANUAL_ONLY_CLASSES, true ) ) {
			return new ProviderResult( [], [ "{$classKey} has no external authority; use the manual Add* fields" ] );
		}

		$classQids = $this->classSearchClasses( $classKey );
		if ( $classKey === 'youtube-channel' ) {
			return $query === ''
				? new ProviderResult( [], [ 'query is required for a YouTube channel search' ] )
				: $this->client->searchYouTubeChannels( $query );
		}
		if ( $classKey === 'youtube-video' ) {
			return $query === ''
				? new ProviderResult( [], [ 'query is required for a YouTube video search' ] )
				: $this->client->searchYouTubeVideos( $query );
		}
		if ( $author !== '' ) {
			if ( ( $params['authorMode'] ?? 'text' ) === 'entity' ) {
				$qids = $this->splitItemIds( $author );
				return $qids === []
					? new ProviderResult( [], [ 'entity-mode author search needs Wikidata Q-ids (e.g. Q42)' ] )
					: $this->client->searchWorksByAuthorEntities( $qids, $query, $classQids );
			}
			return $this->client->searchWorksByAuthorName( $author, $query, $classQids );
		}
		return $query === ''
			? new ProviderResult( [], [ 'query, doi, isbn, youtubeUrl or url is required' ] )
			: $this->client->searchWorks( $query, $classQids );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function dispatchPerson( string $mode, array $params ): ProviderResult {
		$wikidataId = trim( (string)( $params['wikidataId'] ?? '' ) );
		if ( $mode === 'harvest' ) {
			return $wikidataId === ''
				? new ProviderResult( [], [ 'wikidataId is required for mode=harvest' ] )
				: $this->client->harvestPerson( $wikidataId );
		}
		$orcid = trim( (string)( $params['orcid'] ?? '' ) );
		$viaf = trim( (string)( $params['viaf'] ?? '' ) );
		$isni = trim( (string)( $params['isni'] ?? '' ) );
		if ( $orcid !== '' ) {
			return $this->client->byOrcid( $orcid );
		}
		if ( $viaf !== '' ) {
			return $this->client->byViaf( $viaf );
		}
		if ( $isni !== '' ) {
			return $this->client->byIsni( $isni );
		}
		if ( $mode === 'lookup' ) {
			return new ProviderResult( [], [ 'mode=lookup needs orcid, viaf or isni' ] );
		}
		$query = trim( (string)( $params['query'] ?? '' ) );
		return $query === ''
			? new ProviderResult( [], [ 'query is required for a person search' ] )
			: $this->client->searchPersons( $query );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function dispatchEntity( string $mode, array $params ): ProviderResult {
		$wikidataId = trim( (string)( $params['wikidataId'] ?? '' ) );
		if ( $mode === 'harvest' ) {
			return $wikidataId === ''
				? new ProviderResult( [], [ 'wikidataId is required for mode=harvest' ] )
				: $this->client->harvestEntity( $wikidataId );
		}
		$query = trim( (string)( $params['query'] ?? '' ) );
		return $query === ''
			? new ProviderResult( [], [ 'query is required for an entity search' ] )
			: $this->client->searchEntities( $query );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function dispatchSoftware( string $mode, array $params ): ProviderResult {
		$wikidataId = trim( (string)( $params['wikidataId'] ?? '' ) );
		if ( $mode === 'harvest' ) {
			return $wikidataId === ''
				? new ProviderResult( [], [ 'wikidataId is required for mode=harvest' ] )
				: $this->client->harvestSoftware( $wikidataId );
		}
		$query = trim( (string)( $params['query'] ?? '' ) );
		return $query === ''
			? new ProviderResult( [], [ 'query is required for a software search' ] )
			: $this->client->searchSoftware( $query );
	}

	private function pageResult( string $url ): ProviderResult {
		$metadata = $this->pageMetadata->fetch( $url );
		if ( $metadata === null || $metadata->isEmpty() ) {
			return new ProviderResult( [], [ "page metadata could not be fetched for {$url}" ] );
		}
		return new ProviderResult( [ new WorkRecord(
			title: $metadata->title !== '' ? $metadata->title : $url,
			description: $metadata->description !== '' ? $metadata->description : null,
			url: $url,
			abstract: $metadata->intro !== '' ? $metadata->intro : null,
			keywords: $metadata->keywords !== '' ? $metadata->keywords : null,
			provider: 'page',
			providerId: $url
		) ] );
	}

	/**
	 * The class-specific Wikidata scope (the same map the browser form uses).
	 *
	 * @return string[]
	 */
	private function classSearchClasses( string $classKey ): array {
		if ( $classKey === '' ) {
			return [];
		}
		$formKey = SourceFieldMap::formKey( $classKey );
		return $this->config->sourceClassSearchClasses()[$formKey] ?? [];
	}

	/**
	 * @param object $record WorkRecord|PersonRecord|EntityRecord|SoftwareRecord
	 * @param array<string,mixed>|null $local
	 * @return array<string,string>
	 */
	private function suggestedFields( string $kind, object $record, string $class, ?array $local ): array {
		$fields = match ( $kind ) {
			'work' => [
				'classKey' => $class,
				'title' => $record->title,
				'description' => $record->description,
				'year' => $record->issuedYear !== null ? (string)$record->issuedYear : null,
				'doi' => $record->doi,
				'isbn' => $record->isbn,
				'openalexWorkId' => $record->openalexId,
				'pubmedId' => $record->pubmedId,
				'wikidataId' => $record->wikidataId,
				'volume' => $record->volume,
				'issue' => $record->issue,
				'pages' => $record->pages,
				'url' => $record->url,
				'youtubeChannelId' => $record->youtubeChannelId,
				'youtubeVideoId' => $record->youtubeVideoId,
			],
			'person' => [
				'givenName' => $record->givenName,
				'familyName' => $record->familyName,
				'description' => $record->description,
				'dateOfBirth' => $record->dateOfBirth,
				'dateOfDeath' => $record->dateOfDeath,
				'orcid' => $record->orcid,
				'viafId' => $record->viafId,
				'isni' => $record->isni,
				'wikidataId' => $record->wikidataId,
				'openalexAuthorId' => $record->openalexId,
			],
			'entity' => [
				'label' => $record->label,
				'description' => $record->description,
				'wikidataId' => $record->wikidataId,
			],
			'software' => [
				'label' => $record->label,
				'description' => $record->description,
				'wikidataId' => $record->wikidataId,
				'officialWebsite' => $record->website,
				'sourceCodeRepository' => $record->sourceRepository,
			],
			default => [],
		};
		$fields = array_filter( $fields, static fn ( $value ): bool => $value !== null && $value !== '' );

		if ( is_array( $local ) ) {
			if ( ( $local['publisher']['qid'] ?? null ) !== null ) {
				$fields['publisher'] = $local['publisher']['qid'];
			}
			if ( ( $local['journal']['qid'] ?? null ) !== null ) {
				$fields['journal'] = $local['journal']['qid'];
			}
			$authorQids = [];
			foreach ( $local['authors'] ?? [] as $author ) {
				if ( ( $author['qid'] ?? null ) !== null ) {
					$authorQids[] = $author['qid'];
				}
			}
			if ( $authorQids !== [] ) {
				$fields['authors'] = implode( ', ', $authorQids );
			}
		}

		return $fields;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function recordToArray( object $record ): array {
		$vars = get_object_vars( $record );
		if ( isset( $vars['authors'] ) && is_array( $vars['authors'] ) ) {
			$vars['authors'] = array_map(
				static fn ( object $author ): array => get_object_vars( $author ),
				$vars['authors']
			);
		}
		return $vars;
	}

	/**
	 * @return string[]
	 */
	private function splitItemIds( string $value ): array {
		$ids = [];
		foreach ( preg_split( '/[\s,;|]+/', $value ) ?: [] as $id ) {
			$id = strtoupper( trim( $id ) );
			if ( preg_match( '/^Q[1-9]\d*$/', $id ) === 1 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
