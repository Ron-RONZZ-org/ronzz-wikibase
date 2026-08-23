<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

use Wikimedia\ObjectCache\BagOStuff;

/**
 * Cascade orchestrator for the fetch design (#7 §Fetch).
 *
 * Provider lists follow the #7 cascade table, split by operation because a
 * single order cannot serve both name/title search and identifier lookups:
 *
 *  - person name search:  Wikidata → OpenAlex → dblp → ORCID
 *  - byOrcid:             ORCID → Wikidata (SPARQL) → OpenAlex   (direct first)
 *  - work title search:   Wikidata → Open Library → OpenAlex → Crossref
 *  - byDoi:               Crossref → OpenAlex → Wikidata (SPARQL) (direct first)
 *  - byIsbn:              Open Library → Crossref → Wikidata (SPARQL)
 *  - entity name search:  Wikidata (only)
 *  - software name search: Wikidata → GitHub (issue #26)
 *  - YouTube (channels/videos): YouTube Data API v3 (single provider)
 *
 * Identifier paths stop at the first hit; searches collect from all
 * providers, dedupe by authority ID (wikidataId > orcid/doi/isbn >
 * normalized label), and cap results. Every per-provider failure is caught
 * and surfaced as a warning on ProviderResult — never silently swallowed,
 * never fatal to the cascade. The Wikidata-hub harvest (wbgetentities pick
 * step) is exposed via harvestPerson()/harvestWork()/harvestEntity()/
 * harvestSoftware().
 *
 * @license GPL-2.0-or-later
 */
class ProviderClient {

	private const RESULT_CAP = 10;

	/** @var PersonProvider[] */
	private array $personNameProviders;

	/** @var PersonProvider[] */
	private array $personIdProviders;

	/** @var WorkProvider[] */
	private array $workTitleProviders;

	/** @var WorkProvider[] */
	private array $doiProviders;

	/** @var WorkProvider[] */
	private array $isbnProviders;

	/** @var EntityProvider[] */
	private array $entityProviders;

	/** @var SoftwareProvider[] */
	private array $softwareNameProviders;

	private ?WikidataPersonProvider $wikidataPerson;
	private ?WikidataWorkProvider $wikidataWork;
	private ?WikidataEntityProvider $wikidataEntity;
	private ?WikidataSoftwareProvider $wikidataSoftware;
	private ?YouTubeProvider $youtube;

	/**
	 * @param PersonProvider[] $personNameProviders ordered name-search cascade
	 * @param PersonProvider[] $personIdProviders ordered byOrcid cascade
	 * @param WorkProvider[] $workTitleProviders ordered title-search cascade
	 * @param WorkProvider[] $doiProviders ordered byDoi cascade
	 * @param WorkProvider[] $isbnProviders ordered byIsbn cascade
	 * @param EntityProvider[] $entityProviders ordered name-search cascade
	 * @param SoftwareProvider[] $softwareNameProviders ordered software name-search cascade
	 */
	public function __construct(
		array $personNameProviders = [],
		array $personIdProviders = [],
		array $workTitleProviders = [],
		array $doiProviders = [],
		array $isbnProviders = [],
		array $entityProviders = [],
		?WikidataPersonProvider $wikidataPerson = null,
		?WikidataWorkProvider $wikidataWork = null,
		?WikidataEntityProvider $wikidataEntity = null,
		?WikidataSoftwareProvider $wikidataSoftware = null,
		array $softwareNameProviders = [],
		?YouTubeProvider $youtube = null
	) {
		$this->personNameProviders = $personNameProviders;
		$this->personIdProviders = $personIdProviders;
		$this->workTitleProviders = $workTitleProviders;
		$this->doiProviders = $doiProviders;
		$this->isbnProviders = $isbnProviders;
		$this->entityProviders = $entityProviders;
		$this->softwareNameProviders = $softwareNameProviders;
		$this->wikidataPerson = $wikidataPerson;
		$this->wikidataWork = $wikidataWork;
		$this->wikidataEntity = $wikidataEntity;
		$this->wikidataSoftware = $wikidataSoftware;
		$this->youtube = $youtube;
	}

