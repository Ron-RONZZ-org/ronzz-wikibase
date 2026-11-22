<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Store;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\PropertyIdFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\Snak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Repo\WikibaseRepo;
use DataValues\StringValue;

/**
 * Writes the dataset and per-table Wikibase items for an applied manifest.
 *
 * Idempotent: the dataset item is found by its `Dataset:<slug>` sitelink; the
 * table items by their `table id` statements on the dataset item. Re-apply
 * updates the same items. The vocabulary ids come from the seed config map;
 * a missing id is a deployment error (the seed must be re-run), not a silent
 * skip.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetItemWriter {

	private EmbeddableContentConfig $config;

	public function __construct( EmbeddableContentConfig $config ) {
		$this->config = $config;
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @return array{datasetItem:string,tableItems:array<string,string>}
	 */
	public function apply( array $manifest, UserIdentity $user ): array {
		$dataset = $manifest['dataset'];
		$datasetId = (string)$dataset['dataset_id'];
		$pageTitle = Title::newFromText( 'Dataset:' . $datasetId );
		if ( $pageTitle === null ) {
			throw new RuntimeException( "invalid Dataset: title for $datasetId" );
		}
		$instanceOf = $this->config->instanceOfPropertyId();
		$datasetClass = $this->required( $this->config->datasetClasses(), 'dataset' );
		$dataTableClass = $this->required( $this->config->datasetClasses(), 'dataTable' );
		$props = $this->config->datasetPropertyIds();
		$hasDataTable = $this->required( $props, 'hasDataTable' );
		$extractedFrom = $this->required( $props, 'extractedFrom' );
		$tableNumberProp = $this->required( $props, 'tableNumber' );
		$tableIdProp = $this->required( $props, 'tableId' );
		$datasetIdProp = $this->required( $props, 'datasetId' );
		$sourceProp = $this->config->provenancePropertyIds()['source'] ?? null;
		$sourceItem = $this->itemId( (string)( $dataset['source_item'] ?? '' ) );

		$datasetItem = $this->datasetItem( $pageTitle );
		$isNew = $datasetItem->getId() === null;
		$this->clearProperty( $datasetItem, $instanceOf );
		$this->clearProperty( $datasetItem, $datasetIdProp );
		$this->clearProperty( $datasetItem, $hasDataTable );
		if ( $sourceProp !== null ) {
			$this->clearProperty( $datasetItem, $sourceProp );
		}
		$this->setTerms( $datasetItem, (string)$dataset['title'], (string)( $dataset['description'] ?? '' ) );
		$this->addItemStatement( $datasetItem, $instanceOf, $datasetClass );
		$this->addStringStatement( $datasetItem, $datasetIdProp, $datasetId );
		if ( $sourceProp !== null && $sourceItem !== null ) {
			$this->addItemStatement( $datasetItem, $sourceProp, $sourceItem->getSerialization() );
		}
		$datasetItem->getSiteLinkList()->setNewSiteLink( 'wikibase', $pageTitle->getPrefixedText() );

		$existingTables = $this->existingTableItems( $datasetItem, $hasDataTable, $tableIdProp );
		$tableItems = [];
		foreach ( $manifest['tables'] as $table ) {
			$tableId = (string)$table['table_id'];
			$tableItem = $existingTables[$tableId] ?? new Item();
			$tableIsNew = $tableItem->getId() === null;
			foreach ( [ $instanceOf, $tableIdProp, $tableNumberProp, $extractedFrom,
				$props['reviewStatus'] ?? null, $props['extractionMethod'] ?? null ] as $property ) {
				if ( is_string( $property ) && $property !== '' ) {
					$this->clearProperty( $tableItem, $property );
				}
			}
			$this->setTerms( $tableItem, $this->label( (string)$table['caption'], 250 ),
				$this->description( $table ) );
			$this->addItemStatement( $tableItem, $instanceOf, $dataTableClass );
			$this->addStringStatement( $tableItem, $tableIdProp, $tableId );
			$this->addStringStatement( $tableItem, $tableNumberProp, (string)$table['table_number'] );
			if ( $sourceItem !== null ) {
				$this->addItemStatement( $tableItem, $extractedFrom, $sourceItem->getSerialization() );
			}
			$this->addVocabularyStatement( $tableItem, $props, 'reviewStatus',
				$this->config->datasetReviewStatusItems(), (string)( $table['review_status'] ?? 'unreviewed' ) );
			$this->addVocabularyStatement( $tableItem, $props, 'extractionMethod',
				$this->config->datasetExtractionMethodItems(), (string)( $table['method'] ?? 'manual' ) );
			$this->save( $tableItem, $tableIsNew, $user, $tableId );
			$tableItems[$tableId] = $tableItem->getId()->getSerialization();
			$datasetItem->getStatements()->addNewStatement(
				new PropertyValueSnak(
					PropertyIdFactory::create( $hasDataTable ),
					new EntityIdValue( new ItemId( $tableItems[$tableId] ) )
				)
			);
		}
		$this->save( $datasetItem, $isNew, $user, $datasetId );
		WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $datasetItem );
		return [
			'datasetItem' => (string)$datasetItem->getId()->getSerialization(),
			'tableItems' => $tableItems,
		];
	}

	private function datasetItem( Title $pageTitle ): Item {
		$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( 'wikibase', $pageTitle->getPrefixedText() );
		if ( $itemId !== null ) {
			$item = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
			if ( $item instanceof Item ) {
				return $item;
			}
		}
		return new Item();
	}

	/**
	 * @return array<string,Item> table id => item
	 */
	private function existingTableItems( Item $datasetItem, string $hasDataTable, string $tableIdProp ): array {
		$out = [];
		foreach ( $datasetItem->getStatements()->getByPropertyId( PropertyIdFactory::create( $hasDataTable ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( !$value instanceof EntityIdValue || !$value->getEntityId() instanceof ItemId ) {
				continue;
			}
			$item = WikibaseRepo::getEntityLookup()->getEntity( $value->getEntityId() );
			if ( !$item instanceof Item ) {
				continue;
			}
			$id = $this->stringStatement( $item, $tableIdProp );
			if ( $id !== null ) {
				$out[$id] = $item;
			}
		}
		return $out;
	}

	private function stringStatement( Item $item, string $propertyId ): ?string {
		foreach ( $item->getStatements()->getByPropertyId( PropertyIdFactory::create( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				return (string)$snak->getDataValue()->getValue();
			}
		}
		return null;
	}

	private function setTerms( Item $item, string $label, string $description ): void {
		$item->setLabel( 'en', $this->label( $label, 250 ) );
		if ( $description !== '' ) {
			$item->setDescription( 'en', $this->label( $description, 250 ) );
		}
	}

	private function clearProperty( Item $item, string $propertyId ): void {
		foreach ( $item->getStatements()->getByPropertyId( PropertyIdFactory::create( $propertyId ) ) as $statement ) {
			$item->getStatements()->removeStatementsWithGuid( $statement->getGuid() );
		}
	}

	private function addItemStatement( Item $item, string $propertyId, string $itemId ): void {
		$id = $this->itemId( $itemId );
		if ( $id === null ) {
			return;
		}
		$item->getStatements()->addNewStatement(
			new PropertyValueSnak( PropertyIdFactory::create( $propertyId ), new EntityIdValue( $id ) )
		);
	}

	private function addStringStatement( Item $item, string $propertyId, string $value ): void {
		if ( $value === '' ) {
			return;
		}
		$item->getStatements()->addNewStatement(
			new PropertyValueSnak( PropertyIdFactory::create( $propertyId ), new StringValue( $value ) )
		);
	}

	/**
	 * @param array<string,string> $props
	 * @param array<string,string> $items
	 */
	private function addVocabularyStatement(
		Item $item,
		array $props,
		string $propKey,
		array $items,
		string $value
	): void {
		if ( !isset( $props[$propKey], $items[$value] ) ) {
			return;
		}
		$this->addItemStatement( $item, $props[$propKey], $items[$value] );
	}

	private function save( Item $item, bool $isNew, UserIdentity $user, string $label ): void {
		WikibaseRepo::getEntityStore()->saveEntity(
			$item,
			'dataset ' . ( $isNew ? 'create' : 'update' ) . ': ' . $label,
			$user,
			$isNew ? EDIT_NEW : EDIT_UPDATE
		);
	}

	private function itemId( string $value ): ?ItemId {
		if ( !preg_match( '/^Q[0-9]+$/', $value ) ) {
			return null;
		}
		return new ItemId( $value );
	}

	private function label( string $value, int $max ): string {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		return mb_substr( $value === '' ? 'Dataset' : $value, 0, $max );
	}

	private function description( array $table ): string {
		$page = (string)( $table['page'] ?? '' );
		return $page === '' ? 'extracted data table' : "extracted data table, page $page";
	}

	/**
	 * @param array<string,string> $map
	 */
	private function required( array $map, string $key ): string {
		if ( !isset( $map[$key] ) || $map[$key] === '' ) {
			throw new RuntimeException(
				"dataset vocabulary is not seeded: missing $key (run the seed and re-emit the config map)"
			);
		}
		return $map[$key];
	}
}
