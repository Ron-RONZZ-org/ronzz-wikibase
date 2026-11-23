<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

/**
 * The version policy of the LEGI corpus.
 *
 * The DILA LEGI tree carries EVERY version of an article (VIGUEUR, MODIFIE,
 * ABROGE, MODIFIE_MORT_NE), and the store keeps one row per (law, reference).
 * A v1 import kept whichever version was iterated last — which served
 * abrogated text for some articles (Code civil art. 9 was the 1927 text).
 *
 * Pure PHP so the policy is unit-testable without MediaWiki.
 *
 * @license GPL-2.0-or-later
 */
final class LawVersionPolicy {

	private const RANK = [
		'VIGUEUR' => 3,
		'MODIFIE' => 2,
		'ABROGE' => 1,
		'MODIFIE_MORT_NE' => 0,
	];

	public static function isInForce( array $article ): bool {
		return strtoupper( (string)( $article['status'] ?? '' ) ) === 'VIGUEUR';
	}

	/**
	 * Whether $candidate should replace the stored $existing row for the same
	 * (law, reference): the higher state wins, then the latest start date.
	 *
	 * @param array<string,mixed> $candidate
	 * @param array<string,mixed> $existing
	 */
	public static function isPreferred( array $candidate, array $existing ): bool {
		$candidateRank = self::RANK[strtoupper( (string)( $candidate['status'] ?? '' ) )] ?? 2;
		$existingRank = self::RANK[strtoupper( (string)( $existing['status'] ?? '' ) )] ?? 2;
		if ( $candidateRank !== $existingRank ) {
			return $candidateRank > $existingRank;
		}
		return (string)( $candidate['version_start'] ?? '' ) >= (string)( $existing['version_start'] ?? '' );
	}
}