	/**
	 * Canonical cascade wiring per #7's fetch table.
	 *
	 * @param string $youtubeApiKey deploy-injected YouTube Data API key ('' = disabled)
	 * @param int $youtubeSearchCap name-search result cap (UX choice — the API bills
	 *  per call, not per result)
	 * @param int $youtubeCacheTtl seconds to memoize name searches (0 = off)
	 */
	public static function default(
		HttpClientInterface $http,
		float $timeout = 10.0,
		string $youtubeApiKey = '',
		int $youtubeSearchCap = 10,
		?BagOStuff $youtubeCache = null,
		int $youtubeCacheTtl = 0
	): self {
		$wikidataPerson = new WikidataPersonProvider( $http, $timeout );
		$wikidataWork = new WikidataWorkProvider( $http, $timeout );
		$wikidataEntity = new WikidataEntityProvider( $http, $timeout );
		$wikidataSoftware = new WikidataSoftwareProvider( $http, $timeout );
		$openalex = new OpenAlexProvider( $http, $timeout );
		$crossref = new CrossrefProvider( $http, $timeout );
		$openlibrary = new OpenLibraryProvider( $http, $timeout );
		$orcid = new OrcidProvider( $http, $timeout );

		return new self(
			[ $wikidataPerson, $openalex, new DblpPersonProvider( $http, $timeout ), $orcid ],
			[ $orcid, $wikidataPerson, $openalex ],
			[ $wikidataWork, $openlibrary, $openalex, $crossref ],
			[ $crossref, $openalex, $wikidataWork ],
			[ $openlibrary, $crossref, $wikidataWork ],
			[ $wikidataEntity ],
			$wikidataPerson,
			$wikidataWork,
			$wikidataEntity,
			$wikidataSoftware,
			[ $wikidataSoftware, new GitHubSoftwareProvider( $http, $timeout ) ],
			$youtubeApiKey === '' ? null : new YouTubeProvider(
				$http, $youtubeApiKey, $timeout, $youtubeSearchCap, $youtubeCache, $youtubeCacheTtl
			)
		);
	}

	public function searchPersons( string $name ): ProviderResult {
		return $this->searchCascade(
			$this->personNameProviders,
			static fn ( PersonProvider $p, string $q ) => $p->searchByName( $q ),
			$name,
			fn ( array $records ) => $this->dedupePersons( $records )
		);
	}

	public function searchWorks( string $title ): ProviderResult {
		return $this->searchCascade(
			$this->workTitleProviders,
			static fn ( WorkProvider $p, string $q ) => $p->searchByTitle( $q ),
			$title,
			fn ( array $records ) => $this->dedupeWorks( $records )
		);
	}

	/**
	 * Work search by free-text author name (narrowed by an optional title):
	 * Wikidata → Open Library → OpenAlex → Crossref. The author filter
	 * narrows down common-title searches.
	 */
	public function searchWorksByAuthorName( string $author, string $title = '' ): ProviderResult {
		return $this->searchCascade(
			$this->workTitleProviders,
			static fn ( WorkProvider $p, string $q ) => $p->searchByAuthorName( $q, $title ),
			$author,
			fn ( array $records ) => $this->dedupeWorks( $records )
		);
	}

	/**
	 * Work search by Wikidata author ENTITY ids (semantic search; narrowed by
	 * an optional title). Only the Wikidata hub filters by QID — the other
	 * work providers return [] for this operation.
	 *
	 * @param string[] $qids Wikidata author entity ids
	 */
	public function searchWorksByAuthorEntities( array $qids, string $title = '' ): ProviderResult {
		return $this->searchCascade(
			$this->workTitleProviders,
			static fn ( WorkProvider $p, string $q ) => $p->searchByAuthorEntities( $qids, $title ),
			implode( '|', $qids ),
			fn ( array $records ) => $this->dedupeWorks( $records )
		);
	}

	public function searchEntities( string $name ): ProviderResult {
		return $this->searchCascade(
			$this->entityProviders,
			static fn ( EntityProvider $p, string $q ) => $p->searchByName( $q ),
			$name,
			fn ( array $records ) => $this->dedupeEntities( $records )
		);
	}

	/**
	 * Issue #26: software name search — Wikidata → GitHub.
	 */
	public function searchSoftware( string $name ): ProviderResult {
		return $this->searchCascade(
			$this->softwareNameProviders,
			static fn ( SoftwareProvider $p, string $q ) => $p->searchByName( $q ),
			$name,
			fn ( array $records ) => $this->dedupeSoftware( $records )
		);
	}

	/**
	 * YouTube channel name search (YouTube Data API v3, capped).
	 */
	public function searchYouTubeChannels( string $name ): ProviderResult {
		return $this->youtubeResult( fn () => $this->youtube->searchChannels( $name ), 'youtube' );
	}

	/**
	 * YouTube video name search (YouTube Data API v3, capped).
	 */
	public function searchYouTubeVideos( string $name ): ProviderResult {
		return $this->youtubeResult( fn () => $this->youtube->searchVideos( $name ), 'youtube' );
	}

	/**
	 * Exact YouTube URL resolution (channel or video). An empty result set
	 * means "no match for the provided URL" — the caller localizes it.
	 */
	public function byYouTubeUrl( string $url ): ProviderResult {
		return $this->youtubeResult(
			static fn ( YouTubeProvider $yt ) => ( $record = $yt->byUrl( $url ) ) !== null ? [ $record ] : [],
			'youtube'
		);
	}

