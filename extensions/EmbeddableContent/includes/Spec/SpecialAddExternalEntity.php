<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\EntityLabelMatcher;
use EmbeddableContent\Fetch\ProviderClient;
use EmbeddableContent\Fetch\ProviderResult;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
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

	protected const SESSION_PREFIX = 'extadd:';

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
	 * Full authority record for a Wikidata id (harvest-on-pick, issue #7).
	 * Kind-specific: each subclass delegates to its provider
	 * (harvestSoftware / harvestPerson / harvestWork / harvestEntity).
	 */
	abstract protected function harvest( string $qid ): ProviderResult;

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
		// Entity comboboxes in the review/manual steps need the autofill
		// module (AddSoftware entity facts, AddSource authors/parent,
		// AddPerson place fields); loading it is a no-op without comboboxes.
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );
		// The portrait/logo URL validate button + 429 blob fallback; a
		// no-op on pages without the wb-uploadmeta wiring span.
		$this->getOutput()->addModules( 'ext.embeddableContent.uploadmeta' );
		// The entity-field autofill confirmation banners (publisher/journal/
		// authors/licenses auto-filled from fetched source data) — a no-op
		// when the rendered form carries no .wb-entity-confirm block.
		$this->getOutput()->addModules( 'ext.embeddableContent.entityconfirm' );
		$parts = explode( '/', trim( (string)$subPage ) );
		$first = $parts[0] ?? '';
		if ( $first === '' ) {
			$this->executeSearch();
			return;
		}
		if ( $first === 'manual' ) {
			if ( ( $parts[1] ?? '' ) === 'content' ) {
				$this->executeManualContent();
				return;
			}
			$this->executeManual();
			return;
		}
		if ( ( $parts[1] ?? '' ) === 'review' && ( $parts[2] ?? '' ) !== '' ) {
			if ( ( $parts[3] ?? '' ) === 'content' ) {
				// Fetched-content review step: /<token>/review/<i>/content.
				$this->executeContent( $first, (int)$parts[2] );
				return;
			}
			$this->executeReview( $first, (int)$parts[2] );
			return;
		}
		// Finalize a just-created classic page in a FRESH request (the page
		// is written by afterCreate; this step strips the pending marker —
		// tokens are 32-hex so `complete` can never collide with them).
		if ( $first === 'complete' && ( $parts[1] ?? '' ) !== '' ) {
			$this->executeComplete( $parts[1] );
			return;
		}
		$this->executeSelection( $first );
	}

	/**
	 * Subclass URL prefix for step subpages (e.g. a class key in
	 * Special:AddSource's class-first flow); '' for the plain page. All
	 * step URLs go through stepTitle() so the prefix stays consistent.
	 */
	protected function classUrlPrefix(): string {
		return '';
	}

	/**
	 * Title of a step subpage, honoring the subclass URL prefix.
	 */
	protected function stepTitle( string $sub = '' ): Title {
		$prefix = $this->classUrlPrefix();
		if ( $prefix === '' ) {
			return $sub === '' ? $this->getPageTitle() : $this->getPageTitle( $sub );
		}
		return $sub === '' ? $this->getPageTitle( $prefix ) : $this->getPageTitle( $prefix . '/' . $sub );
	}

	// ------------------------------------------------------------- step 1

	protected function executeSearch(): void {
		$this->getOutput()->setPageTitle( $this->searchStepTitleMessage()->text() );
		$form = HTMLForm::factory( 'ooui', $this->buildSearchFields(), $this->getContext() );
		$form->setTitle( $this->stepTitle() )
			->setSubmitTextMsg( 'embeddablecontent-extsearch-submit' )
			->setSubmitCallback( [ $this, 'onSearchSubmit' ] )
			->setSubmitID( 'wb-ext-add-search' )
			->setWrapperLegendMsg( $this->searchStepLegendMessage() );
		$form->show();
		// Manual fallback (issue #12): always offered, also shown on zero hits.
		$this->getOutput()->addHTML( $this->manualFallbackHtml() );
	}

	/**
	 * Page title of the search step. The default is the kind's root title
	 * (e.g. "Special:AddPerson"); Special:AddSource overrides it with the
	 * class-scoped "Search for {class} … from an external authority".
	 */
	protected function searchStepTitleMessage(): \Message {
		return $this->msg( 'embeddablecontent-' . $this->kindKey() . '-title' );
	}

	/** Form-legend message of the search step (kind-scoped). */
	protected function searchStepLegendMessage(): string {
		return 'embeddablecontent-' . $this->kindKey() . '-extsearch-legend';
	}

	/**
	 * "Create the item manually instead" link to the manual step. $query
	 * extra URL parameters (e.g. the session token for search-autofill,
	 * issue #35). The old "No matching record?" preface is gone — the user
	 * cannot know whether a record matches until they search.
	 *
	 * @param array<string,string> $query
	 */
	protected function manualFallbackHtml( array $query = [] ): string {
		return \MediaWiki\Html\Html::rawElement(
			'p',
			[ 'class' => 'wb-ext-manual' ],
			'<a href="' . htmlspecialchars( $this->stepTitle( 'manual' )->getFullURL( $query ) ) . '">'
			. $this->msg( 'embeddablecontent-manual-link' )->escaped() . '</a>'
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

		// The token is generated even on zero hits: the search inputs are
		// stored under it so the manual form can autofill what the user
		// typed (issue #35) — the zero-hit page renders a manual link with
		// the token, the selection page's link carries it too.
		$token = \MWCryptRand::generateHex( 16 );
		// Store plain arrays (records are value objects; the session must be
		// serializable and the selection step must not depend on classes).
		$records = array_map(
			static fn ( $record ): array => json_decode( json_encode( $record ), true ),
			$result->records
		);
		$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token, $records );
		$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token . ':search', $data );

		if ( $result->records === [] ) {
			$this->getOutput()->addHTML( $this->manualFallbackHtml( [ 'token' => $token ] ) );
			return $this->msg( 'embeddablecontent-extsearch-nohits' )->text();
		}

		$this->getOutput()->redirect( $this->stepTitle( $token )->getFullURL() );
		return true;
	}

	// ------------------------------------------------------------- step 2

	protected function executeSelection( string $token ): void {
		$records = $this->loadSessionRecords( $token );
		if ( $records === null ) {
			$this->showExpired();
			return;
		}

		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-select-title' )->text() );

		// Detailed candidate display (issue #12): provider, description,
		// year and the full identifier set — plain radio labels are not
		// enough for same-name disambiguation.
		$this->getOutput()->addHTML( $this->candidateDetailsHtml( $records ) );

		// NO class field here (issue follow-up): the selection step is about
		// the RECORD, the class is about how to classify it — that choice
		// belongs on the review step, where the harvested record's class
		// inference (defaultClassItemId) pre-selects a sensible default.
		$form = HTMLForm::factory( 'ooui', [
			'candidates' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-extselect-candidates',
				'options' => $this->candidateOptions( $records ),
				'default' => '0',
				'required' => true,
			],
		], $this->getContext() );

		$form->setTitle( $this->stepTitle( $token ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-continue' )
			->setSubmitCallback( fn ( array $data ) => $this->onSelectSubmit( $data, $token, $records ) )
			->setSubmitID( 'wb-ext-add-create' )
			->setWrapperLegendMsg( 'embeddablecontent-extselect-legend' );
		$form->show();
		// Manual fallback on the SELECTION step too (issue #35): none of the
		// candidates is a good match — the link carries the token so the
		// manual form is prefilled with the search inputs.
		$this->getOutput()->addHTML( $this->manualFallbackHtml( [ 'token' => $token ] ) );
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

		// No class here (issue follow-up): the class is chosen on the REVIEW
		// step, where the harvested record pre-selects the inferred class —
		// the selection step only decides WHICH record to import.

		// Enrich now (harvest-on-pick) so the review step shows the full
		// record; the user can correct errors before anything is created.
		$records[$index] = $this->enrichRecord( $record );
		$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token, $records );
		$this->getOutput()->redirect( $this->stepTitle( $token . '/review/' . $index )->getFullURL() );
		return true;
	}

	// ------------------------------------------------------------- step 3

	protected function executeReview( string $token, int $index ): void {
		$records = $this->loadSessionRecords( $token );
		$record = $records[$index] ?? null;
		if ( $record === null ) {
			$this->showExpired();
			return;
		}

		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-review-title' )->text() );
		$fields = $this->reviewFieldSpecs( $record ) + $this->classFieldSpec( $record );

		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setTitle( $this->stepTitle( $token . '/review/' . $index ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-create' )
			->setSubmitCallback( fn ( array $data ) => $this->onReviewSubmit( $data, $token, $index, $records ) )
			->setSubmitID( 'wb-ext-add-create' )
			->setWrapperLegendMsg( 'embeddablecontent-review-legend' );
		$form->show();
	}

	/**
	 * Applies the user's hand-edits to the harvested record, validates it,
	 * then either routes to the fetched-content review step (when the record
	 * carries page content — abstract/keywords/intro/plot/lyrics/…) or
	 * creates the item directly (issue #12 + follow-up).
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
		// Validate now so errors surface on the RECORD form, not on the
		// content step. Side effects (file uploads) are idempotent — the
		// final creation re-runs beforeCreate without duplicating them.
		$beforeError = $this->beforeCreate( $record );
		if ( $beforeError !== null ) {
			return $beforeError;
		}

		$records[$index] = $record;
		$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token, $records );

		if ( $this->recordHasContent( $record ) ) {
			$this->getOutput()->redirect(
				$this->stepTitle( $token . '/review/' . $index . '/content' )->getFullURL()
			);
			return true;
		}
		return $this->createItemAndRedirect( $record, $classItemId, $token );
	}

	// ------------------------------------------------------------- content step
	// Fetched page content (abstract/keywords/intro/plot/lyrics/…) is
	// reviewed on its OWN step after the record review — the record form
	// stays about facts, the content form about prose. The step is skipped
	// entirely when nothing was fetched (no blank forms, no clutter).

	/**
	 * Editable content-field specs for the content review step: one
	 * MULTI-LINE textarea per fetched content key. The default has no
	 * content fields; subclasses declare their page-content keys (e.g.
	 * Special:AddSource per source class, Special:AddPerson biography).
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed> fieldname => descriptor
	 */
	protected function contentFieldSpecs( array $record ): array {
		return [];
	}

	/** @return string[] content field names (record keys) */
	protected function contentKeys(): array {
		return array_keys( $this->contentFieldSpecs( [] ) );
	}

	/** Whether the record carries any page content for the content step. */
	protected function recordHasContent( array $record ): bool {
		foreach ( $this->contentKeys() as $key ) {
			if ( !empty( $record[$key] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Overwrites the record's content fields from the content-step POST.
	 * Cleared fields become '' → the page section is omitted.
	 *
	 * @param array<string,mixed> $record
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function applyContentFields( array $record, array $data ): array {
		foreach ( $this->contentFieldSpecs( $record ) as $name => $_ ) {
			if ( !array_key_exists( $name, $data ) ) {
				continue;
			}
			$value = is_array( $data[$name] ) ? '' : trim( (string)$data[$name] );
			$record[$name] = $value;
		}
		return $record;
	}

	protected function executeContent( string $token, int $index ): void {
		$records = $this->loadSessionRecords( $token );
		$record = $records[$index] ?? null;
		if ( $record === null || !is_array( $record ) ) {
			$this->showExpired();
			return;
		}
		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-content-review-title' )->text() );
		$fields = $this->contentFieldSpecs( $record ) + $this->classFieldSpec( $record );

		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setTitle( $this->stepTitle( $token . '/review/' . $index . '/content' ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-create' )
			->setSubmitCallback( fn ( array $data ) => $this->onContentSubmit( $data, $token, $index, $records ) )
			->setSubmitID( 'wb-ext-add-content' )
			->setWrapperLegendMsg( 'embeddablecontent-content-review-legend' );
		$form->show();
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,array<string,mixed>> $records
	 * @return bool|string
	 */
	public function onContentSubmit( array $data, string $token, int $index, array $records ) {
		$record = $records[$index] ?? null;
		if ( $record === null || !is_array( $record ) ) {
			return $this->msg( 'embeddablecontent-extselect-expired' )->text();
		}
		$record = $this->applyContentFields( $record, $data );
		$classItemId = (string)( $data['class'] ?? ( $this->defaultClassItemId( $record ) ?? '' ) );
		if ( $classItemId === '' ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}
		$beforeError = $this->beforeCreate( $record );
		if ( $beforeError !== null ) {
			return $beforeError;
		}
		return $this->createItemAndRedirect( $record, $classItemId, $token );
	}

	/**
	 * Shared creation tail: beforeCreate must already have run (each caller
	 * runs it exactly once), then the item is created and the user is
	 * redirected (classic page via afterCreate, or the item).
	 *
	 * @param array<string,mixed> $record
	 * @return bool|string
	 */
	private function createItemAndRedirect( array $record, string $classItemId, string $token ): bool {
		if ( trim( $this->primaryLabel( $record ) ) === '' ) {
			return $this->msg( 'embeddablecontent-add-error-required' )->text();
		}
		try {
			$itemId = $this->createFromRecord( $record, $classItemId );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-extcreate-error', get_class( $e ), $e->getMessage() )->text();
		}

		$this->getRequest()->getSession()->remove( self::SESSION_PREFIX . $token );
		$this->getRequest()->getSession()->remove( self::SESSION_PREFIX . $token . ':class' );
		$target = $this->afterCreate( $itemId, $record );
		if ( $target !== null ) {
			$this->getOutput()->redirect( $target );
		} else {
			$this->redirectToItem( $itemId );
		}
		return true;
	}

	// ------------------------------------------------------------- manual

	protected function executeManual(): void {
		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-manual-title' )->text() );
		// Search autofill (issue #35): when the manual step is reached from a
		// search (selection page / zero-hit link carries ?token=), the search
		// inputs prefill the manual fields — the user corrects instead of
		// retyping.
		$record = $this->manualAutofillRecord();
		$fields = $this->reviewFieldSpecs( $record ) + $this->classFieldSpec( $record );

		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setTitle( $this->stepTitle( 'manual' ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-create' )
			->setSubmitCallback( [ $this, 'onManualSubmit' ] )
			->setSubmitID( 'wb-ext-add-manual' )
			->setWrapperLegendMsg( 'embeddablecontent-manual-legend' );
		$form->show();
	}

	/**
	 * Autofill record for the manual form: the search inputs stored under
	 * the token (see onSearchSubmit) mapped onto the manual fields, or — for
	 * the website/webpage URL-first flow — the metadata fetched from the
	 * entered URL (see SpecialAddSource::onUrlEntrySubmit). Empty when the
	 * manual step was reached directly (no token).
	 *
	 * @return array<string,mixed>
	 */
	protected function manualAutofillRecord(): array {
		$token = $this->getRequest()->getVal( 'token' );
		if ( $token === null || $token === '' ) {
			return [];
		}
		$session = $this->getRequest()->getSession();
		$search = $session->get( self::SESSION_PREFIX . $token . ':search' );
		if ( is_array( $search ) ) {
			return $this->autofillRecord( $search );
		}
		$urlMeta = $session->get( self::SESSION_PREFIX . $token . ':urlmeta' );
		return is_array( $urlMeta ) ? $this->autofillRecord( $urlMeta ) : [];
	}

	/**
	 * URL-fetched metadata stored under the session token (the
	 * website/webpage URL-first flow), or [] when absent.
	 *
	 * @return array<string,mixed>
	 */
	protected function manualUrlMeta(): array {
		$token = $this->getRequest()->getVal( 'token' );
		if ( $token === null || $token === '' ) {
			return [];
		}
		$urlMeta = $this->getRequest()->getSession()->get( self::SESSION_PREFIX . $token . ':urlmeta' );
		return is_array( $urlMeta ) ? $urlMeta : [];
	}

	/**
	 * Maps the search-step inputs onto the manual review fields. The default
	 * passes through keys shared by both (same field name); subclasses map
	 * differently-named fields (AddSource author→authors, AddPerson
	 * name→given/family via NameSplitter, AddSoftware/AddCollective
	 * name→label).
	 *
	 * @param array<string,mixed> $search
	 * @return array<string,mixed>
	 */
	protected function autofillRecord( array $search ): array {
		$out = [];
		$manualFields = array_keys( $this->reviewFieldSpecs( [] ) );
		foreach ( $search as $key => $value ) {
			if ( is_string( $value ) && in_array( $key, $manualFields, true ) ) {
				$out[$key] = $value;
			}
		}
		return $out;
	}

	/**
	 * Creates the item from blank (no external record): no import reference.
	 * When the record carries page content (e.g. the website/webpage
	 * URL-first flow's fetched intro/keywords), routes through the content
	 * review step first.
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
		// URL-first flow content (website/webpage): the fetched intro and
		// keywords ride along for the content review step.
		$urlMeta = $this->manualUrlMeta();
		foreach ( $this->contentKeys() as $key ) {
			if ( empty( $record[$key] ) && !empty( $urlMeta[$key] ) ) {
				$record[$key] = (string)$urlMeta[$key];
				$record['contentSources'][$key] = 'site';
			}
		}
		$beforeError = $this->beforeCreate( $record );
		if ( $beforeError !== null ) {
			return $beforeError;
		}
		if ( $this->recordHasContent( $record ) ) {
			$token = \MWCryptRand::generateHex( 16 );
			$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token, [ $record ] );
			$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token . ':class', $classItemId );
			$this->getOutput()->redirect(
				$this->stepTitle( 'manual/content' )->getFullURL( [ 'token' => $token ] )
			);
			return true;
		}
		return $this->createItemAndRedirect( $record, $classItemId, '' );
	}

	/**
	 * Content review step for the manual path (/manual/content?token=): the
	 * record assembled by onManualSubmit (stored under the token) is edited
	 * field by field before creation.
	 */
	protected function executeManualContent(): void {
		$token = $this->getRequest()->getVal( 'token' );
		$records = $this->loadSessionRecords( $token );
		$record = $records[0] ?? null;
		if ( $record === null || !is_array( $record ) ) {
			$this->showExpired();
			return;
		}
		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-content-review-title' )->text() );
		$fields = $this->contentFieldSpecs( $record ) + $this->classFieldSpec( $record );

		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		// The token lives in the QUERY (the manual path is /manual/content,
		// unlike the review path where it is in the subpage) — set the form
		// action so the POST keeps it.
		$form->setAction( $this->stepTitle( 'manual/content' )->getFullURL( [ 'token' => $token ] ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-create' )
			->setSubmitCallback( [ $this, 'onManualContentSubmit' ] )
			->setSubmitID( 'wb-ext-add-content' )
			->setWrapperLegendMsg( 'embeddablecontent-content-review-legend' );
		$form->show();
	}

	/**
	 * @param array<string,mixed> $data
	 * @return bool|string
	 */
	public function onManualContentSubmit( array $data ) {
		$token = $this->getRequest()->getVal( 'token' );
		$records = $this->loadSessionRecords( $token );
		$record = $records[0] ?? null;
		if ( $record === null || !is_array( $record ) ) {
			return $this->msg( 'embeddablecontent-extselect-expired' )->text();
		}
		$record = $this->applyContentFields( $record, $data );
		$classItemId = (string)( $data['class'] ?? '' );
		if ( $classItemId === '' ) {
			$classItemId = (string)$this->getRequest()->getSession()
				->get( self::SESSION_PREFIX . $token . ':class' );
		}
		if ( $classItemId === '' ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}
		$beforeError = $this->beforeCreate( $record );
		if ( $beforeError !== null ) {
			return $beforeError;
		}
		return $this->createItemAndRedirect( $record, $classItemId, $token );
	}

	/**
	 * Runs after the record is assembled from the form, before the item is
	 * created (review AND manual paths). Subclasses may upload files or
	 * validate cross-field constraints by mutating $record (e.g.
	 * Special:AddSoftware's logo upload writes $record['logoFileTitle']).
	 * Returning a non-null string ABORTS the creation and shows it as a
	 * form error — a failure to honour a field the user filled in must never
	 * be silently swallowed.
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function beforeCreate( array &$record ): ?string {
		return null;
	}

	/**
	 * Statement specs for the created item, built from the (harvested or
	 * hand-edited) record. The default writes the authority external ids +
	 * the citation-metadata facts; subclasses with kind-specific statements
	 * (AddSoftware's URL/entity facts, AddSource's duration/authors/part-of)
	 * override and extend.
	 *
	 * A spec value is normally a single DataValue (one statement); a value
	 * given as an ARRAY of DataValue writes one statement per element.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue|\Wikibase\DataModel\DataValue[]>
	 */
	protected function statementSpecs( array $record ): array {
		return $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
	}

	/**
	 * Creates (or reuses) the local item for the selected record.
	 * Returns the item id.
	 */
	protected function createFromRecord( array $record, string $classItemId ): string {
		$record = $this->enrichRecord( $record );
		return $this->createOrSkipItem(
			$this->primaryLabel( $record ),
			$classItemId,
			$this->statementSpecs( $record ),
			$record
		);
	}

	/**
	 * Creates the item from the manual-form record (no external record, no
	 * import reference). Same statement specs as the review path.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function manualCreate( string $label, string $classItemId, array $record ): string {
		return $this->createOrSkipItem( $label, $classItemId, $this->statementSpecs( $record ), $record );
	}

	/**
	 * Enriches a light search record with the full authority record
	 * (harvest-on-pick, issue #7). Idempotent: returns the record unchanged
	 * once marked as harvested. Only harvestable records are enriched
	 * (canHarvest()); the harvest itself is kind-specific (harvest()). After
	 * the authority harvest, the subclass content hook (harvestContent)
	 * fetches page content (abstract/keywords/intro/plot/lyrics/…) —
	 * best-effort, never fatal.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function enrichRecord( array $record ): array {
		if ( !empty( $record['harvested'] ) ) {
			return $record;
		}
		$qid = (string)( $record['wikidataId'] ?? '' );
		if ( $qid !== '' && $this->canHarvest( $record ) ) {
			$result = $this->harvest( $qid );
			if ( $result->records !== [] ) {
				$record = array_merge( $record, (array)$result->records[0] );
			}
		}
		$record = $this->harvestContent( $record );
		$record['harvested'] = true;
		return $record;
	}

	/**
	 * Page-content auto-fetch hook (abstract/keywords/intro/plot/lyrics/…),
	 * called at harvest-on-pick. Subclasses that build classic pages fill
	 * the record's content keys + record['contentSources'][key]. Best-effort
	 * contract: never throws, missing content simply omits the page section.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	protected function harvestContent( array $record ): array {
		return $record;
	}

	/**
	 * Attribution line for fetched page content, e.g.:
	 *   ''from Wikipedia (CC BY-SA 4.0):''
	 * followed by the content. No line when the content has no recorded
	 * source (hand-written).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function attributed( array $record, string $key, string $content ): string {
		$source = $record['contentSources'][$key] ?? null;
		if ( $source === null ) {
			return $content;
		}
		return "''" . $this->msg( 'embeddablecontent-content-from-' . $source )->text() . "''\n\n" . $content;
	}

	/**
	 * Whether a candidate record is eligible for the Wikidata-hub harvest.
	 * Default: only records the hub itself returned; Special:AddPerson
	 * overrides to true (its dblp/OpenAlex candidates carry hub-derived
	 * wikidata ids and are enriched from Wikidata).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function canHarvest( array $record ): bool {
		return ( $record['provider'] ?? '' ) === 'wikidata';
	}

	// ------------------------------------------------------------- classic pages

	/**
	 * Namespace of the classic wiki page auto-created for a new item, or
	 * null for item-only flows (the default). Subclasses that create pages
	 * also override pageTemplate() and may override pageSkeleton()/
	 * pageEditSummary()/pageSitelinkSummary().
	 */
	protected function pageNamespace(): ?int {
		return null;
	}

	/** Marker left in the first page revision, removed by the finalize step. */
	protected function pagePendingMarker(): string {
		return '__EXTERNAL_LINK_PENDING__';
	}

	/** Template name transcluded by the default page skeleton (no prefix). */
	protected function pageTemplate(): string {
		return '';
	}

	/**
	 * Classic page title for a created item, or null when the kind creates
	 * no page (pageNamespace() null) or the label is unusable as a title
	 * (empty, or containing title-forbidden characters like #).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageTitleForRecord( array $record ): ?Title {
		$ns = $this->pageNamespace();
		if ( $ns === null ) {
			return null;
		}
		$label = trim( $this->primaryLabel( $record ) );
		if ( $label === '' ) {
			return null;
		}
		try {
			// makeTitle takes the namespace ID — newFromText('<ns>:<label>')
			// would need the namespace NAME (the int concatenates into
			// "2008:Label", a main-namespace title).
			$title = Title::makeTitle( $ns, $label );
		} catch ( \Throwable $e ) {
			return null;
		}
		return ( $title !== null && $title->getNamespace() === $ns && $title->isValid() ) ? $title : null;
	}

	/**
	 * Default page skeleton: the kind's template + the item description as an
	 * == Overview == placeholder when one is available. Only sections with
	 * content are rendered — no blank scaffolds, no See also (the page-flow
	 * convention). Subclasses with richer defaults (AddSoftware's FOSS
	 * infobox + logo parameter, the content-driven Source/Person skeletons)
	 * override.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		$template = $this->pageTemplate();
		$body = $template !== '' ? "{{" . $template . "}}\n\n" : '';
		$overview = trim( (string)( $record['description'] ?? '' ) );
		if ( $overview !== '' ) {
			$body .= "== Overview ==\n\n{$overview}\n\n";
		}
		return $body . $marker;
	}

	/** Edit-summary message for the page creation. */
	protected function pageEditSummary( string $label ): string {
		return $this->msg( 'embeddablecontent-page-edit-summary', $label )->inContentLanguage()->text();
	}

	/** Edit-summary message for the page↔item sitelink assertion. */
	protected function pageSitelinkSummary( string $label ): string {
		return $this->msg( 'embeddablecontent-page-sitelink-edit-summary', $label )->inContentLanguage()->text();
	}

	/**
	 * Post-create hook: runs after the item is created (review and manual
	 * paths), before the redirect. Default: when the subclass declares a
	 * pageNamespace, creates the classic wiki page and sitelinks it to the
	 * item, so the page renders the item's statements at view time
	 * (AddSoftware issue #26 pattern); returns the page URL, or the
	 * finalize-step round-trip for a freshly created page. Item-only kinds
	 * keep the item redirect (null).
	 *
	 * @param array<string,mixed> $record
	 * @return string|null redirect target URL, or null for the item redirect
	 */
	protected function afterCreate( string $itemId, array $record ): ?string {
		$title = $this->pageTitleForRecord( $record );
		if ( $title === null ) {
			return null;
		}
		$label = $this->primaryLabel( $record );

		// Sitelink the page ↔ item FIRST: the page's save-time parse must
		// find the link or its wikibase_item page property stays stale
		// ("unexpectedUnconnectedPage") and the infobox renders empty.
		// Page names are stored WITH SPACES (getItemIdForLink normalizes
		// underscores away) — getPrefixedDBkey() would be a silent mismatch.
		// The sitelink must live in the ENTITY REVISION too (wbgetentities
		// reads sitelinks from the revision, not the table) — saving the
		// item writes both: the revision and, via ItemHandler's secondary
		// data update, the sitelink table.
		// Guard: on create-or-skip reuse the item may already carry the link
		// — never rewrite existing sitelink state.
		$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		if ( $item instanceof Item && !$item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $title->getPrefixedText() );
			WikibaseRepo::getEntityStore()->saveEntity(
				$item,
				$this->pageSitelinkSummary( $label ),
				$this->getUser(),
				EDIT_UPDATE
			);
			// ALSO write the sitelink table synchronously: the entity save's
			// secondary data update (ItemHandler::saveLinksOfItem) may run
			// deferred, and the finalize step's parse — which happens in the
			// immediately-following request — reads the TABLE. Diff-based, so
			// re-running it here is a harmless no-op when it already landed.
			WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
		}

		if ( !$title->exists() ) {
			$page = \MediaWiki\MediaWikiServices::getInstance()
				->getWikiPageFactory()->newFromTitle( $title );
			// Revision 1 carries a marker: this request's parse runs before
			// the sitelink is durably visible AND the client's in-process
			// sitelink cache would return the cached negative for it — so it
			// cannot set the wikibase_item property. The redirect target
			// below routes through the complete/<id> step, which re-saves the
			// page in a FRESH request (committed sitelink, empty cache) and
			// removes the marker.
			$content = new \MediaWiki\Content\WikitextContent(
				$this->pageSkeleton( $record, true )
			);
			$status = $page->doUserEditContent(
				$content,
				$this->getUser(),
				$this->pageEditSummary( $label ),
				EDIT_NEW
			);
			if ( !$status->isOK() ) {
				// Page creation failed (e.g. protected namespace): the item
				// still exists — surface the item instead of erroring.
				return null;
			}
			return $this->stepTitle( 'complete/' . $itemId )->getFullURL();
		}

		return $title->getFullURL();
	}

	/**
	 * Finalizes a just-created classic page in a FRESH request: the first
	 * request's parse ran before the sitelink was committed AND the client's
	 * in-process sitelink cache had already cached the negative lookup, so
	 * its wikibase_item page property was left unset. Re-saving the page
	 * here — new process, committed sitelink, empty lookup cache — makes the
	 * re-parse deterministically map the page to the item.
	 *
	 * Idempotent and safe: only touches pages that carry the pending marker
	 * AND whose item is sitelinked to them (both set by the legitimate flow).
	 */
	protected function executeComplete( string $itemId ): void {
		$this->setHeaders();
		// The step performs an edit (finalize the page): login-gated like
		// every other step of the flow (the legitimate flow redirects here
		// from the review/manual submit, so the user is already logged in).
		$this->requireLogin();
		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			$item = null;
		}
		$target = null;
		if ( $item instanceof Item && $item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			$pageName = $item->getSiteLinkList()->getBySiteId( 'wikibase' )->getPageName();
			$title = Title::newFromText( $pageName );
			if ( $title !== null && $title->exists() ) {
				$page = \MediaWiki\MediaWikiServices::getInstance()
					->getWikiPageFactory()->newFromTitle( $title );
				$current = $page->getContent() !== null ? $page->getContent()->getWikitextForTransclusion() : '';
				if ( strpos( $current, $this->pagePendingMarker() ) !== false ) {
					// Strip ONLY the pending marker — the rest of the skeleton
					// (incl. subclass params from afterCreate) stays intact.
					$final = new \MediaWiki\Content\WikitextContent(
						str_replace( "\n<!-- " . $this->pagePendingMarker() . " -->", '', $current )
					);
					$status = $page->doUserEditContent(
						$final,
						$this->getUser(),
						'Completing the page–item link',
						EDIT_UPDATE | EDIT_MINOR
					);
					if ( !$status->isOK() ) {
						// Best-effort: the page still exists with the marker;
						// the contributor can finish the edit by hand.
						$this->getOutput()->redirect( $title->getFullURL() );
						return;
					}
				}
				$target = $title->getFullURL();
			}
		}
		$this->getOutput()->redirect( $target ?? $this->stepTitle()->getFullURL() );
	}

	// ------------------------------------------------------------- shared

	/**
	 * Login gate for the write/abuse surfaces (search submit performs
	 * server-side external fetches, manual submit creates items, the URL
	 * fetch is a server-side external fetch too). Returns an error message
	 * for anonymous/bot sessions, null when logged in. The page LOADS are
	 * deliberately NOT gated (see execute()).
	 */
	protected function loginRequiredError(): ?string {
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
			// Wikibase's term-description limit was raised to 2000 chars on
			// this instance ($wgWBRepoSettings['string-limits']['multilang']
			// ['length'], with the wbt_text.wbx_text column widened to
			// VARBINARY(2000)) — the value is persisted as the item's en
			// term (createOrSkipItem), so the field matches the storage.
			'maxlength' => 2000,
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
	 * Parses a single item id string ("Q42") into an ItemId, or null when
	 * invalid. Lenient contract shared by the entity-combobox fields
	 * (AddSoftware facts, AddSource authors/parent, AddPerson places).
	 */
	protected function parseItemId( string $input ): ?ItemId {
		$input = trim( $input );
		if ( $input === '' ) {
			return null;
		}
		try {
			$id = WikibaseRepo::getEntityIdParser()->parse( $input );
			return $id instanceof ItemId ? $id : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * "YYYY-MM-DD" (HTMLForm date field) -> day-precision TimeValue, or null
	 * when the value is malformed (the date widget validates the shape, so
	 * null only happens for values that bypassed it).
	 */
	protected function dateToTimeValue( string $date ): ?TimeValue {
		$date = trim( $date );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) !== 1 ) {
			return null;
		}
		return new TimeValue(
			sprintf( '+%s-%s-%sT00:00:00Z', $m[1], $m[2], $m[3] ),
			0, 0, 0,
			TimeValue::PRECISION_DAY,
			'http://www.wikidata.org/entity/Q1985727'
		);
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
		// Persist the (harvested or hand-edited) description as the English
		// term — previously it was silently discarded. New-item path only:
		// the create-or-skip reuse above returns early, so an existing item's
		// term is never clobbered. The term limit (2000 on this instance,
		// raised from Wikibase's default 250) matches the form's
		// descriptionFieldSpec maxlength.
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( 'en', $description );
		}

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
	 * Canonical keys excluded from the base citation-metadata statements.
	 * Subclasses that write a field themselves (e.g. Special:AddSource
	 * writes the publisher as an ENTITY value, not the base string) return
	 * the key here so the base never emits the string statement.
	 *
	 * @return string[]
	 */
	protected function citationMetadataFieldExclusions(): array {
		return [];
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
			if ( in_array( $key, $this->citationMetadataFieldExclusions(), true ) ) {
				continue;
			}
			$propertyId = $this->config->citationMetadataPropertyIds()[$key] ?? null;
			if ( $propertyId === null || empty( $record[$field] ) ) {
				continue;
			}
			$specs[$propertyId] = new StringValue( (string)$record[$field] );
		}
		return $specs;
	}

	/**
	 * Local item id whose English label matches exactly (case-insensitive),
	 * or null. Used by create-or-skip and by Special:AddSource's
	 * publisher-field resolution (harvested publisher string → existing
	 * publisher item).
	 */
	protected function findItemIdByLabel( string $label ): ?string {
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

	// ------------------------------------------------------------- entity autofill
	// The autofill-confirm flow: a fetched STRING for an entity-typed field
	// (publisher, journal, license, author, …) is matched against the
	// instance's items — exact label first, then fuzzy (EntityLabelMatcher).
	// A match PRE-FILLS the field and renders a confirmation banner
	// (entityconfirm.js: "we think this corresponds to {label} (Q#)" with
	// Yes / No, let me correct). No good match → the field stays empty and
	// the caller falls back to its plain hint flow.

	/**
	 * Resolves a fetched string to an existing item, or null when there is
	 * no good match (the caller keeps its current flow).
	 *
	 * @param string[] $classItemIds optional instance-of filter
	 * @return array{id:string,label:string,exact:bool}|null
	 */
	protected function resolveEntityField( string $fetched, array $classItemIds = [] ): ?array {
		$fetched = trim( $fetched );
		if ( $fetched === '' || preg_match( '/^Q[1-9]\d*$/i', $fetched ) === 1 ) {
			// Empty, or already an item id — nothing to resolve.
			return null;
		}
		$exact = $this->findItemIdByLabel( $fetched );
		if ( $exact !== null ) {
			return [
				'id' => $exact,
				'label' => $this->entityLabel( $exact ),
				'exact' => true,
			];
		}
		$match = ( new EntityLabelMatcher( null, $this->config->instanceOfPropertyId() ) )
			->findBestMatch( $fetched, $classItemIds );
		if ( $match !== null ) {
			return [
				'id' => $match['itemId'],
				'label' => $match['label'],
				'exact' => false,
			];
		}
		return null;
	}

	/**
	 * Confirmation banner for an entity field auto-filled from fetched
	 * source data: "{field} fetched from source: {value}, we think this
	 * corresponds to {label} ({id})." + [Yes, that's right] / [No, let me
	 * correct]. The field's input name goes in data-field (the rendered
	 * HTMLForm names are "wp" + field key); resources/entityconfirm.js wires
	 * the buttons — "No" clears the field and focuses the combobox.
	 */
	protected function entityConfirmHtml( string $inputName, string $fieldLabel, string $fetched, string $matchedLabel, string $matchedId ): string {
		$line = $this->msg( 'embeddablecontent-entityconfirm-line' )
			->params( $fieldLabel, $fetched, $matchedLabel, $matchedId )
			->escaped();
		$yes = $this->msg( 'embeddablecontent-entityconfirm-yes' )->escaped();
		$no = $this->msg( 'embeddablecontent-entityconfirm-no' )->escaped();
		return '<div class="wb-entity-confirm" data-field="'
			. htmlspecialchars( $inputName, ENT_QUOTES, 'UTF-8' ) . '">'
			. '<span class="wb-entity-confirm-line">' . $line . '</span>'
			. '<span class="wb-entity-confirm-actions">'
			. '<button type="button" class="wb-entity-confirm-yes">' . $yes . '</button>'
			. '<button type="button" class="wb-entity-confirm-no">' . $no . '</button>'
			. '</span></div>';
	}

	/**
	 * English label of an item (best-effort; the id itself when missing) —
	 * for the confirmation banner copy.
	 */
	protected function entityLabel( string $itemId ): string {
		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
			if ( $item instanceof Item ) {
				$term = $item->getLabels()->getByLanguage( 'en' );
				if ( $term !== null ) {
					return $term->getText();
				}
			}
		} catch ( \Throwable $e ) {
			// fall through
		}
		return $itemId;
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
