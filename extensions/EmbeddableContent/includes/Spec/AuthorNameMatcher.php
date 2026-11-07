<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EntityLabelMatcher;

/**
 * Pure scoring for an author name against a candidate person label.
 *
 * The AddSource author-name resolver searches local Person items, then
 * ranks the candidates with this matcher. Author names are ambiguous
 * ("John Smith"), so the matcher never decides: it only produces a score
 * that orders candidates for explicit human confirmation. The rules cover
 * the common bibliography variants:
 *
 *  - exact full name (1.0);
 *  - same family + given names as a prefix/subset ("John Smith" vs
 *    "John Paul Smith" — the no-middle-name case, 0.95);
 *  - same family + matching initial ("J. Smith" vs "John Smith", 0.9);
 *  - same family + fuzzy given (0.85);
 *  - same family + no/unknown given (0.8 — mononym / ambiguous);
 *  - a generic label-similarity fallback (EntityLabelMatcher, >= 0.75).
 *
 * A completely different given name for the same family scores below the
 * threshold (0.7) so a page of unrelated "Smith" people does not drown the
 * real candidate; the resolver can still surface them in a separate
 * "same family" bucket if needed.
 *
 * @license GPL-2.0-or-later
 */
final class AuthorNameMatcher {

	/** Minimum score worth showing as a candidate. */
	public const GOOD_MATCH_THRESHOLD = 0.75;

	/** Generational suffixes that belong to the family name. */
	private const SUFFIXES = [ 'jr', 'sr', 'ii', 'iii', 'iv', 'v' ];

	/**
	 * @param array{givenName:string,familyName:string} $name parsed name row
	 * @param string $candidateLabel an item label
	 * @return array{score:float,reason:string}
	 */
	public static function score( array $name, string $candidateLabel ): array {
		$given = self::normalize( (string)( $name['givenName'] ?? '' ) );
		$family = self::normalize( (string)( $name['familyName'] ?? '' ) );
		$full = trim( $given . ' ' . $family );
		$candidate = self::normalize( $candidateLabel );
		if ( $candidate === '' ) {
			return [ 'score' => 0.0, 'reason' => 'empty' ];
		}
		if ( $full !== '' && $full === $candidate ) {
			return [ 'score' => 1.0, 'reason' => 'exact' ];
		}

		[ $candidateGiven, $candidateFamily ] = self::splitLabel( $candidate );
		if ( $family !== '' && $candidateFamily !== '' && $family !== $candidateFamily ) {
			// Different family: fall back to the generic label similarity.
			$score = EntityLabelMatcher::scorePair( $full !== '' ? $full : $candidate, $candidateLabel );
			return $score >= self::GOOD_MATCH_THRESHOLD
				? [ 'score' => $score, 'reason' => 'fuzzy' ]
				: [ 'score' => 0.0, 'reason' => 'different_family' ];
		}

		// Mononym / unknown given name: the family is all we have.
		if ( $given === '' || $candidateGiven === '' ) {
			return [ 'score' => 0.8, 'reason' => 'mononym' ];
		}

		if ( $given === $candidateGiven ) {
			return [ 'score' => 1.0, 'reason' => 'exact' ];
		}

		$givenTokens = self::tokens( $given );
		$candidateGivenTokens = self::tokens( $candidateGiven );
		if ( $givenTokens !== [] && $candidateGivenTokens !== []
			&& self::isTokenPrefix( $givenTokens, $candidateGivenTokens )
		) {
			return [ 'score' => 0.95, 'reason' => 'given_prefix' ];
		}
		if ( $givenTokens !== [] && $candidateGivenTokens !== []
			&& self::firstTokenInitialMatch( $givenTokens, $candidateGivenTokens )
		) {
			return [ 'score' => 0.9, 'reason' => 'given_initial' ];
		}
		if ( self::stringSimilarity( $given, $candidateGiven ) >= 0.8 ) {
			return [ 'score' => 0.85, 'reason' => 'given_fuzzy' ];
		}
		// Same family, clearly different given name: keep it out of the
		// candidate list (0.7 < threshold).
		return [ 'score' => 0.7, 'reason' => 'family_only' ];
	}

	/**
	 * Split a label into given/family with the same last-word (and
	 * generational-suffix) rule the parser uses.
	 *
	 * @return array{0:string,1:string} [given, family]
	 */
	private static function splitLabel( string $label ): array {
		$tokens = self::tokens( $label );
		if ( count( $tokens ) <= 1 ) {
			return [ '', $tokens[0] ?? '' ];
		}
		$family = array_pop( $tokens );
		if ( in_array( rtrim( $family, '.' ), self::SUFFIXES, true ) && $tokens !== [] ) {
			$family = array_pop( $tokens ) . ' ' . $family;
		}
		return [ implode( ' ', $tokens ), $family ];
	}

	/**
	 * Whether $prefix appears as an in-order prefix of $tokens (the
	 * no-middle-name case: ["john"] is a prefix of ["john","paul"]).
	 *
	 * @param string[] $prefix
	 * @param string[] $tokens
	 */
	private static function isTokenPrefix( array $prefix, array $tokens ): bool {
		if ( count( $prefix ) > count( $tokens ) ) {
			return false;
		}
		foreach ( $prefix as $i => $token ) {
			if ( $token !== $tokens[$i] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * "J." vs "John" — the first given token matches as an initial.
	 *
	 * @param string[] $a
	 * @param string[] $b
	 */
	private static function firstTokenInitialMatch( array $a, array $b ): bool {
		$left = rtrim( $a[0], '.' );
		$right = rtrim( $b[0], '.' );
		if ( $left === '' || $right === '' ) {
			return false;
		}
		return $left[0] === $right[0] && ( strlen( $left ) === 1 || strlen( $right ) === 1 );
	}

	private static function stringSimilarity( string $a, string $b ): float {
		$max = max( mb_strlen( $a ), mb_strlen( $b ) );
		if ( $max === 0 ) {
			return 1.0;
		}
		return 1.0 - ( levenshtein( $a, $b ) / $max );
	}

	/**
	 * Lowercase, strip diacritics/combining marks, remove punctuation that
	 * is not a space or hyphen, and collapse whitespace.
	 */
	private static function normalize( string $text ): string {
		$text = trim( $text );
		if ( $text === '' ) {
			return '';
		}
		if ( class_exists( \Normalizer::class ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_D );
			if ( is_string( $normalized ) ) {
				$text = (string)preg_replace( '/\p{Mn}+/u', '', $normalized );
			}
		}
		$text = mb_strtolower( $text );
		$text = (string)preg_replace( '/[^\p{L}\p{N}\s\-]+/u', ' ', $text );
		$text = (string)preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/** @return string[] */
	private static function tokens( string $text ): array {
		$tokens = preg_split( '/\s+/u', trim( $text ) ) ?: [];
		return array_values( array_filter( $tokens, static fn ( string $t ): bool => $t !== '' ) );
	}

}
