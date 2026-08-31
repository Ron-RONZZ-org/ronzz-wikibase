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
 *    the managed statements for which the form provides a NEW non-empty
 *    value (a blank managed field keeps the existing statement — no
 *    clobbering; removal is an explicit item-page edit) and updates the en
 *    label/description — everything else (sitelinks, non-managed
 *    statements) is untouched;
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
		$this->getOutput()->addModules( 'ext.embeddableContent.fileselect' );

		// The missing-page heal (applyUpdate → healClassicPage) redirects
		// here to finalize the just-created page in a fresh request — the
		// Add* afterCreate pattern (see executeComplete).
		if ( is_string( $subPage ) && str_starts_with( $subPage, 'complete/' ) ) {
			$this->executeComplete( substr( $subPage, strlen( 'complete/' ) ) );
			return;
		}

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
			$redirect = $this->applyUpdate( $itemId, $record, $classItemId, $label );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-update-error', get_class( $e ), $e->getMessage() )->text();
		}
		$this->getOutput()->redirect(
			$redirect ?? WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( new ItemId( $itemId ) )->getFullURL()
		);
		return true;
	}

	/**
	 * Applies the update: en label/description terms, then the managed
	 * statement replacement (only properties with a NEW non-empty spec —
	 * see applyUpdate), then the classic-page rename on a label change and
	 * the missing-page heal. Returns an explicit redirect target (the
	 * complete/<id> finalize step when a classic page was just created), or
	 * null for the default item redirect.
	 *
	 * @param array<string,mixed> $record
	 */
	private function applyUpdate( string $itemId, array $record, string $classItemId, string $newLabel ): ?string {
		$item = $this->loadItem( $itemId );
		if ( !$item instanceof Item ) {
			throw new \RuntimeException( 'item not found at update time' );
		}
		$oldLabel = $this->itemLabel( $item );

		// Terms: the en label + description. No-clobber: a BLANK description
		// keeps the existing one (only a new valid value replaces it).
		$item->setLabel( 'en', $newLabel );
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( 'en', $description );
		}

		// Statement replacement — NO-CLOBBER contract: only properties with
		// a NEW non-empty spec (from statementSpecs) are replaced. A managed
		// field the user left blank keeps the existing statement — "basic
		// information" must never overwrite what the user did not touch
		// (a cleared field is not an implicit removal; that is an explicit
		// item-page edit). The specs keys also cover newly-uploaded image
		// facts (portrait/logo), so an untouched existing image survives.
		$specs = $this->updateStatementSpecs( $record );
		foreach ( array_keys( $specs ) as $propertyId ) {
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

		// Rename the classic page on a label change OR a page-kind flip
		// (FOSS: ↔ Software: when the license changed). renameClassicPage
		// no-ops when the old sitelink page and the new title coincide.
		if ( $oldLabel !== '' ) {
			$this->renameClassicPage( $item, $oldLabel, $newLabel, $record );
		}
		// Heal a missing classic page: the class declares a page namespace
		// but the item has no sitelink/page (created before the page
		// machinery, or with a then-invalid label — the Q1232 case). The
		// update is the natural repair surface; creating the page here sends
		// the user through the complete/<id> finalize round-trip.
		return $this->healClassicPage( $item, $record, $newLabel );
	}

	/**
	 * The statement specs for an update — the no-clobber contract's managed
	 * set. Default: the legacy per-form statementSpecs; the Update* classes
	 * delegate to their flow service (updateStatementSpecs overrides) so the
	 * statement building lives in one place with the create path.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue|\Wikibase\DataModel\DataValue[]>
	 */
	protected function updateStatementSpecs( array $record ): array {
		return $this->statementSpecs( $record );
	}

	/**
	 * Creates the missing classic page for an updated item: the kind
	 * declares a page namespace (pageNamespace()), the item has no wikibase
	 * sitelink yet, and the (updated) label is usable as a page title.
	 * Mirrors afterCreate: sitelink first, then the marked page + the
	 * complete/<id> redirect target (null = no heal happened — the default
	 * item redirect stands).
	 *
	 * @param array<string,mixed> $record
	 * @return string|null redirect target, or null
	 */
	private function healClassicPage( Item $item, array $record, string $label ): ?string {
		if ( $this->pageNamespaceForRecord( $record ) === null
			|| $item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' )
		) {
			return null;
		}
		$title = $this->pageTitleForRecord( $record );
		if ( $title === null ) {
			return null;
		}
		$this->linkPageToItem( $item, $title, $label );
		if ( $title->exists() ) {
			// The page already existed (e.g. created by hand) — only the
			// sitelink was missing; nothing to finalize.
			return null;
		}
		if ( !$this->createClassicPage( $title, $record, $label ) ) {
			return null;
		}
		return $this->stepTitle( 'complete/' . $item->getId()->getSerialization() )->getFullURL();
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
	 * Best-effort classic-page rename on a label or page-kind change: move
	 * the sitelinked page to the new title (a label change within the same
	 * namespace, OR a license flip moving it between the FOSS: and
	 * Software: namespaces), then update the sitelink to the new page name.
	 * The OLD page name comes from the item's sitelink (ground truth —
	 * it knows exactly where the page is); the NEW title from the updated
	 * record's namespace + label. A failure leaves the old page in place —
	 * the item update itself is never rolled back.
	 */
	private function renameClassicPage( Item $item, string $oldLabel, string $newLabel, array $record ): void {
		$sitelinks = $item->getSiteLinkList();
		if ( !$sitelinks->hasLinkWithSiteId( 'wikibase' ) ) {
			// No sitelink → no page to move (the heal path creates it).
			return;
		}
		$newNs = $this->pageNamespaceForRecord( $record );
		if ( $newNs === null ) {
			return;
		}
		try {
			$oldTitle = Title::newFromText( $sitelinks->getBySiteId( 'wikibase' )->getPageName() );
			$newTitle = Title::makeTitle( $newNs, $newLabel );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( $oldTitle === null || $oldTitle->equals( $newTitle ) || !$oldTitle->exists() || $newTitle->exists() ) {
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
