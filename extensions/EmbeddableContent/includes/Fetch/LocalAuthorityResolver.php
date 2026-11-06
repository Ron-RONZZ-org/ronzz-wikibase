<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Search\EntityTermSearch;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * Resolves authority-record labels and identifiers against the local wiki:
 * publisher/journal labels (the AddSource combobox scopes), author records
 * (authority ID first, then an exact label), and person place labels.
 *
 * Returns candidates as well as a suggested Q-id: an LLM client can confirm
 * a fuzzy match instead of silently binding the wrong item.
 *
 * @license GPL-2.0-or-later
 */
class LocalAuthorityResolver {

	private const LOCAL_LIMIT = 5;

	/** @var callable(string): ?Item */
	private $entityLookup;

	public function __construct(
		private EntityTermSearch $entitySearch,
		private EmbeddableContentConfig $config,
		?callable $entityLookup = null
	) {
		$this->entityLookup = $entityLookup ?? static function ( string $id ): ?Item {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $id ) );
			return $entity instanceof Item ? $entity : null;
		};
	}

	/**
	 * @param object $record WorkRecord|PersonRecord|EntityRecord|SoftwareRecord
	 * @return array<string,mixed>
	 */
	public function resolve( string $kind, object $record, string $language ): array {
		return match ( $kind ) {
			'work' => $this->resolveWork( $record, $language ),
			'person' => $this->resolvePerson( $record, $language ),
			'entity' => [ 'collectiveClassWikidataIds' => $record->classWikidataIds ],
			'software' => [
				'license' => $this->resolveLabel( (string)( $record->license ?? '' ), [], $language ),
				'developer' => $this->resolveLabel(
					(string)( $record->developer ?? '' ),
					$this->agentClassIds(),
					$language
				),
				'programmingLanguage' => $this->resolveLabel(
					(string)( $record->programmingLanguage ?? '' ),
					[],
					$language
				),
			],
			default => [],
		};
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolveWork( WorkRecord $record, string $language ): array {
		$authors = [];
		foreach ( $record->authors ?? [] as $author ) {
			$authors[] = $this->resolveAuthor( $author, $language );
		}
		return [
			'publisher' => $this->resolveLabel(
				(string)( $record->publisher ?? '' ),
				$this->mergeScopes( $this->scopedClass( $this->config->publisherClass() ), $this->agentClassIds() ),
				$language
			),
			'journal' => $this->resolveLabel(
				(string)( $record->containerTitle ?? '' ),
				$this->mergeScopes(
					$this->scopedClass( $this->config->scholarlyJournalClass() ),
					array_values( $this->config->sourceClasses() )
				),
				$language
			),
			'authors' => $authors,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolvePerson( PersonRecord $record, string $language ): array {
		return [
			'placeOfBirth' => $this->resolveLabel( (string)( $record->placeOfBirth ?? '' ), [], $language ),
			'placeOfDeath' => $this->resolveLabel( (string)( $record->placeOfDeath ?? '' ), [], $language ),
		];
	}

	/**
	 * Resolve a label to local items (case-insensitive exact first, then the
	 * best candidate). The caller keeps the candidates so an LLM can confirm.
	 *
	 * @param string[] $classIds
	 * @return array{qid: ?string, label: string, exact: bool, candidates: array<int,array<string,mixed>>}
	 */
	private function resolveLabel( string $label, array $classIds, string $language ): array {
		$label = trim( $label );
		if ( $label === '' ) {
			return [ 'qid' => null, 'label' => '', 'exact' => false, 'candidates' => [] ];
		}
		$candidates = [];
		foreach ( $this->labelVariants( $label ) as $variant ) {
			$candidates = $this->entitySearch->search( $variant, $language, self::LOCAL_LIMIT, $classIds );
			if ( $candidates !== [] ) {
				break;
			}
		}
		$match = $this->matchByLabel( $candidates, $label );
		$chosen = $match ?? ( $candidates[0] ?? null );
		return [
			'qid' => $chosen['id'] ?? null,
			'label' => $chosen['label'] ?? $label,
			'exact' => $match !== null,
			'candidates' => $candidates,
		];
	}

	/**
	 * @return array{qid: ?string, label: string, matchedBy: ?string, exact: bool, candidates: array<int,array<string,mixed>>}
	 */
	private function resolveAuthor( AuthorRecord $author, string $language ): array {
		$candidates = $this->entitySearch->search(
			$author->label,
			$language,
			self::LOCAL_LIMIT,
			$this->agentClassIds()
		);
		// Crossref abbreviates given names ("A. Einstein"); the full label
		// often has no CONTAINS hit even when the person exists locally. Fall
		// back to the family name, which the model confirms via `exact`.
		if ( $candidates === [] && is_string( $author->familyName ) && $author->familyName !== '' ) {
			$candidates = $this->entitySearch->search(
				$author->familyName,
				$language,
				self::LOCAL_LIMIT,
				$this->agentClassIds()
			);
		}
		$match = $this->matchByAuthority( $candidates, $author )
			?? $this->matchByLabel( $candidates, $author->label );
		$chosen = $match ?? ( $candidates[0] ?? null );
		return [
			'qid' => $chosen['id'] ?? null,
			'label' => $author->label,
			'matchedBy' => $match['matchedBy'] ?? ( $match !== null ? 'label' : null ),
			'exact' => $match !== null,
			'candidates' => $candidates,
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @return array<string,mixed>|null
	 */
	private function matchByLabel( array $candidates, string $label ): ?array {
		foreach ( $candidates as $candidate ) {
			if ( isset( $candidate['label'] )
				&& strcasecmp( trim( (string)$candidate['label'] ), trim( $label ) ) === 0
			) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @return array<string,mixed>|null
	 */
	private function matchByAuthority( array $candidates, AuthorRecord $author ): ?array {
		$ids = $this->config->externalIdPropertyIds();
		$checks = [];
		foreach ( [
			'wikidata' => $author->wikidataId,
			'orcid' => $author->orcid,
			'viaf' => $author->viafId,
			'isni' => $author->isni,
			'openalexAuthor' => $author->openalexId,
		] as $key => $value ) {
			if ( is_string( $value ) && trim( $value ) !== '' && isset( $ids[$key] ) ) {
				$checks[] = [ $ids[$key], trim( $value ), $key ];
			}
		}
		if ( $checks === [] ) {
			return null;
		}
		foreach ( $candidates as $candidate ) {
			$id = (string)( $candidate['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$item = ( $this->entityLookup )( $id );
			if ( !$item instanceof Item ) {
				continue;
			}
			foreach ( $checks as [ $propertyId, $value, $key ] ) {
				if ( $this->claimHasValue( $item, $propertyId, $value ) ) {
					$candidate['matchedBy'] = $key;
					return $candidate;
				}
			}
		}
		return null;
	}

	private function claimHasValue( Item $item, string $propertyId, string $value ): bool {
		$property = new PropertyId( $propertyId );
		if ( !$item->getStatements()->hasProperty( $property ) ) {
			return false;
		}
		foreach ( $item->getStatements()->getByPropertyId( $property )->getIterator() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			$dataValue = $snak->getDataValue();
			if ( $dataValue instanceof StringValue
				&& strcasecmp( trim( $dataValue->getValue() ), trim( $value ) ) === 0
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string[]
	 */
	private function agentClassIds(): array {
		return array_values( $this->config->agentClasses() );
	}

	/**
	 * Common authority-label variants: a leading "The" and a trailing
	 * parenthetical ("American Physical Society (APS)") are frequent enough
	 * that the exact label should not be the only search.
	 *
	 * @return string[]
	 */
	private function labelVariants( string $label ): array {
		$variants = [ $label ];
		if ( stripos( $label, 'The ' ) === 0 ) {
			$variants[] = substr( $label, 4 );
		}
		$parenthetical = strpos( $label, ' (' );
		if ( $parenthetical !== false ) {
			$variants[] = substr( $label, 0, $parenthetical );
		}
		return array_values( array_unique( array_filter(
			array_map( 'trim', $variants ),
			static fn ( string $value ): bool => $value !== ''
		) ) );
	}

	/**
	 * @return string[]
	 */
	private function scopedClass( ?string $classId ): array {
		return $classId === null || $classId === '' ? [] : [ $classId ];
	}

	/**
	 * @param string[] ...$scopes
	 * @return string[]
	 */
	private function mergeScopes( array ...$scopes ): array {
		$out = [];
		foreach ( $scopes as $scope ) {
			foreach ( $scope as $id ) {
				if ( is_string( $id ) && $id !== '' && !in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
		}
		return $out;
	}
}
