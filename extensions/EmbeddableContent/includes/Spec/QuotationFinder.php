<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Content\PayloadCodec;

/**
 * Quotation listing for a source item (issue #79): finds every quotation
 * item — class `quotation`, `source` statement pointing at the given item —
 * via ONE WDQS SPARQL query, returning the item id, its DECODED payload
 * (the quotation text, escape-at-rest form decoded) and the optional en
 * label.
 *
 * Mirrors the DuplicateFinder shape: the query building and the row parsing
 * are pure and unit-tested with an injected SPARQL runner; the
 * MediaWiki-bound HTTP execution lives in QuotationLookup. Exception-safe
 * by contract — an unreachable WDQS yields null (the caller degrades to
 * "no data", never a 500). WDQS is eventually consistent, so a quotation
 * created moments ago may not appear for a few minutes — the parser-cache
 * dependency re-renders the listing when the source item changes, and the
 * Special page is never parser-cached.
 *
 * @license GPL-2.0-or-later
 */
final class QuotationFinder {

	public const MAX_ROWS = 500;

	/**
	 * @var callable(string):array<int,array<string,array{type:string,value:string}|array<string,mixed>>>|null
	 *      runs a SPARQL SELECT and returns the decoded `results.bindings`,
	 *      or null on failure
	 */
	private $sparqlRunner;

	/** @param callable(string):?array $sparqlRunner injected for tests */
	public function __construct( ?callable $sparqlRunner = null ) {
		$this->sparqlRunner = $sparqlRunner;
	}

	/**
	 * All quotations of one source item, in item-id order. Every row:
	 *   qid     — the quotation item id (Q…);
	 *   content — the payload decoded with PayloadCodec ('' when the item
	 *             has no payload statement);
	 *   label   — the en term when present ('' otherwise).
	 *
	 * @param string $sourceItemId        the source item (Q…)
	 * @param string $quotationClassId    the quotation class item (Q…)
	 * @param string $contentPropertyId   the quotation payload property (P…)
	 * @param string $sourcePropertyId    the `source` provenance property (P…)
	 * @param string $instanceOfPropertyId the instance-of property (P…)
	 * @param string $wd   entity URI base, e.g. https://wikibase.ronzz.org/entity/
	 * @param string $wdt  prop/direct base
	 * @return array<int,array{qid:string,content:string,label:string}>|null
	 *         null when WDQS is unreachable / the query failed
	 */
	public function findForSource(
		string $sourceItemId,
		string $quotationClassId,
		string $contentPropertyId,
		string $sourcePropertyId,
		string $instanceOfPropertyId,
		string $wd,
		string $wdt
	): ?array {
		if ( preg_match( '/^Q[1-9]\d*$/i', $sourceItemId ) !== 1
			|| preg_match( '/^Q[1-9]\d*$/i', $quotationClassId ) !== 1
		) {
			return [];
		}
		foreach ( [ $contentPropertyId, $sourcePropertyId, $instanceOfPropertyId ] as $propertyId ) {
			if ( preg_match( '/^P[1-9]\d*$/i', $propertyId ) !== 1 ) {
				return [];
			}
		}

		$query = 'PREFIX wd: <' . $wd . '> PREFIX wdt: <' . $wdt . ">\n"
			. 'SELECT ?item ?content ?label WHERE {' . "\n"
			// The source link, the class, and the (optional) payload.
			// Monolingual payload values surface as language-tagged
			// literals; STR() keeps the text.
			. '  ?item wdt:' . strtoupper( $sourcePropertyId ) . ' wd:' . strtoupper( $sourceItemId ) . " .\n"
			. '  ?item wdt:' . strtoupper( $instanceOfPropertyId ) . ' wd:' . strtoupper( $quotationClassId ) . " .\n"
			. '  OPTIONAL { ?item wdt:' . strtoupper( $contentPropertyId ) . ' ?content }' . "\n"
			. '  OPTIONAL { ?item rdfs:label ?label FILTER(LANG(?label) = "en") }' . "\n"
			. '} ORDER BY ?item LIMIT ' . self::MAX_ROWS;

		$rows = $this->runQuery( $query );
		if ( $rows === null ) {
			return null;
		}
		return self::rowsToQuotations( $rows );
	}

	/**
	 * Pure: decoded quotation rows from decoded SPARQL bindings.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array{qid:string,content:string,label:string}>
	 */
	public static function rowsToQuotations( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			$qid = basename( (string)( $row['item']['value'] ?? '' ) );
			if ( preg_match( '/^Q[1-9]\d*$/i', $qid ) !== 1 ) {
				continue;
			}
			$content = (string)( $row['content']['value'] ?? '' );
			$label = (string)( $row['label']['value'] ?? '' );
			$out[] = [
				'qid' => strtoupper( $qid ),
				// The stored payload is the escape-at-rest form; the
				// listing shows the logical (multi-line) content.
				'content' => $content !== '' ? PayloadCodec::decode( $content ) : '',
				'label' => $label,
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
