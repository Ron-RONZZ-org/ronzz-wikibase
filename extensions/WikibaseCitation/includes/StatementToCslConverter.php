<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use DataValues\StringValue;
use DataValues\TimeValue;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;

/**
 * Converts a content item's statements into CSL-JSON using the citation
 * property map (CSL field => local property id). Deterministic; missing
 * fields are omitted, never fatal (issue #6 §7).
 *
 * Issue #7 additions:
 *  - CSL type follows the SOURCE class (content-class × source-class aware)
 *  - source-level fields (container-title/publisher/page/volume/issue/DOI/
 *    ISBN) are read from the `source` item via the source property map;
 *    container-title falls back to the source item's label (books)
 *
 * @license GPL-2.0-or-later
 */
class StatementToCslConverter {

	/** Source-level CSL fields resolved against the source item. */
	private const SOURCE_LEVEL_FIELDS = [
		'container-title', 'publisher', 'page', 'volume', 'issue', 'DOI', 'ISBN',
	];

	/** @var EntityLookup */
	private $entityLookup;

	/** @var CitationMapLookup */
	private $propertyMap;

	/** @var CslTypeMapper */
	private $typeMapper;

	/** @var string|null instance-of property id (from extension config) */
	private $instanceOfPropertyId;

	public function __construct(
		EntityLookup $entityLookup,
		CitationMapLookup $propertyMap,
		CslTypeMapper $typeMapper,
		?string $instanceOfPropertyId = null
	) {
		$this->entityLookup = $entityLookup;
		$this->propertyMap = $propertyMap;
		$this->typeMapper = $typeMapper;
		$this->instanceOfPropertyId = $instanceOfPropertyId;
	}

	/**
	 * @return array<string,mixed> CSL-JSON
	 */
	public function toCslJson( Item $item ): array {
		$sourceItem = $this->sourceItemOf( $item );
		$contentClassIds = $this->classIdsOf( $item );
		$sourceClassIds = $sourceItem !== null ? $this->classIdsOf( $sourceItem ) : [];

		$csl = [
			'type' => $this->typeMapper->getTypeForContentAndSource( $contentClassIds, $sourceClassIds ),
		];

		$label = $this->labelOf( $item );
		if ( $label !== '' ) {
			$csl['title'] = $label;
		}

		$this->addFromStatement( $item, 'author', static function ( $value ) {
			// citeproc-php only renders family/given names; split the label
			// deterministically (last word = family). Single-word names fall
			// back to a literal name.
			$name = (string)$value;
			if ( preg_match( '/^(.*?)\s+(\S+)$/u', $name, $m ) === 1 ) {
				return [ [ 'given' => $m[1], 'family' => $m[2] ] ];
			}
			return [ [ 'literal' => $name ] ];
		}, $csl, 'author' );
		$this->addFromStatement( $item, 'issued', static function ( $value ) {
			return $value instanceof TimeValue
				? [ 'date-parts' => [ [ (int)substr( ltrim( $value->getTime(), '+' ), 0, 4 ) ] ] ]
				: [ 'literal' => (string)$value ];
		}, $csl, 'issued' );
		$this->addFromStatement( $item, 'URL', static function ( $value ) {
			return (string)$value;
		}, $csl, 'URL' );

		// Issue #7: source-level fields from the source item.
		foreach ( self::SOURCE_LEVEL_FIELDS as $field ) {
			$this->addFromSource( $sourceItem, $field, $csl );
		}
		if ( $sourceItem !== null && !isset( $csl['container-title'] ) ) {
			// Books: the container IS the source item (label fallback).
			$sourceLabel = $this->labelOf( $sourceItem );
			if ( $sourceLabel !== '' ) {
				$csl['container-title'] = $sourceLabel;
			}
		}

		return $csl;
	}

	/**
	 * Resolves the `source` statement value to its item, or null.
	 */
	private function sourceItemOf( Item $item ): ?Item {
		$sourcePropertyId = $this->propertyMap->getPropertyForField( 'container-title' );
		if ( $sourcePropertyId === null ) {
			return null;
		}
		$statement = $this->bestStatement( $item, $sourcePropertyId );
		if ( $statement === null ) {
			return null;
		}
		$snak = $statement->getMainSnak();
		if ( !$snak instanceof PropertyValueSnak ) {
			return null;
		}
		$value = $snak->getDataValue();
		if ( $value instanceof EntityIdValue ) {
			$value = $value->getEntityId();
		}
		if ( !$value instanceof ItemId ) {
			return null;
		}
		$target = $this->entityLookup->getEntity( $value );
		return $target instanceof Item ? $target : null;
	}

