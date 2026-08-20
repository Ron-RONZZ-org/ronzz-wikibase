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
 * Issue #24 (cite-by-QID) addition: self-cite — when the cited item itself
 * belongs to a configured source class (book / scholarly article / …) and
 * carries no `source` statement, it is treated as its own source, so the
 * source-level fields are read from the item itself (citing a source item
 * directly no longer omits publisher / page(s) / volume / issue / DOI /
 * ISBN).
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

	/** @var string[] source class item ids (from extension config, default []) */
	private $sourceClasses;

	/**
	 * @param string[] $sourceClasses Class item ids that are sources themselves
	 *  (self-cite, issue #24). Default [] disables the self-cite behaviour.
	 */
	public function __construct(
		EntityLookup $entityLookup,
		CitationMapLookup $propertyMap,
		CslTypeMapper $typeMapper,
		?string $instanceOfPropertyId = null,
		array $sourceClasses = []
	) {
		$this->entityLookup = $entityLookup;
		$this->propertyMap = $propertyMap;
		$this->typeMapper = $typeMapper;
		$this->instanceOfPropertyId = $instanceOfPropertyId;
		$this->sourceClasses = $sourceClasses;
	}

	/**
	 * Default label-language order when no preferred language is given
	 * (legacy behaviour, kept for API callers without a language context).
	 */
	private const DEFAULT_LABEL_LANGUAGES = [ 'fr', 'en', 'eo' ];

	/**
	 * @param string|null $preferredLanguage language code of the reader /
	 *  render context (user language for parser functions, request language
	 *  for the API). Labels are resolved in that language first, then the
	 *  default order, then any remaining label. Null keeps the legacy
	 *  fr-first order.
	 *
	 * @return array<string,mixed> CSL-JSON
	 */
	public function toCslJson( Item $item, ?string $preferredLanguage = null ): array {
		$sourceItem = $this->sourceItemOf( $item );
		if ( $sourceItem === null && $this->isSourceClass( $item ) ) {
			// Issue #24: a source-class item cited directly is its own source —
			// otherwise the source-level fields below would all be omitted.
			$sourceItem = $item;
		}
		$contentClassIds = $this->classIdsOf( $item );
		$sourceClassIds = $sourceItem !== null ? $this->classIdsOf( $sourceItem ) : [];

		$csl = [
			'type' => $this->typeMapper->getTypeForContentAndSource( $contentClassIds, $sourceClassIds ),
		];

		$label = $this->labelOf( $item, $preferredLanguage );
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
		}, $csl, 'author', $preferredLanguage );
		$this->addFromStatement( $item, 'issued', static function ( $value ) {
			return $value instanceof TimeValue
				? [ 'date-parts' => [ [ (int)substr( ltrim( $value->getTime(), '+' ), 0, 4 ) ] ] ]
				: [ 'literal' => (string)$value ];
		}, $csl, 'issued', $preferredLanguage );
		$this->addFromStatement( $item, 'URL', static function ( $value ) {
			return (string)$value;
		}, $csl, 'URL', $preferredLanguage );

		// Issue #7: source-level fields from the source item.
		foreach ( self::SOURCE_LEVEL_FIELDS as $field ) {
			$this->addFromSource( $sourceItem, $field, $csl, $preferredLanguage );
		}
		if ( $sourceItem !== null && !isset( $csl['container-title'] ) ) {
			// Books: the container IS the source item (label fallback).
			$sourceLabel = $this->labelOf( $sourceItem, $preferredLanguage );
			if ( $sourceLabel !== '' ) {
				$csl['container-title'] = $sourceLabel;
			}
		}

		// Issue #25 (v2): quote-position locator — a `page(s)` QUALIFIER on
		// the content item's `source` statement is the exact quote location
		// and overrides the work-level page range read from the source item.
		$this->addSourceStatementQualifier( $item, $csl, $preferredLanguage );

		return $csl;
	}

	/**
	 * Reads the `page(s)` qualifier of the content item's `source`
	 * statement into the CSL `page` locator (issue #25 v2). Item-typed
	 * qualifier values fall back to their labels; empty values are omitted.
	 *
	 * @param array<string,mixed> $csl
	 */
	private function addSourceStatementQualifier( Item $item, array &$csl, ?string $preferredLanguage ): void {
		$pagePropertyId = $this->propertyMap->getSourcePropertyForField( 'page' );
		$statement = $this->sourceStatement( $item );
		if ( $pagePropertyId === null || $statement === null ) {
			return;
		}
		foreach ( $statement->getQualifiers() as $snak ) {
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			if ( $snak->getPropertyId()->getSerialization() !== $pagePropertyId ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$value = $value->getEntityId();
			}
			if ( $value instanceof ItemId ) {
				$target = $this->entityLookup->getEntity( $value );
				$value = $target instanceof Item ? $this->labelOf( $target, $preferredLanguage ) : $value->getSerialization();
			} elseif ( $value instanceof StringValue ) {
				$value = $value->getValue();
			}
			if ( $value === null || $value === '' ) {
				continue;
			}
			// The qualifier is the quote-level locator: it wins over the
			// work-level page range already read from the source item.
			$csl['page'] = $value;
			return;
		}
	}

	/**
	 * Resolves the `source` statement value to its item, or null. Public so
	 * the CitationEngine can reuse it for the {{#citations:}} bibliography
	 * accumulation (issue #24).
	 */
	public function sourceItemOf( Item $item ): ?Item {
		$statement = $this->sourceStatement( $item );
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
	 * The best-ranked `source` statement of a content item, or null (the
	 * `source` property id is the map's `container-title` entry, issue #6 §7).
	 */
	private function sourceStatement( Item $item ): ?Statement {
		$sourcePropertyId = $this->propertyMap->getPropertyForField( 'container-title' );
		if ( $sourcePropertyId === null ) {
			return null;
		}
		return $this->bestStatement( $item, $sourcePropertyId );
	}

	/**
	 * Reads a source-level CSL field from the source item via the source
	 * property map; item-typed values fall back to their labels.
	 *
	 * @param array<string,mixed> $csl
	 */
	private function addFromSource( ?Item $sourceItem, string $field, array &$csl, ?string $preferredLanguage ): void {
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
			$value = $target instanceof Item ? $this->labelOf( $target, $preferredLanguage ) : $value->getSerialization();
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
		string $cslKey,
		?string $preferredLanguage
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
			$value = $target instanceof Item ? $this->labelOf( $target, $preferredLanguage ) : $value->getSerialization();
		} elseif ( $value instanceof StringValue ) {
			$value = $value->getValue();
		}

		if ( $value === null || $value === '' ) {
			return;
		}
		$csl[$cslKey] = $transform( $value );
	}

	/**
	 * True when the item belongs to a configured source class (issue #24
	 * self-cite). Class membership is checked against the injected config
	 * list; an empty list disables the behaviour.
	 */
	public function isSourceClass( Item $item ): bool {
		if ( $this->sourceClasses === [] ) {
			return false;
		}
		$sourceClasses = array_flip( $this->sourceClasses );
		foreach ( $this->classIdsOf( $item ) as $classId ) {
			if ( isset( $sourceClasses[$classId] ) ) {
				return true;
			}
		}
		return false;
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

	private function labelOf( Item $item, ?string $preferredLanguage ): string {
		$labels = $item->getFingerprint()->getLabels()->toTextArray();
		if ( $preferredLanguage !== null && $preferredLanguage !== '' ) {
			if ( isset( $labels[$preferredLanguage] ) ) {
				return $labels[$preferredLanguage];
			}
		}
		foreach ( self::DEFAULT_LABEL_LANGUAGES as $code ) {
			if ( $code === $preferredLanguage ) {
				continue;
			}
			if ( isset( $labels[$code] ) ) {
				return $labels[$code];
			}
		}
		return $labels === [] ? '' : reset( $labels );
	}
}
