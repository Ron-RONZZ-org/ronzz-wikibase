<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Relevance ranking for merged external-authority search results.
 *
 * The provider cascade deliberately concatenates providers in a fixed
 * order (Wikidata → Open Library → OpenAlex → Crossref for works). That
 * order is a fallback, not a relevance signal: an earlier provider's ten
 * loosely matched records can fill the 10-result cap before a later
 * provider's exact title is even reached (the "On the psychology of
 * prediction" AddSource report — Open Library returned four irrelevant
 * books ahead of OpenAlex's exact title).
 *
 * This ranker sorts the MERGED list by how closely each record's label
 * matches the query, with a stable tie-break that preserves the original
 * provider order. Tiers (lower is better):
 *   0   exact (case/punctuation-insensitive)
 *   10  the title starts with the query, or the query starts with the title
 *   20  the query appears inside the title
 *   30  every query token appears in the title, in order
 *   40  every query token appears in the title (any order)
 *   50  some query token appears in the title
 *   1000 no token overlap
 *
 * Titles are normalised by decoding entities, stripping tags, lowercasing
 * and collapsing punctuation/whitespace to single spaces (OpenAlex titles
 * carry trailing periods; Crossref titles carry HTML markup). Pure PHP
 * (mbstring only), unit-tested.
 *
 * @license GPL-2.0-or-later
 */
final class RecordRanker {

	/**
	 * Rank records by relevance to `$query`, preserving the input order for
	 * equal scores.
	 *
	 * @param object[] $records
	 * @param string $query the text the user typed
	 * @param callable(object):string $label the record's display label/title
	 * @return object[] the same records, best match first
	 */
	public static function rank( array $records, string $query, callable $label ): array {
		$queryKey = self::normalize( $query );
		if ( $queryKey === '' || $records === [] ) {
			return $records;
		}
		$queryTokens = explode( ' ', $queryKey );
		$scored = [];
		foreach ( $records as $index => $record ) {
			$scored[] = [
				'score' => self::score( self::normalize( (string)$label( $record ) ), $queryKey, $queryTokens ),
				'index' => $index,
				'record' => $record,
			];
		}
		usort( $scored, static function ( array $a, array $b ): int {
			return ( $a['score'] <=> $b['score'] ) ?: ( $a['index'] <=> $b['index'] );
		} );
		return array_column( $scored, 'record' );
	}

	/**
	 * Case- and punctuation-insensitive form used for comparison.
	 */
	public static function normalize( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// OpenAlex/Crossref titles carry markup (e.g. <i>…</i>).
		$text = preg_replace( '/<[^>]*>/', '', $text ) ?? $text;
		$text = mb_strtolower( $text, 'UTF-8' );
		// Every run of non-letter/digit characters is a separator; letters
		// and digits from any script survive (\p{L}\p{N}).
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		return trim( $text );
	}

	/**
	 * @param string $titleKey normalized title
	 * @param string $queryKey normalized query
	 * @param string[] $queryTokens
	 */
	private static function score( string $titleKey, string $queryKey, array $queryTokens ): int {
		if ( $titleKey === $queryKey ) {
			return 0;
		}
		if ( str_starts_with( $titleKey, $queryKey . ' ' )
			|| str_starts_with( $queryKey, $titleKey . ' ' )
		) {
			return 10;
		}
		if ( str_contains( $titleKey, $queryKey ) ) {
			return 20;
		}
		$titleTokens = explode( ' ', $titleKey );
		if ( self::containsInOrder( $titleTokens, $queryTokens ) ) {
			return 30;
		}
		$present = 0;
		foreach ( $queryTokens as $token ) {
			if ( in_array( $token, $titleTokens, true ) ) {
				$present++;
			}
		}
		if ( $present === count( $queryTokens ) ) {
			return 40;
		}
		// Partial matches rank by how many query tokens they contain.
		return $present > 0 ? 50 + ( count( $queryTokens ) - $present ) : 1000;
	}

	/**
	 * Whether every needle token appears in $haystack in the same order.
	 *
	 * @param string[] $haystack
	 * @param string[] $needles
	 */
	private static function containsInOrder( array $haystack, array $needles ): bool {
		$cursor = 0;
		$total = count( $haystack );
		foreach ( $needles as $needle ) {
			$found = false;
			for ( ; $cursor < $total; $cursor++ ) {
				if ( $haystack[$cursor] === $needle ) {
					$cursor++;
					$found = true;
					break;
				}
			}
			if ( !$found ) {
				return false;
			}
		}
		return true;
	}

}
