<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Search\EntityTermSearch;
use EmbeddableContent\Spec\AuthorNameList;
use EmbeddableContent\Spec\AuthorNameMatcher;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * Resolves parsed author names against the local wiki.
 *
 * The resolver is READ-ONLY: it searches Person-class items, scores the
 * candidates with the pure AuthorNameMatcher, and returns them for explicit
 * human confirmation. It never marks a match as accepted — common names
 * ("John Smith") make that unsafe.
 *
 * It also detects the legacy "author list stored as a Group-of-humans
 * label" pattern: a group-of-humans candidate whose own label parses into
 * two or more person names is returned as a `collective` suggestion so the
 * caller can offer to split it into individual Persons.
 *
 * @license GPL-2.0-or-later
 */
final class AuthorResolver {

	/** @var callable(string): ?Item */
	private $entityLookup;

	public function __construct(
		private readonly EntityTermSearch $search,
		private readonly EmbeddableContentConfig $config,
		?callable $entityLookup = null
	) {
		$this->entityLookup = $entityLookup ?? static function ( string $id ): ?Item {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $id ) );
			return $entity instanceof Item ? $entity : null;
		};
	}

	/**
	 * @param string $rawInput the original pasted name string (group detection)
	 * @param array<int,array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>}> $names
	 * @return array{
	 *   names: list<array<string,mixed>>,
	 *   collectives: list<array<string,mixed>>,
	 *   warnings: list<array{code:string,message:string,name:string}>
	 * }
	 */
	public function resolveNames( string $rawInput, array $names, string $language = 'en', int $limit = 8 ): array {
		$personClass = $this->config->agentClasses()['person'] ?? null;
		$personScope = $personClass !== null ? [ $personClass ] : [];
		$resolved = [];
		foreach ( $names as $name ) {
			$candidates = $this->candidatesFor( $name, $personScope, $language, $limit );
			$resolved[] = [
				'original' => $name['original'],
				'givenName' => $name['givenName'],
				'familyName' => $name['familyName'],
				'familyFirst' => $name['familyFirst'],
				'flags' => $name['flags'],
				// The client decides; always 'candidates' or 'missing'.
				'status' => $candidates === [] ? 'missing' : 'candidates',
				'candidates' => $candidates,
				'collective' => null,
			];
		}

		[ $collectives, $byName ] = $this->collectiveSuggestions( $rawInput, $names, $language );
		foreach ( $resolved as $i => $row ) {
			if ( isset( $byName[$i] ) ) {
				$resolved[$i]['collective'] = $byName[$i];
			}
		}

		return [
			'names' => $resolved,
			'collectives' => $collectives,
			'warnings' => [],
		];
	}

	/**
	 * @param array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>} $name
	 * @param string[] $personScope
	 * @return list<array<string,mixed>>
	 */
	private function candidatesFor( array $name, array $personScope, string $language, int $limit ): array {
		$full = trim( $name['givenName'] . ' ' . $name['familyName'] );
		$terms = [ $full ];
		if ( $name['familyName'] !== '' && $name['familyName'] !== $full ) {
			$terms[] = $name['familyName'];
		}
		$entries = [];
		foreach ( $terms as $term ) {
			if ( $term === '' ) {
				continue;
			}
			foreach ( $this->search->search( $term, $language, $limit * 2, $personScope ) as $entry ) {
				$id = (string)( $entry['id'] ?? '' );
				if ( $id !== '' ) {
					$entries[$id] = $entry;
				}
			}
			if ( $entries !== [] ) {
				break;
			}
		}

		$candidates = [];
		foreach ( $entries as $id => $entry ) {
			$item = ( $this->entityLookup )( $id );
			if ( !$item instanceof Item ) {
				continue;
			}
			$label = (string)( $entry['label'] ?? $this->itemLabel( $item ) );
			if ( $label === '' ) {
				continue;
			}
			$score = AuthorNameMatcher::score( $name, $label );
			if ( $score['score'] < AuthorNameMatcher::GOOD_MATCH_THRESHOLD ) {
				continue;
			}
			$candidates[] = [
				'qid' => $id,
				'label' => $label,
				'description' => (string)( $entry['description'] ?? '' ),
				'score' => $score['score'],
				'reason' => $score['reason'],
				'authority' => $this->authorityFacts( $item ),
			];
		}
		usort(
			$candidates,
			static fn ( array $a, array $b ): int => $b['score'] <=> $a['score']
		);
		return array_slice( $candidates, 0, $limit );
	}

	/**
	 * Finds group-of-humans items whose label is itself a person-name list.
	 * Returns the group list plus a per-input-name suggestion (index keyed).
	 *
	 * @param array<int,array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>}> $names
	 * @return array{0: list<array<string,mixed>>, 1: array<int,array<string,mixed>>}
	 */
	private function collectiveSuggestions( string $rawInput, array $names, string $language ): array {
		$groupClass = $this->config->agentClasses()['groupOfHumans'] ?? null;
		if ( $groupClass === null ) {
			return [ [], [] ];
		}
		$terms = [];
		if ( trim( $rawInput ) !== '' ) {
			$terms[] = trim( $rawInput );
		}
		foreach ( $names as $name ) {
			if ( $name['familyName'] !== '' ) {
				$terms[] = $name['familyName'];
			}
		}
		$terms = array_values( array_unique( array_filter( $terms ) ) );

		$groups = [];
		foreach ( $terms as $term ) {
			foreach ( $this->search->search( $term, $language, 8, [ $groupClass ] ) as $entry ) {
				$id = (string)( $entry['id'] ?? '' );
				if ( $id === '' || isset( $groups[$id] ) ) {
					continue;
				}
				$item = ( $this->entityLookup )( $id );
				if ( !$item instanceof Item ) {
					continue;
				}
				$label = $this->itemLabel( $item );
				$parsed = AuthorNameList::parse( $label );
				if ( count( $parsed['names'] ) < 2 ) {
					continue;
				}
				$groups[$id] = [
					'qid' => $id,
					'label' => $label,
					'description' => (string)( $entry['description'] ?? '' ),
					'names' => array_map(
						static fn ( array $name ): string => trim(
							$name['givenName'] . ' ' . $name['familyName']
						),
						$parsed['names']
					),
				];
			}
		}

		$byName = [];
		foreach ( $names as $i => $name ) {
			foreach ( $groups as $group ) {
				foreach ( $group['names'] as $groupName ) {
					$score = AuthorNameMatcher::score( $name, $groupName );
					if ( $score['score'] >= 0.9 ) {
						$byName[$i] = $group;
						break 2;
					}
				}
			}
		}
		return [ array_values( $groups ), $byName ];
	}

	/**
	 * @return array<string,string> authority key => value
	 */
	private function authorityFacts( Item $item ): array {
		$ids = $this->config->externalIdPropertyIds();
		$keys = [
			'wikidata' => 'wikidata',
			'orcid' => 'orcid',
			'viaf' => 'viaf',
			'isni' => 'isni',
			'openalexAuthor' => 'openalexAuthor',
		];
		$facts = [];
		foreach ( $keys as $configKey => $outputKey ) {
			$propertyId = $ids[$configKey] ?? null;
			if ( $propertyId === null ) {
				continue;
			}
			$values = $this->stringValues( $item, $propertyId );
			if ( $values !== [] ) {
				$facts[$outputKey] = $values[0];
			}
		}
		return $facts;
	}

	/** @return string[] */
	private function stringValues( Item $item, string $propertyId ): array {
		$values = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				$values[] = (string)$snak->getDataValue()->getValue();
			}
		}
		return $values;
	}

	private function itemLabel( Item $item ): string {
		foreach ( [ 'en', 'fr', 'eo', 'mul' ] as $language ) {
			if ( $item->getLabels()->hasTermForLanguage( $language ) ) {
				return $item->getLabels()->getByLanguage( $language )->getText();
			}
		}
		$labels = $item->getLabels()->toTextArray();
		return $labels === [] ? '' : (string)reset( $labels );
	}

}