	/**
	 * @param callable(YouTubeProvider):array $fetch
	 * @return ProviderResult
	 */
	private function youtubeResult( callable $fetch, string $label ): ProviderResult {
		if ( $this->youtube === null ) {
			return new ProviderResult( [], [ $label . ': not configured (missing YouTube API key)' ] );
		}
		try {
			$records = $fetch( $this->youtube );
		} catch ( ProviderException $e ) {
			return new ProviderResult( [], [ $label . ': ' . $e->getMessage() ] );
		}
		return new ProviderResult( array_slice( array_values( $records ), 0, self::RESULT_CAP ) );
	}

	public function harvestSoftware( string $qid ): ProviderResult {
		if ( $this->wikidataSoftware === null ) {
			return new ProviderResult( [], [ 'No Wikidata software hub configured' ] );
		}
		try {
			$record = $this->wikidataSoftware->byWikidataId( $qid );
		} catch ( ProviderException $e ) {
			return new ProviderResult( [], [ 'wikidata: ' . $e->getMessage() ] );
		}
		return new ProviderResult( $record === null ? [] : [ $record ] );
	}

	public function byOrcid( string $orcid ): ProviderResult {
		return $this->identifierCascade(
			$this->personIdProviders,
			static fn ( PersonProvider $p, string $q ) => $p->byOrcid( $q ),
			$orcid
		);
	}

	/**
	 * VIAF lookup — Wikidata-hub only (no other provider resolves VIAF).
	 */
	public function byViaf( string $viaf ): ProviderResult {
		return $this->personHubLookup( $viaf, 'viaf' );
	}

	/**
	 * ISNI lookup — Wikidata-hub only (no other provider resolves ISNI).
	 */
	public function byIsni( string $isni ): ProviderResult {
		return $this->personHubLookup( $isni, 'isni' );
	}

	/**
	 * @return ProviderResult single-record result, or a warning when the hub
	 * is not configured or the lookup fails
	 */
	private function personHubLookup( string $id, string $kind ): ProviderResult {
		if ( $this->wikidataPerson === null ) {
			return new ProviderResult( [], [ 'No Wikidata person hub configured' ] );
		}
		try {
			$record = $kind === 'viaf'
				? $this->wikidataPerson->byViaf( $id )
				: $this->wikidataPerson->byIsni( $id );
		} catch ( ProviderException $e ) {
			return new ProviderResult( [], [ 'wikidata: ' . $e->getMessage() ] );
		}
		return new ProviderResult( $record === null ? [] : [ $record ] );
	}

	public function byDoi( string $doi ): ProviderResult {
		return $this->identifierCascade(
			$this->doiProviders,
			static fn ( WorkProvider $p, string $q ) => $p->byDoi( $q ),
			$doi
		);
	}

	public function byIsbn( string $isbn ): ProviderResult {
		return $this->identifierCascade(
			$this->isbnProviders,
			static fn ( WorkProvider $p, string $q ) => $p->byIsbn( $q ),
			$isbn
		);
	}

	public function harvestPerson( string $qid ): ProviderResult {
		if ( $this->wikidataPerson === null ) {
			return new ProviderResult( [], [ 'No Wikidata person hub configured' ] );
		}
		try {
			$record = $this->wikidataPerson->byWikidataId( $qid );
		} catch ( ProviderException $e ) {
			return new ProviderResult( [], [ 'wikidata: ' . $e->getMessage() ] );
		}
		return new ProviderResult( $record === null ? [] : [ $record ] );
	}

	public function harvestWork( string $qid ): ProviderResult {
		if ( $this->wikidataWork === null ) {
			return new ProviderResult( [], [ 'No Wikidata work hub configured' ] );
		}
		try {
			$record = $this->wikidataWork->byWikidataId( $qid );
		} catch ( ProviderException $e ) {
			return new ProviderResult( [], [ 'wikidata: ' . $e->getMessage() ] );
		}
		return new ProviderResult( $record === null ? [] : [ $record ] );
	}

	public function harvestEntity( string $qid ): ProviderResult {
		if ( $this->wikidataEntity === null ) {
			return new ProviderResult( [], [ 'No Wikidata entity hub configured' ] );
		}
		try {
			$record = $this->wikidataEntity->byWikidataId( $qid );
		} catch ( ProviderException $e ) {
			return new ProviderResult( [], [ 'wikidata: ' . $e->getMessage() ] );
		}
		return new ProviderResult( $record === null ? [] : [ $record ] );
	}

