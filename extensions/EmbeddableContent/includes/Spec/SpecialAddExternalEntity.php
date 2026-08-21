<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Fetch\ProviderClient;
use EmbeddableContent\Fetch\ProviderResult;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Rdbms\DBError;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Lib\TermIndexEntry;
use Wikibase\Repo\WikibaseRepo;

/**
 * Base class for the issue-#7 entity-creation pages: fetch from external
 * authorities (import-on-reference), review/correct, create-or-skip the local
 * stub item.
 *
 * Flow (token in the session, subpage carries the token; issue #12):
 *   1. search  — kind-specific inputs → ProviderClient → candidates stored
 *                in the session under the token → redirect to /<token>
 *   2. select  — detailed candidate table + radio + class picker → the picked
 *                record is enriched (harvest-on-pick) → redirect to
 *                /<token>/review/<index>
 *   3. review  — editable, pre-filled form with the harvested record; the
 *                user can correct errors in the external data by hand →
 *                create-or-skip → redirect to the created (or existing) item
 *   manual    — /manual: create from blank by hand when the search has no
 *                good result (no external record, no import reference)
 *
 * Imported statements carry authority IDs (externalIds), citation metadata
 * (citationMetadata) and an import-provenance reference (source URL + date),
 * all written at normal rank; the item is created with the English label
 * (fr/eo are editor additions).
 *
 * @license GPL-2.0-or-later
 */
abstract class SpecialAddExternalEntity extends SpecialPage {

	private const SESSION_PREFIX = 'extadd:';

	/** @var EmbeddableContentConfig */
	protected $config;

	/** @var ProviderClient */
	protected $client;

	public function __construct(
		string $pageName,
		EmbeddableContentConfig $config,
		ProviderClient $client
	) {
		parent::__construct( $pageName );
		$this->config = $config;
		$this->client = $client;
	}

	/** Canonical kind: person | source | collective */
	abstract protected function kindKey(): string;

	/** @return array<string,mixed> HTMLForm field descriptors for the search step */
	abstract protected function buildSearchFields(): array;

	/** Runs the provider cascade for the search-step inputs. */
	abstract protected function search( array $data ): ProviderResult;

	/** @return array<string,string> candidate option label (radio) => record index */
	abstract protected function candidateOptions( array $records ): array;

	/**
	 * Creates (or reuses) the local item for the selected record.
	 * Returns the item id.
	 */
	abstract protected function createFromRecord( array $record, string $classItemId ): string;

	/**
	 * Class options for the selection step: label => item id.
	 *
	 * @return array<string,string>
	 */
	abstract protected function classOptions(): array;

	/** Default class item id for the selection step (harvest inference). */
	abstract protected function defaultClassItemId( array $record ): ?string;

	/** Primary display label of a record (label vs title). */
	abstract protected function primaryLabel( array $record ): string;

	/**
	 * Editable HTMLForm field specs for the review step, pre-filled from the
	 * record (issue #12). Field names double as record keys.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function reviewFieldSpecs( array $record ): array;

	/**
	 * Enriches a light search record with the full authority record
	 * (harvest-on-pick, issue #7). Idempotent: returns the record unchanged
	 * once marked as harvested.
	 */
	abstract protected function enrichRecord( array $record ): array;

	public function execute( $subPage ) {
		// Standard special-page header plumbing (title from getDescription(),
		// noindex + article-related=false); the step handlers may then
		// override the title for their specific screen.
		$this->setHeaders();
		// No requireLogin() here: the page LOAD performs no external fetches,
		// and gating it excluded bot-password sessions — MediaWiki bot
		// passwords are API-only by design (BotPasswordSessionProvider serves
		// no non-API request), so an MCP/automation session could never view
		// the forms. The abuse surface is the SEARCH SUBMIT (server-side
		// external fetches) and the manual/review CREATION — those handlers
		// enforce login (onSearchSubmit/onManualSubmit).
		$this->getOutput()->enableOOUI();
		$parts = explode( '/', trim( (string)$subPage ) );
		$first = $parts[0] ?? '';
		if ( $first === '' ) {
			$this->executeSearch();
			return;
		}
		if ( $first === 'manual' ) {
			$this->executeManual();
			return;
		}
		if ( ( $parts[1] ?? '' ) === 'review' && ( $parts[2] ?? '' ) !== '' ) {
			$this->executeReview( $first, (int)$parts[2] );
			return;
		}
		$this->executeSelection( $first );
	}

