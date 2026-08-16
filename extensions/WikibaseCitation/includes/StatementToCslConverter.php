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
 * @license GPL-2.0-or-later
 */
class StatementToCslConverter {

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
		$classIds = $this->classIdsOf( $item );
		$csl = [ 'type' => $this->typeMapper->getTypeForClasses( $classIds ) ];

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
		$this->addFromStatement( $item, 'container-title', static function ( $value ) {
			return (string)$value;
		}, $csl, 'container-title' );
		$this->addFromStatement( $item, 'issued', static function ( $value ) {
			return $value instanceof TimeValue
				? [ 'date-parts' => [ [ (int)substr( ltrim( $value->getTime(), '+' ), 0, 4 ) ] ] ]
				: [ 'literal' => (string)$value ];
		}, $csl, 'issued' );
		$this->addFromStatement( $item, 'URL', static function ( $value ) {
			return (string)$value;
		}, $csl, 'URL' );

		return $csl;
	}

	/**
	 * Resolves the mapped property's best-ranked claim and adds the value
	 * (transformed by $transform) to $csl under $field.
	 *
	 * @param callable $transform callable(mixed $value): mixed
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

		// Author/container values are items; fall back to their labels.
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