	/**
	 * Abstract + keywords for a scholarly article by DOI (the page-content
	 * fetch): OpenAlex first (inverted-index reconstruction + keywords),
	 * Crossref as the direct-text fallback. Never throws; [] when nothing
	 * is available.
	 *
	 * @return array{abstract: ?string, keywords: ?string, source: ?string}
	 */
	public function workAbstractByDoi( string $doi ): array {
		// Reverse the byDoi cascade (crossref → openalex → wikidata) so the
		// best-coverage provider wins: openalex → crossref → wikidata (no-op).
		foreach ( array_reverse( $this->doiProviders ) as $provider ) {
			if ( !$provider instanceof WorkProvider ) {
				continue;
			}
			try {
				$data = $provider->abstractAndKeywordsByDoi( $doi );
			} catch ( ProviderException $e ) {
				continue;
			}
			if ( !empty( $data['abstract'] ) || !empty( $data['keywords'] ) ) {
				return $data;
			}
		}
		return [ 'abstract' => null, 'keywords' => null, 'source' => null ];
	}

	/**
	 * Abstract + keywords by a bare OpenAlex work id ("W2741809807").
	 *
	 * @return array{abstract: ?string, keywords: ?string, source: ?string}
	 */
	public function workAbstractByOpenAlexId( string $openalexId ): array {
		if ( preg_match( '/^W[1-9]\d*$/i', $openalexId ) !== 1 ) {
			return [ 'abstract' => null, 'keywords' => null, 'source' => null ];
		}
		foreach ( $this->doiProviders as $provider ) {
			if ( !$provider instanceof OpenAlexProvider ) {
				continue;
			}
			try {
				return $provider->abstractAndKeywordsById( $openalexId );
			} catch ( ProviderException $e ) {
				return [ 'abstract' => null, 'keywords' => null, 'source' => null ];
			}
		}
		return [ 'abstract' => null, 'keywords' => null, 'source' => null ];
	}

	/**
	 * @param object[] $providers
	 * @param callable(object,string):object[] $call
	 * @param callable(object[]):object[] $dedupe
	 */
	private function searchCascade( array $providers, callable $call, string $query, callable $dedupe ): ProviderResult {
		$records = [];
		$warnings = [];
		foreach ( $providers as $provider ) {
			try {
				foreach ( $call( $provider, $query ) as $record ) {
					$records[] = $record;
				}
			} catch ( ProviderException $e ) {
				$warnings[] = $this->providerName( $provider ) . ': ' . $e->getMessage();
			}
		}
		return new ProviderResult(
			array_slice( $dedupe( $records ), 0, self::RESULT_CAP ),
			$warnings
		);
	}

	/**
	 * @param object[] $providers
	 * @param callable(object,string):?object $call
	 */
	private function identifierCascade( array $providers, callable $call, string $query ): ProviderResult {
		$warnings = [];
		foreach ( $providers as $provider ) {
			try {
				$record = $call( $provider, $query );
				if ( $record !== null ) {
					return new ProviderResult( [ $record ], $warnings );
				}
			} catch ( ProviderException $e ) {
				$warnings[] = $this->providerName( $provider ) . ': ' . $e->getMessage();
			}
		}
		return new ProviderResult( [], $warnings );
	}

	/**
	 * @param PersonRecord[] $records
	 * @return PersonRecord[]
	 */
	private function dedupePersons( array $records ): array {
		$seen = [];
		$out = [];
		foreach ( $records as $record ) {
			$key = $record->wikidataId ?? $record->orcid ?? strtolower( trim( $record->label ) );
			if ( isset( $seen[$key] ) ) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $record;
		}
		return $out;
	}

	/**
	 * @param WorkRecord[] $records
	 * @return WorkRecord[]
	 */
	private function dedupeWorks( array $records ): array {
		$seen = [];
		$out = [];
		foreach ( $records as $record ) {
			$key = $record->wikidataId ?? $record->doi ?? $record->isbn ?? strtolower( trim( $record->title ) );
			if ( isset( $seen[$key] ) ) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $record;
		}
		return $out;
	}

	/**
	 * @param EntityRecord[] $records
	 * @return EntityRecord[]
	 */
	private function dedupeEntities( array $records ): array {
		$seen = [];
		$out = [];
		foreach ( $records as $record ) {
			$key = $record->wikidataId ?? strtolower( trim( $record->label ) );
			if ( isset( $seen[$key] ) ) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $record;
		}
		return $out;
	}

	/**
	 * @param SoftwareRecord[] $records
	 * @return SoftwareRecord[]
	 */
	private function dedupeSoftware( array $records ): array {
		$seen = [];
		$out = [];
		foreach ( $records as $record ) {
			$key = $record->wikidataId
				?? $record->githubFullName
				?? strtolower( trim( $record->label ) );
			if ( isset( $seen[$key] ) ) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $record;
		}
		return $out;
	}

	private function providerName( object $provider ): string {
		$name = get_class( $provider );
		$pos = strrpos( $name, '\\' );
		return $pos === false ? $name : substr( $name, $pos + 1 );
	}
}
