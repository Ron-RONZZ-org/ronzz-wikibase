<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EntityLabelMatcher;

/**
 * Duplicate detection for the Add* creation flows (the duplication guard):
 * finds an existing local item that the about-to-be-created item would
 * duplicate, by two orthogonal signals:
 *
 *  1. **Exact statement values** — an identical authority external id
 *     (Wikidata Q-id, OpenAlex id, ORCID, DOI, ISBN, VIAF, ISNI, YouTube
 *     ids) or an identical web URL (official website, source repository,
 *     documentation URL, access URL) on any existing item, found via ONE
 *     WDQS SPARQL query with `VALUES (?p ?v) { … }`.
 *  2. **Fuzzy label** — a highly similar (>= 0.75) class-filtered label via
 *     the shared EntityLabelMatcher (exact → prefix → token-containment →
 *     Levenshtein).
 *
 * Both lookups are exception-safe: an unreachable WDQS or an unseeded term
 * store yields NO duplicate (the creation flow proceeds) — the guard must
 * never block creation on infrastructure failure. The SPARQL runner is
 * injectable so the query building and the row matching stay unit-testable
 * without a MediaWiki runtime (the SiteRootMatcher pattern).
 *
 * @license GPL-2.0-or-later
 */
final class DuplicateFinder {

	/**
	 * @var callable(string):array<int,array<string,array{type:string,value:string}>|array<string,mixed>>|null
	 *      runs a SPARQL SELECT and returns the decoded `results.bindings`,
	 *      or null on failure
	 */
	private $sparqlRunner;

	/** @var EntityLabelMatcher|null */
	private $labelMatcher;

	/** @var string instance-of property id for the default label matcher */
	private $instanceOfPropertyId;

	/**
	 * @param callable(string):?array $sparqlRunner injected for tests
	 * @param EntityLabelMatcher|null $labelMatcher injected for tests
	 * @param string $instanceOfPropertyId for the DEFAULT label matcher's
	 *        class filter (the instance's P31-aligned id; ignored when a
	 *        matcher is injected)
	 */
	public function __construct( ?callable $sparqlRunner = null, ?EntityLabelMatcher $labelMatcher = null, string $instanceOfPropertyId = '' ) {
		$this->sparqlRunner = $sparqlRunner;
		$this->labelMatcher = $labelMatcher;
		$this->instanceOfPropertyId = $instanceOfPropertyId;
	}

	/**
	 * Existing item carrying ANY of the given (property => value) statement
	 * pairs, or null. One SPARQL query:
	 *
	 *   SELECT ?item ?label WHERE {
	 *     VALUES (?p ?v) { (wdt:P12 "Q28771536") (wdt:P37 "https://…") }
	 *     ?item ?p ?v .
	 *     OPTIONAL { ?item rdfs:label ?label FILTER(LANG(?label) = "en") }
	 *   } LIMIT 5
	 *
	 * @param array<string,string> $pairs property id (P12) => exact value
	 * @param string $wd   entity URI base, e.g. https://wikibase.ronzz.org/entity/
	 * @param string $wdt  prop/direct base (wd with /entity/ → /prop/direct/)
	 * @return array{itemId:string,label:string}|null
	 */
	public function findByValues( array $pairs, string $wd, string $wdt ): ?array {
		$pairs = array_filter(
			$pairs,
			static fn ( $v ) => is_string( $v ) && trim( $v ) !== ''
		);
		if ( $pairs === [] ) {
			return null;
		}
		$values = [];
		foreach ( $pairs as $propertyId => $value ) {
			if ( preg_match( '/^P[1-9]\d*$/i', $propertyId ) !== 1 ) {
				continue;
			}
			$values[] = '(wdt:' . strtoupper( $propertyId ) . ' "' . self::escapeLiteral( trim( $value ) ) . '")';
		}
		if ( $values === [] ) {
			return null;
		}
		$query = "PREFIX wd: <{$wd}> PREFIX wdt: <{$wdt}>\n"
			. "SELECT ?item ?label WHERE {\n"
			. "  VALUES (?p ?v) { " . implode( ' ', $values ) . " }\n"
			. "  ?item ?p ?v .\n"
			. '  OPTIONAL { ?item rdfs:label ?label FILTER(LANG(?label) = "en") }'
			. "\n} LIMIT 5";

		$rows = $this->runQuery( $query );
		if ( $rows === null ) {
			return null;
		}
		return self::firstItemFromRows( $rows );
	}

	/**
	 * Pure: first Q-item row in decoded SPARQL bindings (item URI →
	 * `…/entity/Q123`), with its optional en label.
	 *
	 * @param array<int,array<string,array{type:string,value:string}|array<string,mixed>>> $rows
	 * @return array{itemId:string,label:string}|null
	 */
	public static function firstItemFromRows( array $rows ): ?array {
		foreach ( $rows as $row ) {
			$item = (string)( $row['item']['value'] ?? '' );
			$qid = basename( $item );
			if ( preg_match( '/^Q[1-9]\d*$/i', $qid ) !== 1 ) {
				continue;
			}
			$label = (string)( $row['label']['value'] ?? '' );
			return [ 'itemId' => $qid, 'label' => $label !== '' ? $label : $qid ];
		}
		return null;
	}

	/**
	 * Fuzzy-label duplicate: the best class-filtered match (>= 0.75), or
	 * null. The exact-label case is covered by the callers' silent-reuse
	 * path (findItemIdByLabel) — here we catch the HIGHLY SIMILAR labels.
	 *
	 * @param string[] $classItemIds instance-of filter (the flow's classes)
	 * @return array{itemId:string,label:string}|null
	 */
	public function findByLabel( string $label, array $classItemIds = [] ): ?array {
		if ( trim( $label ) === '' ) {
			return null;
		}
		$match = $this->labelMatcher()->findBestMatch( $label, $classItemIds );
		if ( $match === null ) {
			return null;
		}
		return [ 'itemId' => $match['itemId'], 'label' => $match['label'] ];
	}

	/** SPARQL string-literal escaping (" and \). */
	public static function escapeLiteral( string $value ): string {
		return strtr( $value, [ '\\' => '\\\\', '"' => '\\"' ] );
	}

	// ------------------------------------------------------------- internals

	/** @return array<int,array<string,mixed>>|null */
	private function runQuery( string $query ): ?array {
		if ( $this->sparqlRunner === null ) {
			return null;
		}
		try {
			$rows = ( $this->sparqlRunner )( $query );
			return is_array( $rows ) ? $rows : null;
		} catch ( \Throwable $e ) {
			// WDQS unreachable: the guard is an enhancement, never a
			// blocker — creation proceeds.
			return null;
		}
	}

	private function labelMatcher(): EntityLabelMatcher {
		if ( $this->labelMatcher === null ) {
			// Default: the instance term store via EntitySearchHelper, with
			// the class filter enabled (instance-of property id passed in).
			$this->labelMatcher = new EntityLabelMatcher( null, $this->instanceOfPropertyId );
		}
		return $this->labelMatcher;
	}

}
