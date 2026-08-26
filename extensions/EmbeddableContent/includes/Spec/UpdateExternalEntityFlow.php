<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Repo\WikibaseRepo;

/**
 * The Special:Update* flow — re-edit an EXISTING item with the exact same
 * form fields as its Special:Add* counterpart, prefilled from the item's
 * current statements, UPDATE (not create) on submit.
 *
 * Each Update* page extends its Add* page (inheriting reviewFieldSpecs,
 * statementSpecs, beforeCreate, classOptions, …) and mixes in this trait,
 * which owns:
 *  - the item-id subpage parsing (Special:UpdatePerson/Q42) + the
 *    prefilled review form rendering;
 *  - the submit: re-runs the Add* validation (beforeCreate) then replaces
 *    the managed statements (baseManagedPropertyIds ∪ the new specs) and
 *    updates the en label/description — everything else (sitelinks,
 *    non-managed statements) is untouched;
 *  - the classic-page rename when the label changed (best-effort move +
 *    sitelink update — a failure keeps the old page; the item is updated
 *    regardless).
 *
 * The reverse mapping (item statements → Add* record shape) is
 * recordFromItem(), implemented per kind; the statement-reader helpers
 * below are shared.
 *
 * @license GPL-2.0-or-later
 */
trait UpdateExternalEntityFlow {

	/** @var string|null the item id being edited (set by execute) */
	protected ?string $updateItemId = null;

	/** @var Item|null the item being edited (set by execute) */
	protected ?Item $updateItem = null;

	/** Kind key for the update-page i18n titles (e.g. 'person'). */
	abstract protected function updateKindKey(): string;

	/**
	 * Reverse-maps an item's statements onto the Add* review-record shape
	 * (the record keys the reviewFieldSpecs/statementSpecs expect).
	 */
	abstract protected function recordFromItem( Item $item ): array;

	/**
	 * Property ids whose statements the update REPLACES — the fields the
	 * form manages. Image facts (portrait/logo) are deliberately NOT in the
	 * base set: they are managed only when a NEW file is uploaded (their
	 * property ids then arrive via the new statementSpecs keys), so an
	 * untouched existing portrait/logo survives the update.
	 *
	 * @return string[]
	 */
	abstract protected function baseManagedPropertyIds(): array;

	/**
	 * The item's class among the Add* vocabulary (the hidden class field
	 * value on update — re-classification is out of scope).
	 */
	abstract protected function updateClassItemId( Item $item ): ?string;