	/**
	 * Reads a source-level CSL field from the source item via the source
	 * property map; item-typed values fall back to their labels.
	 *
	 * @param array<string,mixed> $csl
	 */
	private function addFromSource( ?Item $sourceItem, string $field, array &$csl ): void {
		if ( $sourceItem === null ) {
			return;
		}
		$propertyId = $this->propertyMap->getSourcePropertyForField( $field );
		if ( $propertyId === null ) {
			return;
		}
		$statement = $this->bestStatement( $sourceItem, $propertyId );
		if ( $statement === null ) {
			return;
		}
		$snak = $statement->getMainSnak();
		if ( !$snak instanceof PropertyValueSnak ) {
			return;
		}

		$value = $snak->getDataValue();
		if ( $value instanceof EntityIdValue ) {
			$value = $value->getEntityId();
		}
		if ( $value instanceof ItemId ) {
			$target = $this->entityLookup->getEntity( $value );
			$value = $target instanceof Item ? $this->labelOf( $target ) : $value->getSerialization();
		} elseif ( $value instanceof StringValue ) {
			$value = $value->getValue();
		}

		if ( $value === null || $value === '' ) {
			return;
		}
		$csl[$field] = $value;
	}

	/**
	 * Resolves the mapped property's best-ranked claim and adds the value
	 * (transformed by $transform) to $csl under $field.
	 *
	 * @param callable $transform callable(mixed $value): mixed
	 * @param array<string,mixed> $csl
	 */
	private function addFromStatement(
		Item $item,
		string $field,
		callable $transform,
		array &$csl,
		string $cslKey
	): void {
		$propertyId = $this->propertyMap->getPropertyForField( $field );
		if ( $propertyId === null ) {
			return;
		}

		$statement = $this->bestStatement( $item, $propertyId );
		if ( $statement === null ) {
			return;
		}
		$snak = $statement->getMainSnak();
		if ( !$snak instanceof PropertyValueSnak ) {
			return;
		}

		$value = $snak->getDataValue();
		if ( $value instanceof EntityIdValue ) {
			$value = $value->getEntityId();
		}

		// Author values are items; fall back to their labels.
		if ( $value instanceof ItemId ) {
			$target = $this->entityLookup->getEntity( $value );
			$value = $target instanceof Item ? $this->labelOf( $target ) : $value->getSerialization();
		} elseif ( $value instanceof StringValue ) {
			$value = $value->getValue();
		}

		if ( $value === null || $value === '' ) {
			return;
		}
		$csl[$cslKey] = $transform( $value );
	}

	/**
	 * @return string[] all instance-of class ids (rank-ordered)
	 */
	private function classIdsOf( Item $item ): array {
		if ( $this->instanceOfPropertyId === null ) {
			return [];
		}
		$classIds = [];
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			if ( $snak->getPropertyId()->getSerialization() !== $this->instanceOfPropertyId ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$value = $value->getEntityId();
			}
			if ( $value instanceof ItemId ) {
				$classIds[] = $value->getSerialization();
			}
		}
		return $classIds;
	}

	/**
	 * Picks the best-ranked statement for a property: preferred > normal,
	 * deprecated statements are skipped (issue #6 §7).
	 */
	private function bestStatement( Item $item, string $propertyId ): ?Statement {
		$best = null;
		$bestRank = null;
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			if ( $snak->getPropertyId()->getSerialization() !== $propertyId ) {
				continue;
			}
			$rank = $statement->getRank();
			if ( $rank === Statement::RANK_DEPRECATED ) {
				continue;
			}
			if ( $best === null || ( $rank === Statement::RANK_PREFERRED && $bestRank !== Statement::RANK_PREFERRED ) ) {
				$best = $statement;
				$bestRank = $rank;
			}
		}
		return $best;
	}

	private function labelOf( Item $item ): string {
		$labels = $item->getFingerprint()->getLabels()->toTextArray();
		foreach ( [ 'fr', 'en', 'eo' ] as $preferred ) {
			if ( isset( $labels[$preferred] ) ) {
				return $labels[$preferred];
			}
		}
		return $labels === [] ? '' : reset( $labels );
	}
}
