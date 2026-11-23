<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use DataValues\StringValue;
use EmbeddableContent\PropertyIdFactory;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;

/**
 * Resolves a legal item to its Special:Law view.
 *
 * Legal laws and provisions are entity-only (no Source: page). The citation
 * footnote therefore links to Special:Law/<law_key>[/<reference>] instead of
 * a classic page. A provision's law key comes from its `part of` target's
 * `legislation number`; the reference from its own `reference code`.
 *
 * Returns null for non-legal items (the caller falls back to the classic
 * Source: page resolver) and for legal cases (the v1 law view covers
 * legislation/provisions only).
 *
 * @license GPL-2.0-or-later
 */
final class LegalItemLink {

	private EntityLookup $entityLookup;

	private string $instanceOfPropertyId;

	/** @var string[] */
	private array $legislationClasses;

	private ?string $lawClassId;

	private ?string $partOfPropertyId;

	private ?string $legislationNumberPropertyId;

	private ?string $legalIdentifierPropertyId;

	private ?string $referenceCodePropertyId;

	/**
	 * @param string[] $legislationClasses class ids that are a law themselves
	 */
	public function __construct(
		EntityLookup $entityLookup,
		string $instanceOfPropertyId,
		array $legislationClasses,
		?string $lawClassId,
		?string $partOfPropertyId,
		?string $legislationNumberPropertyId,
		?string $referenceCodePropertyId,
		?string $legalIdentifierPropertyId = null
	) {
		$this->entityLookup = $entityLookup;
		$this->instanceOfPropertyId = $instanceOfPropertyId;
		$this->legislationClasses = $legislationClasses;
		$this->lawClassId = $lawClassId;
		$this->partOfPropertyId = $partOfPropertyId;
		$this->legislationNumberPropertyId = $legislationNumberPropertyId;
		$this->referenceCodePropertyId = $referenceCodePropertyId;
		$this->legalIdentifierPropertyId = $legalIdentifierPropertyId;
	}

	public function urlFor( ItemId $itemId ): ?string {
		$item = $this->entityLookup->getEntity( $itemId );
		if ( !$item instanceof Item ) {
			return null;
		}
		$classes = $this->classIdsOf( $item );

		if ( $this->isLaw( $item, $classes ) ) {
			$lawKey = $this->stringOf( $item, $this->legalIdentifierPropertyId )
				?? $this->stringOf( $item, $this->legislationNumberPropertyId );
			return $lawKey !== null ? $this->lawUrl( $lawKey ) : null;
		}
		if ( $this->isProvision( $classes ) ) {
			$reference = $this->stringOf( $item, $this->referenceCodePropertyId );
			$parent = $this->itemOf( $item, $this->partOfPropertyId );
			$lawKey = $parent !== null
				? ( $this->stringOf( $parent, $this->legalIdentifierPropertyId )
					?? $this->stringOf( $parent, $this->legislationNumberPropertyId ) )
				: null;
			if ( $reference !== null && $lawKey !== null ) {
				$reference = (string)preg_replace( '/[\s_]+/u', '', $reference );
				return $this->lawUrl( $lawKey, $reference );
			}
		}
		return null;
	}

	private function lawUrl( string $lawKey, ?string $reference = null ): string {
		$subPage = $lawKey;
		if ( $reference !== null && $reference !== '' ) {
			$subPage .= '/' . $reference;
		}
		return SpecialPage::getTitleFor( 'Law', $subPage )->getFullURL();
	}

	/**
	 * @param string[] $classes
	 */
	private function isLaw( Item $item, array $classes ): bool {
		foreach ( $classes as $classId ) {
			if ( in_array( $classId, $this->legislationClasses, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $classes
	 */
	private function isProvision( array $classes ): bool {
		return $this->lawClassId !== null && in_array( $this->lawClassId, $classes, true );
	}

	/**
	 * @return string[]
	 */
	private function classIdsOf( Item $item ): array {
		$ids = [];
		foreach ( $item->getStatements()->getByPropertyId( PropertyIdFactory::create( $this->instanceOfPropertyId ) ) as $statement ) {
			$value = $this->snakValue( $statement );
			if ( $value instanceof ItemId ) {
				$ids[] = $value->getSerialization();
			}
		}
		return $ids;
	}

	private function stringOf( Item $item, ?string $propertyId ): ?string {
		if ( $propertyId === null ) {
			return null;
		}
		foreach ( $item->getStatements()->getByPropertyId( PropertyIdFactory::create( $propertyId ) ) as $statement ) {
			$value = $this->snakValue( $statement );
			if ( $value instanceof StringValue && trim( $value->getValue() ) !== '' ) {
				return trim( $value->getValue() );
			}
		}
		return null;
	}

	private function itemOf( Item $item, ?string $propertyId ): ?Item {
		if ( $propertyId === null ) {
			return null;
		}
		foreach ( $item->getStatements()->getByPropertyId( PropertyIdFactory::create( $propertyId ) ) as $statement ) {
			$value = $this->snakValue( $statement );
			if ( $value instanceof ItemId ) {
				$target = $this->entityLookup->getEntity( $value );
				if ( $target instanceof Item ) {
					return $target;
				}
			}
		}
		return null;
	}

	private function snakValue( Statement $statement ) {
		if ( $statement->getRank() === Statement::RANK_DEPRECATED ) {
			return null;
		}
		$snak = $statement->getMainSnak();
		if ( !$snak instanceof PropertyValueSnak ) {
			return null;
		}
		$value = $snak->getDataValue();
		return $value instanceof EntityIdValue ? $value->getEntityId() : $value;
	}
}