	// ------------------------------------------------------------- page flow

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->enableOOUI();
		// Same modules as the Add* review/manual forms: entity combobox
		// autocomplete, the portrait/logo validate button, and the
		// entity-field confirmation banners.
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );
		$this->getOutput()->addModules( 'ext.embeddableContent.uploadmeta' );
		$this->getOutput()->addModules( 'ext.embeddableContent.entityconfirm' );

		$itemId = $this->itemIdFromSubPage( $subPage );
		if ( $itemId === null ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-update-badid' )->escaped() )
			);
			return;
		}
		$item = $this->loadItem( $itemId );
		if ( !$item instanceof Item ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-update-notfound', $itemId )->escaped() )
			);
			return;
		}
		$this->updateItemId = $itemId;
		$this->updateItem = $item;

		$classItemId = $this->updateClassItemId( $item );
		if ( $classItemId === null ) {
			// The item is not (anymore) of a class this Update page manages.
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-update-noclass', $itemId )->escaped() )
			);
			return;
		}

		$this->getOutput()->setPageTitle(
			$this->msg( 'embeddablecontent-update-' . $this->updateKindKey() . '-title' )->text()
		);

		$record = $this->recordFromItem( $item );
		// The class is fixed on update (hidden field) — re-classification
		// is a different edit, not "basic information".
		$fields = $this->reviewFieldSpecs( $record ) + [
			'class' => [ 'type' => 'hidden', 'default' => $classItemId ],
		];

		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setTitle( $this->getPageTitle( $itemId ) )
			->setSubmitTextMsg( 'embeddablecontent-update-submit' )
			->setSubmitCallback( fn ( array $data ) => $this->onUpdateSubmit( $data, $itemId ) )
			->setSubmitID( 'wb-ext-update' )
			->setWrapperLegendMsg( 'embeddablecontent-update-legend' );
		$form->show();
	}

	/**
	 * @param array<string,mixed> $data
	 * @return bool|string
	 */
	public function onUpdateSubmit( array $data, string $itemId ) {
		$loginError = $this->loginRequiredError();
		if ( $loginError !== null ) {
			return $loginError;
		}
		$classItemId = (string)( $data['class'] ?? '' );
		if ( $classItemId === '' ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}

		// The full form round-trips: every managed field is present in the
		// POST, INCLUDING cleared ones (a cleared field removes its
		// statement). File-upload fields arrive via multipart (record keys
		// are set by beforeCreate).
		$record = [];
		foreach ( $this->manualFieldSpecs() as $name => $_ ) {
			if ( !array_key_exists( $name, $data ) ) {
				continue;
			}
			$value = is_array( $data[$name] ) ? '' : trim( (string)$data[$name] );
			$record[$name] = ( $name === 'issuedYear' && $value !== '' ) ? (int)$value : $value;
		}

		$beforeError = $this->beforeCreate( $record );
		if ( $beforeError !== null ) {
			return $beforeError;
		}
		return $this->updateItemAndRedirect( $itemId, $record, $classItemId );
	}

	// ------------------------------------------------------------- persistence

	/**
	 * @param array<string,mixed> $record
	 * @return bool|string
	 */
	private function updateItemAndRedirect( string $itemId, array $record, string $classItemId ) {
		$label = trim( $this->primaryLabel( $record ) );
		if ( $label === '' ) {
			return $this->msg( 'embeddablecontent-add-error-required' )->text();
		}
		try {
			$this->applyUpdate( $itemId, $record, $classItemId, $label );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-update-error', get_class( $e ), $e->getMessage() )->text();
		}
		$this->getOutput()->redirect(
			WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( new ItemId( $itemId ) )->getFullURL()
		);
		return true;
	}

	/**
	 * Applies the update: en label/description terms, then the managed
	 * statement replacement (baseManagedPropertyIds ∪ the new specs), then
	 * the classic-page rename on a label change.
	 *
	 * @param array<string,mixed> $record
	 */
	private function applyUpdate( string $itemId, array $record, string $classItemId, string $newLabel ): void {
		$item = $this->loadItem( $itemId );
		if ( !$item instanceof Item ) {
			throw new \RuntimeException( 'item not found at update time' );
		}
		$oldLabel = $this->itemLabel( $item );

		// Terms: the en label + description (blank description removes it).
		$item->setLabel( 'en', $newLabel );
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( 'en', $description );
		} else {
			$item->removeDescription( 'en' );
		}

		// Statement replacement: remove the managed statements (the fields
		// the form shows + any property the new specs write — e.g. a newly
		// uploaded portrait's image facts), then re-add from the record with
		// the same import-provenance references the Add* path uses.
		$specs = $this->statementSpecs( $record );
		$removeProps = array_unique( array_merge( $this->baseManagedPropertyIds(), array_keys( $specs ) ) );
		foreach ( $removeProps as $propertyId ) {
			// StatementList has no removeStatement() — remove by guid.
			foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
				$guid = $statement->getGuid();
				if ( $guid !== null ) {
					$item->getStatements()->removeStatementsWithGuid( $guid );
				}
			}
		}

		$guidGenerator = new GuidGenerator();
		$add = function ( $propertyId, $value ) use ( $item, $guidGenerator, $record ): void {
			$statement = new Statement(
				new PropertyValueSnak( new NumericPropertyId( $propertyId ), $value ),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			);
			$referenceSnaks = $this->importReferenceSnaksForUpdate( $record );
			if ( $referenceSnaks !== null ) {
				$statement->addNewReference( ...$referenceSnaks );
			}
			$item->getStatements()->addStatement( $statement );
		};
		foreach ( $specs as $propertyId => $value ) {
			$values = is_array( $value ) ? $value : [ $value ];
			foreach ( $values as $v ) {
				$add( $propertyId, $v );
			}
		}

		WikibaseRepo::getEntityStore()->saveEntity(
			$item,
			$this->msg( 'embeddablecontent-update-edit-summary', $newLabel )->inContentLanguage()->text(),
			$this->getUser(),
			EDIT_UPDATE
		);

		if ( $oldLabel !== '' && $oldLabel !== $newLabel ) {
			$this->renameClassicPage( $item, $oldLabel, $newLabel );
		}
	}

	/**
	 * Import-provenance reference for an updated statement (source URL →
	 * authority URL, date → today) — the Add* creation-time reference,
	 * re-derived from the (possibly edited) record.
	 */
	private function importReferenceSnaksForUpdate( array $record ): ?SnakList {
		$provenance = $this->config->provenancePropertyIds();
		$url = $this->authorityUrl( $record );
		if ( $url === null || !isset( $provenance['sourceUrl'], $provenance['date'] ) ) {
			return null;
		}
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return new SnakList( [
			new PropertyValueSnak(
				new NumericPropertyId( $provenance['sourceUrl'] ),
				new \DataValues\StringValue( $url )
			),
			new PropertyValueSnak(
				new NumericPropertyId( $provenance['date'] ),
				new \DataValues\TimeValue(
					'+' . $now->format( 'Y-m-d' ) . 'T00:00:00Z',
					0, 0, 0,
					\DataValues\TimeValue::PRECISION_DAY,
					'http://www.wikidata.org/entity/Q1985727'
				)
			),
		] );
	}

	/**
	 * Best-effort classic-page rename on a label change: move the sitelinked
	 * page to the new label (+ subpages/talk), then update the sitelink to
	 * the new page name. A failure leaves the old page in place — the item
	 * update itself is never rolled back.
	 */
	private function renameClassicPage( Item $item, string $oldLabel, string $newLabel ): void {
		$pageNamespace = $this->pageNamespace();
		if ( $pageNamespace === null ) {
			return;
		}
		try {
			$oldTitle = Title::makeTitle( $pageNamespace, $oldLabel );
			$newTitle = Title::makeTitle( $pageNamespace, $newLabel );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( !$oldTitle->exists() || $newTitle->exists() ) {
			return;
		}
		try {
			$movePage = MediaWikiServices::getInstance()->getMovePageFactory()
				->newMovePage( $oldTitle, $newTitle );
			$status = $movePage->move(
				$this->getUser(),
				$this->msg( 'embeddablecontent-update-move-summary', $newLabel )->inContentLanguage()->text(),
				true,
				[ 'movetalk', 'movesubpages' ]
			);
			if ( !$status->isOK() ) {
				return;
			}
		} catch ( \Throwable $e ) {
			return;
		}
		// Point the sitelink at the new page name (entity save + table).
		$sitelinks = $item->getSiteLinkList();
		if ( $sitelinks->hasLinkWithSiteId( 'wikibase' ) ) {
			$sitelinks->setNewSiteLink( 'wikibase', $newTitle->getPrefixedText() );
			WikibaseRepo::getEntityStore()->saveEntity(
				$item,
				$this->msg( 'embeddablecontent-update-sitelink-summary', $newLabel )->inContentLanguage()->text(),
				$this->getUser(),
				EDIT_UPDATE
			);
			WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
		}
	}

	// ------------------------------------------------------------- record from item

	/** English label of an item ('' when none). */
	protected function itemLabel( Item $item ): string {
		// TermList::getByLanguage THROWS OutOfBoundsException when the term
		// is missing — check first (the Add* flows always have en labels, so
		// the unguarded call never surfaced there).
		if ( !$item->getLabels()->hasTermForLanguage( 'en' ) ) {
			return '';
		}
		return $item->getLabels()->getByLanguage( 'en' )->getText();
	}

	/** English description of an item ('' when none). */
	protected function itemDescription( Item $item ): string {
		if ( !$item->getDescriptions()->hasTermForLanguage( 'en' ) ) {
			return '';
		}
		return $item->getDescriptions()->getByLanguage( 'en' )->getText();
	}

	/**
	 * Instance-of class ids of an item.
	 *
	 * @return string[]
	 */
	protected function itemClassIds( Item $item ): array {
		$propertyId = new NumericPropertyId( $this->config->instanceOfPropertyId() );
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$out[] = $value->getEntityId()->getSerialization();
			}
		}
		return $out;
	}

	/**
	 * Entity-typed statement values of a property (serialized ids).
	 *
	 * @return string[]
	 */
	protected function entityIdsForProperty( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null ) {
			return [];
		}
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$out[] = $value->getEntityId()->getSerialization();
			}
		}
		return $out;
	}

	/** First entity-typed statement value ('' when none). */
	protected function firstEntityForProperty( Item $item, ?string $propertyId ): string {
		$ids = $this->entityIdsForProperty( $item, $propertyId );
		return $ids[0] ?? '';
	}

	/**
	 * String-typed statement values of a property.
	 *
	 * @return string[]
	 */
	protected function stringValuesForProperty( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null ) {
			return [];
		}
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof \DataValues\StringValue ) {
				$out[] = $value->getValue();
			}
		}
		return $out;
	}

	/** First string-typed statement value ('' when none). */
	protected function firstStringForProperty( Item $item, ?string $propertyId ): string {
		$values = $this->stringValuesForProperty( $item, $propertyId );
		return $values[0] ?? '';
	}

	/**
	 * Day-precision time statement as "YYYY-MM-DD" ('' when none/other).
	 */
	protected function timeValueForProperty( Item $item, ?string $propertyId ): string {
		if ( $propertyId === null ) {
			return '';
		}
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof \DataValues\TimeValue
				&& preg_match( '/^\+(\d{4})-(\d{2})-(\d{2})T/', $value->getTime(), $m ) === 1
			) {
				return sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] );
			}
		}
		return '';
	}

	/** Year-precision time statement as an int (null when none/other). */
	protected function yearForProperty( Item $item, ?string $propertyId ): ?int {
		if ( $propertyId === null ) {
			return null;
		}
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof \DataValues\TimeValue
				&& preg_match( '/^\+(\d{4})-00-00T/', $value->getTime(), $m ) === 1
			) {
				return (int)$m[1];
			}
		}
		return null;
	}

	/** Quantity statement as an int (null when none). */
	protected function quantityForProperty( Item $item, ?string $propertyId ): ?int {
		if ( $propertyId === null ) {
			return null;
		}
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof \DataValues\QuantityValue ) {
				return (int)$value->getAmount()->getValue();
			}
		}
		return null;
	}

	// ------------------------------------------------------------- shared plumbing

	/** Item id from the subpage (Special:UpdatePerson/Q42) or ?item=Q42. */
	private function itemIdFromSubPage( $subPage ): ?string {
		$candidate = trim( (string)$subPage );
		if ( $candidate === '' ) {
			$candidate = trim( (string)$this->getRequest()->getVal( 'item', '' ) );
		}
		return preg_match( '/^Q[1-9]\d*$/i', $candidate ) === 1
			? strtoupper( $candidate )
			: null;
	}

	private function loadItem( string $itemId ): ?Item {
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $entity instanceof Item ? $entity : null;
	}
}
