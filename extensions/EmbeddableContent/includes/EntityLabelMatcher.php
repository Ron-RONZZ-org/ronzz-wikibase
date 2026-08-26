<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Fuzzy entity-label matching for the "auto-fill entity fields from fetched
 * source data" flow (the autofill-confirm-update batch): upload-metadata
 * license labels, harvested publisher/journal strings, typed author names.
 *
 * Matching is deliberately GENEROUS — a "good match" (>= GOOD_MATCH_THRESHOLD)
 * merely PRE-FILLS the entity combobox together with a confirmation banner
 * ("we think this corresponds to {label} (Q#)"). The user confirms or
 * corrects, so a false positive is recoverable; a false NEGATIVE (no
 * match) falls back to the plain hint flow.
 *
 * The label search is injectable (the pure scoring is unit-testable without
 * a MediaWiki runtime); the default implementation searches the instance's
 * own term store via WikibaseRepo's EntitySearchHelper — the same engine
 * behind the `wbsearchentities` API the entity comboboxes use, so the
 * candidates are what the user would see typing the same text. Because the
 * instance's term store is case-sensitive (VARBINARY wbx_text, upstream
 * T242644), the search runs the raw, title-cased and uppercase variants and
 * merges the hits — the same trick resources/entitysuggest.js uses.
 *
 * @license GPL-2.0-or-later
 */
final class EntityLabelMatcher {

	/** Minimum score for a match worth confirming. */
	public const GOOD_MATCH_THRESHOLD = 0.75;

	/**
	 * Inject the label search for unit tests: receives the query text and a
	 * candidate cap, returns candidate entity ids (best first).
	 *
	 * @var callable(string,int):array<int,EntityId>|null
	 */
	private $searcher;

	/**
	 * Instance-of property id for the class filter (from the seed's config
	 * map — never hardcoded; '' disables the filter).
	 *
	 * @var string
	 */
	private $instanceOfPropertyId;

	/**
	 * @param callable(string,int):array<int,EntityId>|null $searcher
	 * @param string $instanceOfPropertyId config-derived instance-of property id
	 */
	public function __construct( ?callable $searcher = null, string $instanceOfPropertyId = '' ) {
		$this->searcher = $searcher;
		$this->instanceOfPropertyId = $instanceOfPropertyId;
	}

