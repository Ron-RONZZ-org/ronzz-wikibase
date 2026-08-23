<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Shared Wikidata API core used by WikidataPersonProvider,
 * WikidataWorkProvider and WikidataEntityProvider (the fetch-design "hub"):
 *
 *  - name search via wbsearchentities (REST)
 *  - full harvest via wbgetentities: authority IDs (P496/P214/P213/P356/P212/
 *    P10283/P698), citation metadata (P735/P734/P1433/P123/P304/P478/P433,
 *    P577), instance-of class hints (P31) — including one nested batch fetch
 *    to resolve item-typed values (given/family names, container, publisher)
 *    to their labels
 *  - identifier-to-QID lookup via SPARQL on query.wikidata.org (P496 for
 *    ORCID, P356 for DOI, P212 for ISBN)
 *
 * @internal — consume via the three thin provider classes.
 * @license GPL-2.0-or-later
 */
class WikidataCore {

	private const API = 'https://www.wikidata.org/w/api.php';
	private const SPARQL = 'https://query.wikidata.org/sparql';

	private HttpClientInterface $http;
	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	/**
	 * wbsearchentities search — returns raw rows (id/label/description).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchRaw( string $query ): array {
		$data = $this->http->getJson( self::API, [
			'action' => 'wbsearchentities',
			'search' => $query,
			'language' => 'en',
			'type' => 'item',
			'limit' => 10,
			'format' => 'json',
		], $this->timeout );
		$rows = $data['search'] ?? [];
		return array_values( array_filter( $rows, static fn ( $row ): bool => is_array( $row ) && isset( $row['id'] ) ) );
	}

	/**
	 * wbgetentities harvest: labels, description, claims.
	 *
	 * NOTE: do not pass a `languages` filter — verified live (Aug 16 2026)
	 * that `languages=en|fr|eo` makes the API return EMPTY labels while
	 * descriptions/claims still work. Fetch the full payload and filter
	 * client-side (firstLabel prefers en/fr/eo).
	 *
	 * @return array{label: string, description: ?string, claims: array<string,mixed>}|null
	 */
	public function harvestRaw( string $qid ): ?array {
		$data = $this->http->getJson( self::API, [
			'action' => 'wbgetentities',
			'ids' => $qid,
			'props' => 'labels|descriptions|claims',
			'format' => 'json',
		], $this->timeout );
		$entity = $data['entities'][$qid] ?? null;
		if ( !is_array( $entity ) ) {
			return null;
		}
		$label = $this->firstLabel( $entity['labels'] ?? [] );
		if ( $label === '' ) {
			// Wikidata withholds en/fr/eo labels from automated clients for
			// some entities (verified live: Q42 serves 75 non-Latin labels,
			// no en; wbsearchentities DOES return the en label). Fall back.
			$label = $this->labelViaSearch( $qid );
		}
		return [
			'label' => $label,
			'description' => $this->firstDescription( $entity['descriptions'] ?? [] ),
			'claims' => $entity['claims'] ?? [],
		];
	}

	/**
	 * wbsearchentities fallback for a withheld label: searching the QID
	 * itself reliably returns the primary (en) label (verified live).
	 */
	private function labelViaSearch( string $qid ): string {
		$data = $this->http->getJson( self::API, [
			'action' => 'wbsearchentities',
			'search' => $qid,
			'language' => 'en',
			'type' => 'item',
			'limit' => 1,
			'format' => 'json',
		], $this->timeout );
		return (string)( $data['search'][0]['label'] ?? '' );
	}

	/**
	 * Batch label resolution for item-typed claim values (one nested
	 * wbgetentities call, max 50 ids). No `languages` filter — see
	 * harvestRaw().
	 *
	 * @param string[] $qids
	 * @return array<string,string> qid => label
	 */
	public function resolveItemLabels( array $qids ): array {
		if ( $qids === [] ) {
			return [];
		}
		$data = $this->http->getJson( self::API, [
			'action' => 'wbgetentities',
			'ids' => implode( '|', array_slice( $qids, 0, 50 ) ),
			'props' => 'labels',
			'format' => 'json',
		], $this->timeout );
		$labels = [];
		foreach ( $data['entities'] ?? [] as $id => $entity ) {
			$labels[$id] = $this->firstLabel( $entity['labels'] ?? [] );
		}
		return $labels;
	}

