<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Child-item listing for a parent item (issue follow-up — the quotation
 * listing analogue for child SOURCE classes): finds every item that
 * `part of` the given parent AND is classified under one of the parent
 * class's child classes (book → bookExcerpt, youtubeChannel →
 * youtubeVideo), via ONE WDQS SPARQL query, returning the item id + the
 * optional en label.
 *
 * Mirrors the QuotationFinder shape: the query building and the row
 * parsing are pure and unit-tested with an injected SPARQL runner; the
 * MediaWiki-bound HTTP execution lives in ChildItemLookup. Exception-safe
 * by contract — an unreachable WDQS yields null (the caller degrades to
 * "no data", never a 500). WDQS is eventually consistent, so a child
 * created moments ago may not appear for a few minutes — the parser-cache
 * dependency re-renders the listing when the parent item changes, the
 * Special page is never parser-cached, and child creation invalidates the
 * parent's classic page explicitly (see ChildItemLookup).
 *
 * @license GPL-2.0-or-later
 */
final class ChildItemFinder {

	public const MAX_ROWS = 500;

	/**
	 * @var callable(string):?array|null runs a SPARQL SELECT and returns
	 *      the decoded `results.bindings`, or null on failure
	 */
	private $sparqlRunner;

	/** @param callable(string):?array $sparqlRunner injected for tests */
	public function __construct( ?callable $sparqlRunner = null ) {
		$this->sparqlRunner = $sparqlRunner;
	}

	/**
	 * All child items of one parent item, in item-id order. Every row:
	 *   qid   — the child item id (Q…);
	 *   label — the en term when present ('' otherwise).
	 *
	 * @param string   $parentItemId        the parent item (Q…)
	 * @param string   $childClassId        one child class (Q…) whose items
	 *                                      count as children
	 * @param string   $partOfPropertyId    the child→parent link property (P…)
	 * @param string   $instanceOfPropertyId the instance-of property (P…)
	 * @param string   $wd   entity URI base, e.g. https://wikibase.ronzz.org/entity/
	 * @param string   $wdt  prop/direct base
	 * @return array<int,array{qid:string,label:string}>|null
	 *         null when WDQS is unreachable / the query failed
	 */
	public function findForParent(
		string $parentItemId,
		string $childClassId,
		string $partOfPropertyId,
		string $instanceOfPropertyId,
		string $wd,
		string $wdt
	): ?array {
		if ( preg_match( '/^Q[1-9]\d*$/i', $parentItemId ) !== 1
			|| preg_match( '/^Q[1-9]\d*$/i', $childClassId ) !== 1
		) {
			return [];
		}
		foreach ( [ $partOfPropertyId, $instanceOfPropertyId ] as $propertyId ) {
			if ( preg_match( '/^P[1-9]\d*$/i', $propertyId ) !== 1 ) {
				return [];
			}
		}

		// NB: string concatenation only — a SINGLE-quoted '\n' would reach
		// Blazegraph as a literal backslash and 400 the query (the
		// 2026-09-03 quotation-listing incident; regression-tested below).
		$query = 'PREFIX wd: <' . $wd . '> PREFIX wdt: <' . $wdt . ">\n"
			. 'SELECT ?item ?label WHERE {' . "\n"
			. '  ?item wdt:' . strtoupper( $partOfPropertyId ) . ' wd:' . strtoupper( $parentItemId ) . " .\n"
			. '  ?item wdt:' . strtoupper( $instanceOfPropertyId ) . ' wd:' . strtoupper( $childClassId ) . " .\n"
			. '  OPTIONAL { ?item rdfs:label ?label FILTER(LANG(?label) = "en") }' . "\n"
			. '} ORDER BY ?item LIMIT ' . self::MAX_ROWS;

		$rows = $this->runQuery( $query );
		if ( $rows === null ) {
			return null;
		}
		return self::rowsToChildren( $rows );
	}

	/**
	 * Pure: child rows from decoded SPARQL bindings.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array{qid:string,label:string}>
	 */
	public static function rowsToChildren( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			$qid = basename( (string)( $row['item']['value'] ?? '' ) );
			if ( preg_match( '/^Q[1-9]\d*$/i', $qid ) !== 1 ) {
				continue;
			}
			$out[] = [
				'qid' => strtoupper( $qid ),
				'label' => (string)( $row['label']['value'] ?? '' ),
			];
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>>|null */
	private function runQuery( string $query ): ?array {
		if ( $this->sparqlRunner === null ) {
			return null;
		}
		try {
			$rows = ( $this->sparqlRunner )( $query );
			return is_array( $rows ) ? $rows : null;
		} catch ( \Throwable $e ) {
			// WDQS unreachable: the listing is an enhancement, never a
			// blocker — the caller degrades to "no data".
			return null;
		}
	}
}