	/**
	 * Best existing item for a fetched label, or null when nothing scores at
	 * or above GOOD_MATCH_THRESHOLD.
	 *
	 * @param string[] $classItemIds optional instance-of filter — only items
	 *                               whose class is in this set are considered
	 * @return array{itemId:string,label:string,score:float}|null
	 */
	public function findBestMatch( string $label, array $classItemIds = [], int $maxCandidates = 8 ): ?array {
		$label = trim( $label );
		if ( $label === '' || $maxCandidates < 1 ) {
			return null;
		}

		$ids = $this->searchCandidates( $label, $maxCandidates );
		if ( $ids === [] ) {
			return null;
		}

		// Best-effort contract: an entity-lookup failure yields no match and
		// the caller falls back to its plain hint flow.
		try {
			$lookup = WikibaseRepo::getEntityLookup();
			$best = null;
			foreach ( $ids as $id ) {
				if ( !$id instanceof EntityId ) {
					continue;
				}
				$item = $lookup->getEntity( $id );
				if ( !$item instanceof Item ) {
					continue;
				}
				if ( $classItemIds !== [] && !$this->itemHasClass( $item, $classItemIds ) ) {
					continue;
				}
				$candidateLabel = self::itemLabel( $item );
				if ( $candidateLabel === '' ) {
					continue;
				}
				$score = self::scorePair( $label, $candidateLabel );
				if ( $score >= self::GOOD_MATCH_THRESHOLD
					&& ( $best === null || $score > $best['score'] )
				) {
					$best = [
						'itemId' => $id->getSerialization(),
						'label' => $candidateLabel,
						'score' => $score,
					];
				}
			}
			return $best;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Pure helper: the best (label, score) pair from an iterable of candidate
	 * labels (e.g. the config's known license items), or null when nothing
	 * reaches the threshold. Used when the candidate set is already known
	 * (license items) and no term-store search is needed.
	 *
	 * @param iterable<string> $candidateLabels label => item id (or plain labels)
	 * @return array{label:string,score:float}|null
	 */
	public static function bestMatchFromLabels( string $fetched, iterable $candidateLabels ): ?array {
		$fetched = trim( $fetched );
		if ( $fetched === '' ) {
			return null;
		}
		$best = null;
		foreach ( $candidateLabels as $candidate => $_ ) {
			$score = self::scorePair( $fetched, (string)$candidate );
			if ( $score >= self::GOOD_MATCH_THRESHOLD
				&& ( $best === null || $score > $best['score'] )
			) {
				$best = [ 'label' => (string)$candidate, 'score' => $score ];
			}
		}
		return $best;
	}

	/**
	 * Pure normalized similarity between a fetched label and a candidate
	 * label, 0..1. The steps (each returning early on success):
	 *   1.0  exact (after normalization — punctuation/case/parentheticals)
	 *   0.9  one is a prefix of the other (both >= 6 chars)
	 *   0.85 every significant word of the shorter side appears in the other
	 *   0.8+ Levenshtein similarity for near-misses ("MIT License" vs
	 *        "MIT Licence")
	 *   0.0  otherwise
	 */
	public static function scorePair( string $fetched, string $candidate ): float {
		$f = self::compact( $fetched );
		$c = self::compact( $candidate );
		if ( $f === '' || $c === '' ) {
			return 0.0;
		}
		if ( $f === $c ) {
			return 1.0;
		}
		if ( mb_strlen( $f ) >= 6 && mb_strlen( $c ) >= 6
			&& ( str_starts_with( $c, $f ) || str_starts_with( $f, $c ) )
		) {
			return 0.9;
		}
		$fw = self::words( $fetched );
		$cw = self::words( $candidate );
		if ( $fw !== [] && $cw !== [] ) {
			$shorter = count( $fw ) <= count( $cw ) ? $fw : $cw;
			$longer = count( $fw ) <= count( $cw ) ? $cw : $fw;
			if ( array_intersect( $shorter, $longer ) === $shorter ) {
				return 0.85;
			}
		}
		$maxLen = max( mb_strlen( $f ), mb_strlen( $c ) );
		if ( $maxLen > 0 ) {
			$sim = 1 - ( levenshtein( $f, $c ) / $maxLen );
			if ( $sim >= 0.8 ) {
				return $sim;
			}
		}
		return 0.0;
	}

	// ------------------------------------------------------------- internals

	/**
	 * Runs the search over the raw, title-cased and uppercase variants (the
	 * instance's term store is case-sensitive) and returns the deduped,
	 * capped candidate ids, best first.
	 *
	 * @return array<int,EntityId>
	 */
	private function searchCandidates( string $label, int $maxCandidates ): array {
		$searcher = $this->searcher ?? self::defaultSearcher();
		$variants = [ $label ];
		$titleCased = (string)preg_replace_callback(
			'/(^|\s)(\S)/u',
			static fn ( array $m ): string => $m[1] . mb_strtoupper( $m[2] ),
			$label
		);
		if ( $titleCased !== $label ) {
			$variants[] = $titleCased;
		}
		$uppercased = mb_strtoupper( $label );
		if ( $uppercased !== $label && $uppercased !== $titleCased ) {
			$variants[] = $uppercased;
		}

		$seen = [];
		$ids = [];
		foreach ( $variants as $variant ) {
			if ( count( $ids ) >= $maxCandidates ) {
				break;
			}
			// Best-effort: a search failure (service unavailable, unseeded
			// instance) yields no candidates — the caller falls back to the
			// plain hint flow.
			try {
				$candidates = $searcher( $variant, $maxCandidates );
			} catch ( \Throwable $e ) {
				$candidates = [];
			}
			foreach ( $candidates as $id ) {
				if ( !$id instanceof EntityId ) {
					continue;
				}
				$key = $id->getSerialization();
				if ( isset( $seen[$key] ) ) {
					continue;
				}
				$seen[$key] = true;
				$ids[] = $id;
				if ( count( $ids ) >= $maxCandidates ) {
					break 2;
				}
			}
		}
		return $ids;
	}

	/** @return callable(string,int):array<int,EntityId> */
	private static function defaultSearcher(): callable {
		return static function ( string $text, int $limit ): array {
			// 'en' labels (the instance's primary terms) — the Add* review
			// forms store/display English labels; the strict-language flag is
			// off so fallback labels still surface.
			return WikibaseRepo::getEntitySearchHelper()->getRankedSearchResults(
				$text,
				'en',
				Item::ENTITY_TYPE,
				$limit,
				false
			);
		};
	}

	/** English label of an item, or '' when the item has none. */
	private static function itemLabel( Item $item ): string {
		$term = $item->getLabels()->getByLanguage( 'en' );
		if ( $term === null ) {
			foreach ( $item->getLabels()->toTextArray() as $text ) {
				if ( $text !== '' ) {
					return $text;
				}
			}
			return '';
		}
		return $term->getText();
	}

	/** @param string[] $classItemIds */
	private function itemHasClass( Item $item, array $classItemIds ): bool {
		// The instance-of property id is passed in from the caller's config
		// (the instance's P31-aligned property is instance-specific).
		$propertyId = new \Wikibase\DataModel\Entity\NumericPropertyId( $this->instanceOfPropertyId );
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof \Wikibase\DataModel\Entity\EntityIdValue
				&& in_array( $value->getEntityId()->getSerialization(), $classItemIds, true )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lowercased, punctuation/case/parenthetical-stripped compact form for
	 * exact/prefix/Levenshtein comparison: "CC BY-SA 4.0 International" →
	 * "ccbysa40international", "Nature (journal)" → "nature".
	 */
	private static function compact( string $label ): string {
		$label = mb_strtolower( trim( $label ) );
		// A trailing parenthetical qualifier ("Nature (journal)") is noise
		// for matching.
		$label = (string)preg_replace( '/\s*\([^)]*\)\s*$/u', '', $label );
		return (string)preg_replace( '/[\p{P}\p{S}\s]+/u', '', $label );
	}

	/**
	 * Significant words (>= 2 chars) of a label for token-containment
	 * comparison: "CC BY-SA 4.0 International" → [cc, by, sa, 4, 0,
	 * international].
	 *
	 * @return string[]
	 */
	private static function words( string $label ): array {
		$label = mb_strtolower( $label );
		if ( preg_match_all( '/[\p{L}\p{N}]+/u', $label, $m ) === false ) {
			return [];
		}
		return array_values( array_filter(
			$m[0],
			static fn ( string $w ): bool => mb_strlen( $w ) >= 2
		) );
	}
}