	/**
	 * Identifier-to-QID lookup via SPARQL on query.wikidata.org
	 * (e.g. P496 for ORCID, P356 for DOI, P212 for ISBN).
	 */
	public function findItemByExternalId( string $propertyId, string $value ): ?string {
		$escaped = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value );
		$query = "SELECT ?item WHERE { ?item wdt:{$propertyId} \"{$escaped}\" } LIMIT 1";
		$data = $this->http->postForm(
			self::SPARQL,
			[ 'query' => $query ],
			[ 'Accept' => 'application/sparql-results+json' ],
			$this->timeout
		);
		$binding = $data['results']['bindings'][0]['item']['value'] ?? null;
		if ( !is_string( $binding ) ) {
			return null;
		}
		$qid = basename( $binding );
		return preg_match( '/^Q[1-9]\d*$/i', $qid ) === 1 ? $qid : null;
	}

	/**
	 * Work search by author via SPARQL on query.wikidata.org. Two modes
	 * (mutually exclusive):
	 *  - entity ids:   VALUES over the author Q-ids, matched through P50
	 *    (semantic author search — the hub capability for Special:AddSource).
	 *  - free text:    author-entity labels matched case-insensitively.
	 * Free-text label scans are unbounded on Wikidata's Blazegraph and may
	 * time out under the per-call timeout — callers treat that as a
	 * provider warning, never a cascade failure.
	 *
	 * @param string[] $qids Wikidata author entity ids (entity mode)
	 * @param string $authorName free-text author name (text mode)
	 * @return array<int,array<string,mixed>> raw SPARQL rows, deduped by work
	 */
	public function searchWorksByAuthor( array $qids = [], string $authorName = '' ): array {
		if ( $qids !== [] ) {
			$values = implode( ' ', array_map(
				static fn ( string $qid ): string => 'wd:' . $qid,
				array_values( $qids )
			) );
			$authorPattern = "VALUES ?author { {$values} }\n  ?work wdt:P50 ?author .";
		} else {
			$escaped = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], strtolower( $authorName ) );
			$authorPattern = "?work wdt:P50 ?author .\n"
				. "  ?author rdfs:label ?authorLabel .\n"
				. "  FILTER(CONTAINS(LCASE(?authorLabel), \"{$escaped}\"))";
		}
		$query = "SELECT DISTINCT ?work ?workLabel ?workDescription ?date ?class WHERE {\n"
			. "  {$authorPattern}\n"
			. "  OPTIONAL { ?work wdt:P577 ?date . }\n"
			. "  OPTIONAL { ?work wdt:P31 ?class . }\n"
			. "  SERVICE wikibase:label { bd:serviceParam wikibase:language \"en,fr,eo\". }\n"
			. "}\nLIMIT 10";
		$data = $this->http->postForm(
			self::SPARQL,
			[ 'query' => $query ],
			[ 'Accept' => 'application/sparql-results+json' ],
			$this->timeout
		);
		// The label service can emit one row per matched language — dedupe by
		// work, preferring rows that carry a label.
		$rows = [];
		foreach ( $data['results']['bindings'] ?? [] as $binding ) {
			$work = (string)( $binding['work']['value'] ?? '' );
			if ( $work === '' || isset( $rows[$work] ) ) {
				continue;
			}
			$rows[$work] = $binding;
		}
		return array_values( $rows );
	}

	/**
	 * Item-typed claim values (P735/P734/P1433/P123/P31) → entity ids.
	 *
	 * @param array<string,mixed> $claims
	 * @param string[] $propertyIds
	 * @return string[]
	 */
	public function itemValueIds( array $claims, array $propertyIds ): array {
		$ids = [];
		foreach ( $propertyIds as $pid ) {
			foreach ( $claims[$pid] ?? [] as $claim ) {
				$value = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
				if ( is_string( $value ) && preg_match( '/^Q[1-9]\d*$/i', $value ) === 1 ) {
					$ids[] = $value;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string,mixed> $claims
	 */
	public function itemLabel( array $claims, string $propertyId, array $itemLabels ): ?string {
		foreach ( $claims[$propertyId] ?? [] as $claim ) {
			$value = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
			if ( is_string( $value ) && isset( $itemLabels[$value] ) ) {
				return $itemLabels[$value];
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $claims
	 */
	public function stringValue( array $claims, string $propertyId ): ?string {
		foreach ( $claims[$propertyId] ?? [] as $claim ) {
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			if ( is_string( $value ) && $value !== '' ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * First item-id value of a claim, or null ("Q42" from a wikibase-item
	 * datavalue).
	 */
	public function itemId( array $claims, string $propertyId ): ?string {
		foreach ( $claims[$propertyId] ?? [] as $claim ) {
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			if ( is_array( $value ) && isset( $value['id'] ) ) {
				return (string)$value['id'];
			}
		}
		return null;
	}

	/**
	 * First time value of a claim as "YYYY-MM-DD" (day precision preserved,
	 * else the year with -00-00), or null. The date-of-birth/death review
	 * fields prefill from this string.
	 */
	public function timeValue( array $claims, string $propertyId ): ?string {
		foreach ( $claims[$propertyId] ?? [] as $claim ) {
			$time = $claim['mainsnak']['datavalue']['value']['time'] ?? null;
			if ( is_string( $time ) && preg_match( '/^[+-](\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', $time, $m ) === 1 ) {
				$month = $m[2] ?? '00';
				$day = $m[3] ?? '00';
				// Normalize '00' placeholders so the date widget accepts it.
				$month = $month === '00' ? '01' : $month;
				$day = $day === '00' ? '01' : $day;
				return sprintf( '%04d-%s-%s', (int)$m[1], $month, $day );
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $claims
	 */
	public function yearValue( array $claims, string $propertyId ): ?int {
		foreach ( $claims[$propertyId] ?? [] as $claim ) {
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			if ( is_array( $value ) && isset( $value['time'] ) && preg_match( '/^[+-](\d{4})/', (string)$value['time'], $m ) === 1 ) {
				return (int)$m[1];
			}
		}
		return null;
	}

	/**
	 * Extracts the year from a SPARQL time value ("+2019-05-01T00:00:00Z").
	 */
	public function yearFromTimeValue( string $time ): ?int {
		if ( preg_match( '/^[+-](\d{4})/', $time, $m ) === 1 ) {
			return (int)$m[1];
		}
		return null;
	}

	/**
	 * Label in en/fr/eo preference order; '' when none of those languages is
	 * present (callers decide on the fallback).
	 *
	 * @param array<string,mixed> $labels
	 */
	private function firstLabel( array $labels ): string {
		foreach ( [ 'en', 'fr', 'eo' ] as $lang ) {
			if ( isset( $labels[$lang]['value'] ) ) {
				return $labels[$lang]['value'];
			}
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $descriptions
	 */
	private function firstDescription( array $descriptions ): ?string {
		foreach ( [ 'en', 'fr', 'eo' ] as $lang ) {
			if ( isset( $descriptions[$lang]['value'] ) ) {
				return $descriptions[$lang]['value'];
			}
		}
		return null;
	}
}