	// ------------------------------------------------------------- step 1

	private function executeSearch(): void {
		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-title' )->text() );
		$form = HTMLForm::factory( 'ooui', $this->buildSearchFields(), $this->getContext() );
		$form->setTitle( $this->getPageTitle() )
			->setSubmitTextMsg( 'embeddablecontent-extsearch-submit' )
			->setSubmitCallback( [ $this, 'onSearchSubmit' ] )
			->setSubmitID( 'wb-ext-add-search' )
			->setWrapperLegendMsg( 'embeddablecontent-extsearch-legend' );
		$form->show();
		// Manual fallback (issue #12): always offered, also shown on zero hits.
		$this->getOutput()->addHTML(
			\MediaWiki\Html\Html::rawElement(
				'p',
				[ 'class' => 'wb-ext-manual' ],
				$this->msg( 'embeddablecontent-manual-hint' )->parse()
				. ' <a href="' . htmlspecialchars( $this->getPageTitle( 'manual' )->getFullURL() ) . '">'
				. $this->msg( 'embeddablecontent-manual-link' )->escaped() . '</a>'
			)
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return bool
	 */
	public function onSearchSubmit( array $data ) {
		$loginError = $this->loginRequiredError();
		if ( $loginError !== null ) {
			return $loginError;
		}
		try {
			$result = $this->search( $data );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-extsearch-error', get_class( $e ) )->text();
		}

		foreach ( $result->warnings as $warning ) {
			$this->getOutput()->addHTML(
				\MediaWiki\Html\Html::warningBox( htmlspecialchars( $warning ), 'embeddablecontent-warning' )
			);
		}

		if ( $result->records === [] ) {
			return $this->msg( 'embeddablecontent-extsearch-nohits' )->text();
		}

		$token = \MWCryptRand::generateHex( 16 );
		// Store plain arrays (records are value objects; the session must be
		// serializable and the selection step must not depend on classes).
		$records = array_map(
			static fn ( $record ): array => json_decode( json_encode( $record ), true ),
			$result->records
		);
		$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token, $records );
		$this->getOutput()->redirect( $this->getPageTitle( $token )->getFullURL() );
		return true;
	}

	// ------------------------------------------------------------- step 2

	private function executeSelection( string $token ): void {
		$records = $this->loadSessionRecords( $token );
		if ( $records === null ) {
			$this->showExpired();
			return;
		}

		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-select-title' )->text() );
		$firstRecord = $records[0] ?? [];

		// Detailed candidate display (issue #12): provider, description,
		// year and the full identifier set — plain radio labels are not
		// enough for same-name disambiguation.
		$this->getOutput()->addHTML( $this->candidateDetailsHtml( $records ) );

		$form = HTMLForm::factory( 'ooui', [
			'candidates' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-extselect-candidates',
				'options' => $this->candidateOptions( $records ),
				'default' => '0',
				'required' => true,
			],
			'class' => [
				'type' => 'select',
				'label-message' => 'embeddablecontent-extselect-class',
				'options' => $this->classOptions(),
				'default' => $this->defaultClassItemId( $firstRecord ) ?? '',
				'required' => true,
			],
		], $this->getContext() );

		$form->setTitle( $this->getPageTitle( $token ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-continue' )
			->setSubmitCallback( fn ( array $data ) => $this->onSelectSubmit( $data, $token, $records ) )
			->setSubmitID( 'wb-ext-add-create' )
			->setWrapperLegendMsg( 'embeddablecontent-extselect-legend' );
		$form->show();
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,array<string,mixed>> $records
	 * @return bool|string
	 */
	public function onSelectSubmit( array $data, string $token, array $records ) {
		$index = (int)( $data['candidates'] ?? -1 );
		$record = $records[$index] ?? null;
		if ( $record === null || !is_array( $record ) ) {
			return $this->msg( 'embeddablecontent-extselect-invalid' )->text();
		}
		$classItemId = (string)( $data['class'] ?? '' );
		if ( $classItemId === '' ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}

		// Enrich now (harvest-on-pick) so the review step shows the full
		// record; the user can correct errors before anything is created.
		$records[$index] = $this->enrichRecord( $record );
		$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token, $records );
		$this->getOutput()->redirect( $this->getPageTitle( $token . '/review/' . $index )->getFullURL() );
		return true;
	}

	// ------------------------------------------------------------- step 3

	private function executeReview( string $token, int $index ): void {
		$records = $this->loadSessionRecords( $token );
		$record = $records[$index] ?? null;
		if ( $record === null ) {
			$this->showExpired();
			return;
		}

		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-review-title' )->text() );
		$fields = $this->reviewFieldSpecs( $record ) + $this->classFieldSpec( $record );

		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setTitle( $this->getPageTitle( $token . '/review/' . $index ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-create' )
			->setSubmitCallback( fn ( array $data ) => $this->onReviewSubmit( $data, $token, $index, $records ) )
			->setSubmitID( 'wb-ext-add-create' )
			->setWrapperLegendMsg( 'embeddablecontent-review-legend' );
		$form->show();
	}

	/**
	 * Applies the user's hand-edits to the harvested record and creates the
	 * item (issue #12).
	 *
	 * @param array<string,mixed> $data
	 * @param array<int,array<string,mixed>> $records
	 * @return bool|string
	 */
	public function onReviewSubmit( array $data, string $token, int $index, array $records ) {
		$record = $records[$index] ?? null;
		if ( $record === null || !is_array( $record ) ) {
			return $this->msg( 'embeddablecontent-extselect-expired' )->text();
		}
		$classItemId = (string)( $data['class'] ?? ( $this->defaultClassItemId( $record ) ?? '' ) );
		if ( $classItemId === '' ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}

		// Fields present in the POST overwrite the record; absent fields keep
		// the harvested value.
		foreach ( $this->reviewFieldSpecs( $record ) as $name => $_ ) {
			if ( !array_key_exists( $name, $data ) ) {
				continue;
			}
			$value = is_array( $data[$name] ) ? '' : (string)$data[$name];
			$record[$name] = ( $name === 'issuedYear' && $value !== '' ) ? (int)$value : $value;
		}
		$this->beforeCreate( $record );
		if ( trim( $this->primaryLabel( $record ) ) === '' ) {
			return $this->msg( 'embeddablecontent-add-error-required' )->text();
		}

		try {
			$itemId = $this->createFromRecord( $record, $classItemId );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-extcreate-error', get_class( $e ), $e->getMessage() )->text();
		}

		$this->getRequest()->getSession()->remove( self::SESSION_PREFIX . $token );
		$target = $this->afterCreate( $itemId, $record );
		if ( $target !== null ) {
			$this->getOutput()->redirect( $target );
		} else {
			$this->redirectToItem( $itemId );
		}
		return true;
	}

	// ------------------------------------------------------------- manual

	private function executeManual(): void {
		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-manual-title' )->text() );
		$fields = $this->manualFieldSpecs() + $this->classFieldSpec();

		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setTitle( $this->getPageTitle( 'manual' ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-create' )
			->setSubmitCallback( [ $this, 'onManualSubmit' ] )
			->setSubmitID( 'wb-ext-add-manual' )
			->setWrapperLegendMsg( 'embeddablecontent-manual-legend' );
		$form->show();
	}

	/**
	 * Creates the item from blank (no external record): no import reference.
	 *
	 * @param array<string,mixed> $data
	 * @return bool|string
	 */
	public function onManualSubmit( array $data ) {
		$loginError = $this->loginRequiredError();
		if ( $loginError !== null ) {
			return $loginError;
		}
		$classItemId = (string)( $data['class'] ?? '' );
		if ( $classItemId === '' ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}
		$record = [];
		foreach ( $this->manualFieldSpecs() as $name => $_ ) {
			if ( !array_key_exists( $name, $data ) ) {
				continue;
			}
			$value = is_array( $data[$name] ) ? '' : trim( (string)$data[$name] );
			if ( $value === '' ) {
				continue;
			}
			$record[$name] = ( $name === 'issuedYear' ) ? (int)$value : $value;
		}
		$this->beforeCreate( $record );
		$label = $this->primaryLabel( $record );
		if ( $label === '' ) {
			return $this->msg( 'embeddablecontent-add-error-required' )->text();
		}
		try {
			$itemId = $this->manualCreate( $label, $classItemId, $record );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-extcreate-error', get_class( $e ), $e->getMessage() )->text();
		}
		$target = $this->afterCreate( $itemId, $record );
		if ( $target !== null ) {
			$this->getOutput()->redirect( $target );
		} else {
			$this->redirectToItem( $itemId );
		}
		return true;
	}

	/**
	 * Runs after the record is assembled from the form, before the item is
	 * created (review AND manual paths). Subclasses may upload files or
	 * validate cross-field constraints by mutating $record (e.g.
	 * Special:AddSoftware's logo upload writes $record['logoFileTitle']).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function beforeCreate( array &$record ): void {
	}

	/**
	 * Creates the item from the manual-form record. Subclasses that build
	 * kind-specific statements (e.g. Special:AddSoftware's URL/version/
	 * entity facts) override this; the default mirrors the review path's
	 * createFromRecord spec logic.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function manualCreate( string $label, string $classItemId, array $record ): string {
		$specs = $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
		return $this->createOrSkipItem( $label, $classItemId, $specs, $record );
	}

	/**
	 * Post-create hook: runs after the item is created (review and manual
	 * paths), before the redirect. Subclasses may create linked pages or
	 * sitelinks and return a redirect target URL; the default keeps the
	 * item redirect.
	 *
	 * @param array<string,mixed> $record
	 * @return string|null redirect target URL, or null for the item redirect
	 */
	protected function afterCreate( string $itemId, array $record ): ?string {
		return null;
	}

	// ------------------------------------------------------------- shared

	/**
	 * Login gate for the write/abuse surfaces (search submit performs
	 * server-side external fetches, manual submit creates items). Returns an
	 * error message for anonymous/bot sessions, null when logged in. The
	 * page LOADS are deliberately NOT gated (see execute()).
	 */
	private function loginRequiredError(): ?string {
		return $this->getUser()->isAnon()
			? $this->msg( 'embeddablecontent-extsearch-loginrequired' )->text()
			: null;
	}

	/** @return array<int,array<string,mixed>>|null */
	private function loadSessionRecords( string $token ): ?array {
		$records = $this->getRequest()->getSession()->get( self::SESSION_PREFIX . $token );
		return is_array( $records ) && $records !== [] ? $records : null;
	}

	private function showExpired(): void {
		$this->getOutput()->addHTML(
			\MediaWiki\Html\Html::errorBox(
				$this->msg( 'embeddablecontent-extselect-expired' )->escaped()
				. ' <a href="' . htmlspecialchars( $this->getPageTitle()->getFullURL() ) . '">'
				. $this->msg( 'embeddablecontent-extselect-retry' )->escaped() . '</a>'
			)
		);
	}

	private function redirectToItem( string $itemId ): void {
		$this->getOutput()->redirect(
			WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( new ItemId( $itemId ) )->getFullURL()
		);
	}

	/**
	 * Manual-entry form specs: the same editable fields as the review step,
	 * empty (issue #12).
	 *
	 * @return array<string,mixed>
	 */
	protected function manualFieldSpecs(): array {
		return $this->reviewFieldSpecs( [] );
	}

	/**
	 * Shared field builders for the review/manual forms.
	 * The label/description builders return a full `fieldname => descriptor`
	 * entry (array union `+` requires distinct top-level keys).
	 *
	 * @return array<string,mixed>
	 */
	protected function labelFieldSpec( string $fieldName, string $messageKey, string $default ): array {
		return [ $fieldName => [
			'type' => 'text',
			'label-message' => $messageKey,
			'default' => $default,
			'maxlength' => 250,
			'required' => true,
		] ];
	}

	/** @return array<string,mixed> */
	protected function descriptionFieldSpec( string $default ): array {
		return [ 'description' => [
			'type' => 'text',
			'label-message' => 'embeddablecontent-field-description',
			'default' => $default,
			'maxlength' => 500,
		] ];
	}

	/**
	 * Class field for the review/manual steps: a select when there is more
	 * than one class option, otherwise a hidden field (a single-option
	 * dropdown is noise — e.g. AddPerson is always a person).
	 *
	 * @return array<string,mixed> fieldname => descriptor
	 */
	protected function classFieldSpec( ?array $record = null ): array {
		$options = $this->classOptions();
		if ( count( $options ) === 1 ) {
			return [ 'class' => [ 'type' => 'hidden', 'default' => (string)reset( $options ) ] ];
		}
		return [ 'class' => [
			'type' => 'select',
			'label-message' => 'embeddablecontent-extselect-class',
			'options' => $options,
			'default' => $record !== null ? ( $this->defaultClassItemId( $record ) ?? '' ) : '',
			'required' => true,
		] ];
	}

	/** @return array<string,mixed> */
	protected function plainTextField( string $messageKey, string $default, int $maxlength = 250 ): array {
		return [
			'type' => 'text',
			'label-message' => $messageKey,
			'default' => $default,
			'maxlength' => $maxlength,
		];
	}

	/**
	 * Text fields for the config's external-id properties, pre-filled from
	 * the record; the field name doubles as the record key.
	 *
	 * @return array<string,mixed>
	 */
	protected function externalIdFieldSpecs( array $record ): array {
		$fields = [];
		foreach ( $this->externalIdRecordMap() as $key => $field ) {
			if ( $this->config->externalIdPropertyIds()[$key] === null ) {
				continue;
			}
			$fields[$field] = $this->plainTextField(
				'embeddablecontent-field-' . $key,
				(string)( $record[$field] ?? '' ),
				$key === 'isbn' ? 17 : 250
			);
		}
		return $fields;
	}

	/**
	 * Detailed candidate table for the selection step (issue #12): provider
	 * badge, label, description/container, year and the full identifier set.
	 *
	 * @param array<int,array<string,mixed>> $records
	 */
	protected function candidateDetailsHtml( array $records ): string {
		$rows = '';
		foreach ( $records as $index => $record ) {
			$provider = (string)( $record['provider'] ?? '' );
			$badge = $provider !== ''
				? '<span class="wb-ext-provider">[' . htmlspecialchars( ucfirst( $provider ) ) . ']</span> '
				: '';
			$label = htmlspecialchars( $this->primaryLabel( $record ) );
			$bits = [];
			// Description plus (for works) the container/publisher — showing
			// all three keeps the journal visible alongside the description.
			foreach ( [ 'description', 'containerTitle', 'publisher' ] as $key ) {
				if ( !empty( $record[$key] ) ) {
					$bits[] = htmlspecialchars( (string)$record[$key] );
				}
			}
			if ( !empty( $record['issuedYear'] ) ) {
				$bits[] = htmlspecialchars( (string)$record['issuedYear'] );
			}
			$ids = array_map( 'htmlspecialchars', $this->recordSummary( $record ) );
			$details = implode( ' · ', $bits );
			$rows .= '<tr><td class="wb-ext-num">' . ( $index + 1 ) . '</td><td>' . $badge
				. '<strong>' . $label . '</strong>'
				. ( $details !== '' ? '<br>' . $details : '' )
				. ( $ids !== [] ? ' <span class="wb-ext-ids">(' . implode( ', ', $ids ) . ')</span>' : '' );
			// Link to the record's canonical authority page, opening in a
			// NEW TAB (target=_blank; rel=noopener noreferrer against
			// reverse tabnabbing) so the candidate stays comparable.
			$recordUrl = $this->authorityUrl( $record );
			if ( $recordUrl !== null ) {
				$rows .= ' <a class="wb-ext-record-link" href="' . htmlspecialchars( $recordUrl )
					. '" target="_blank" rel="noopener noreferrer">'
					. $this->msg( 'embeddablecontent-extselect-seerecord' )->escaped() . '</a>';
			}
			$rows .= '</td></tr>';
		}
		return '<table class="wikitable wb-ext-candidates"><tbody>' . $rows . '</tbody></table>';
	}

	/** Compact radio label for a candidate (details live in the table). */
	protected function candidateOptionLabel( array $record ): string {
		$label = $this->primaryLabel( $record );
		if ( !empty( $record['description'] ) ) {
			$description = (string)$record['description'];
			if ( mb_strlen( $description ) > 80 ) {
				$description = mb_substr( $description, 0, 77 ) . '…';
			}
			$label .= ' — ' . $description;
		}
		if ( !empty( $record['issuedYear'] ) ) {
			$label .= ' (' . $record['issuedYear'] . ')';
		}
		$provider = (string)( $record['provider'] ?? '' );
		if ( $provider !== '' ) {
			$label = '[' . $provider . '] ' . $label;
		}
		return $label;
	}

	/**
	 * Radio options numbered to match the candidate detail table
	 * (issue #12): option label => record index.
	 *
	 * @param array<int,array<string,mixed>> $records
	 * @return array<string,string>
	 */
	protected function candidateOptionLabels( array $records ): array {
		$options = [];
		foreach ( $records as $index => $record ) {
			$options[ ( $index + 1 ) . '. ' . $this->candidateOptionLabel( $record ) ] = (string)$index;
		}
		return $options;
	}

	/**
	 * Create-or-skip: reuses an existing local item with the same primary
	 * label (seed semantics), otherwise creates it with the given statements.
	 *
	 * A spec value is normally a single DataValue (one statement); a value
	 * given as an ARRAY of DataValue writes one statement per element —
	 * this is how multi-valued facts (several developers, operating systems,
	 * licenses, …) land on the item.
	 *
	 * @param array<string,mixed> $statementSpecs property id => DataValue | DataValue[]
	 */
	protected function createOrSkipItem( string $label, string $classItemId, array $statementSpecs, array $record ): string {
		$existing = $this->findItemIdByLabel( $label );
		if ( $existing !== null ) {
			return $existing;
		}

		$item = new Item();
		$item->setLabel( 'en', $label );

		WikibaseRepo::getEntityStore()->saveEntity(
			$item,
			$this->msg( 'embeddablecontent-extcreate-edit-summary', $label )->inContentLanguage()->text(),
			$this->getUser(),
			EDIT_NEW
		);

		$guidGenerator = new GuidGenerator();
		$add = function ( $propertyId, $value, $reference = true ) use ( $item, $guidGenerator, $record ): void {
			// NOTE: Statement::__construct is (mainSnak, qualifiers, references, guid).
			$statement = new Statement(
				new PropertyValueSnak( new \Wikibase\DataModel\Entity\NumericPropertyId( $propertyId ), $value ),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			);
			if ( $reference ) {
				$referenceSnaks = $this->importReferenceSnaks( $record );
				if ( $referenceSnaks !== null ) {
					// addNewReference() is variadic over Snak — spread the list.
					$statement->addNewReference( ...$referenceSnaks );
				}
			}
			$item->getStatements()->addStatement( $statement );
		};

		$add( $this->config->instanceOfPropertyId(), new EntityIdValue( new ItemId( $classItemId ) ), false );
		foreach ( $statementSpecs as $propertyId => $value ) {
			$values = is_array( $value ) ? $value : [ $value ];
			foreach ( $values as $v ) {
				$add( $propertyId, $v );
			}
		}

		WikibaseRepo::getEntityStore()->saveEntity(
			$item,
			$this->msg( 'embeddablecontent-extcreate-edit-summary', $label )->inContentLanguage()->text(),
			$this->getUser(),
			EDIT_UPDATE
		);
		return $item->getId()->getSerialization();
	}

	/**
	 * Import-provenance reference: source URL → authority URL, date → today.
	 */
	private function importReferenceSnaks( array $record ): ?SnakList {
		$provenance = $this->config->provenancePropertyIds();
		$url = $this->authorityUrl( $record );
		if ( $url === null || !isset( $provenance['sourceUrl'], $provenance['date'] ) ) {
			return null;
		}
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return new SnakList( [
			new PropertyValueSnak(
				new \Wikibase\DataModel\Entity\NumericPropertyId( $provenance['sourceUrl'] ),
				new StringValue( $url )
			),
			new PropertyValueSnak(
				new \Wikibase\DataModel\Entity\NumericPropertyId( $provenance['date'] ),
				new TimeValue(
					'+' . $now->format( 'Y-m-d' ) . 'T00:00:00Z',
					0, 0, 0,
					TimeValue::PRECISION_DAY,
					'http://www.wikidata.org/entity/Q1985727'
				)
			),
		] );
	}

	/**
	 * Authority URL for the import-provenance reference AND the candidate
	 * "see record details" link, derived from the record's provider +
	 * identifiers. Returns null when no canonical URL is derivable.
	 */
	protected function authorityUrl( array $record ): ?string {
		switch ( $record['provider'] ?? '' ) {
			case 'wikidata':
				return isset( $record['wikidataId'] ) ? 'https://www.wikidata.org/wiki/' . $record['wikidataId'] : null;
			case 'orcid':
				return isset( $record['orcid'] ) ? 'https://orcid.org/' . $record['orcid'] : null;
			case 'openalex':
				return isset( $record['providerId'] ) ? $record['providerId'] : null;
			case 'crossref':
				return isset( $record['doi'] ) ? 'https://doi.org/' . $record['doi'] : null;
			case 'openlibrary':
				return isset( $record['providerId'] ) ? 'https://openlibrary.org' . $record['providerId'] : null;
			case 'dblp':
				return isset( $record['providerId'] ) ? $record['providerId'] : null;
			default:
				return null;
		}
	}

	/**
	 * ExternalId statements for the record: canonical key => record field.
	 * Kind-specific: each subclass declares only the identifiers relevant to
	 * its entity type (a person has no DOI/ISBN, a work has no ORCID/VIAF).
	 *
	 * @return array<string,string> externalIds key => record field name
	 */
	abstract protected function externalIdRecordMap(): array;

	/**
	 * Builds the authority-ID statement specs present in the config map.
	 *
	 * @return array<string,\Wikibase\DataModel\DataValue> property id => DataValue
	 */
	protected function externalIdStatements( array $record ): array {
		$specs = [];
		foreach ( $this->externalIdRecordMap() as $key => $field ) {
			$propertyId = $this->config->externalIdPropertyIds()[$key] ?? null;
			if ( $propertyId === null || empty( $record[$field] ) ) {
				continue;
			}
			$specs[$propertyId] = new StringValue( (string)$record[$field] );
		}
		return $specs;
	}

	/**
	 * Builds the citation-metadata statement specs present in the config map.
	 *
	 * @return array<string,\Wikibase\DataModel\DataValue>
	 */
	protected function citationMetadataStatements( array $record ): array {
		$specs = [];
		$map = [
			'givenName' => 'givenName',
			'familyName' => 'familyName',
			'publishedIn' => 'containerTitle',
			'publisher' => 'publisher',
			'pages' => 'pages',
			'volume' => 'volume',
			'issue' => 'issue',
		];
		foreach ( $map as $key => $field ) {
			$propertyId = $this->config->citationMetadataPropertyIds()[$key] ?? null;
			if ( $propertyId === null || empty( $record[$field] ) ) {
				continue;
			}
			$specs[$propertyId] = new StringValue( (string)$record[$field] );
		}
		return $specs;
	}

	private function findItemIdByLabel( string $label ): ?string {
		try {
			$entries = WikibaseRepo::getMatchingTermsLookupFactory()
				->getLookupForSource( WikibaseRepo::getLocalEntitySource() )
				->getMatchingTerms(
					$label,
					Item::ENTITY_TYPE,
					'en',
					TermIndexEntry::TYPE_LABEL,
					[ 'caseSensitive' => false ]
				);
		} catch ( DBError $e ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			$id = $entry->getEntityId();
			if ( $id instanceof ItemId ) {
				return $id->getSerialization();
			}
		}
		return null;
	}

	/** @return string[] */
	protected function recordSummary( array $record ): array {
		$bits = [];
		// Only the kind-relevant identifiers (per-subclass externalIdRecordMap).
		foreach ( $this->externalIdRecordMap() as $field ) {
			if ( !empty( $record[$field] ) ) {
				$bits[] = (string)$record[$field];
			}
		}
		return $bits;
	}

	/**
	 * MW 1.43+ resolves the special-page description from the bare lowercase
	 * page name (strtolower( $this->mName )); our i18n uses the legacy
	 * `special-<name>` keys. Override to keep the pages listed on
	 * Special:SpecialPages (T360723 skips pages whose description message
	 * is disabled) and to render a proper page title — same pattern as
	 * Wikibase's SpecialWikibasePage.
	 */
	public function getDescription() {
		return $this->msg( 'special-' . strtolower( $this->getName() ) );
	}

	protected function getGroupName(): string {
		return 'wikibase';
	}
}
