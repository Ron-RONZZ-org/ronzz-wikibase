<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\QuantityValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Duration;
use EmbeddableContent\Fetch\ProviderResult;
use EmbeddableContent\Fetch\WorkRecord;
use EmbeddableContent\Fetch\YouTubeProvider;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Special:AddSource — create a work item (book / scholarly article / website /
 * song / film / video / YouTube channel / video, plus the child classes
 * bookExcerpt / webpage / YouTube video) from an external authority.
 *
 * Class-first flow (issue #7, follow-up): the page opens on a class picker
 * (Special:AddSource), then routes to the class-scoped search step
 * (Special:AddSource/<classKey>), selection and review — each class carrying
 * its own adapted search and verification fields. Child classes additionally
 * require an existing parent-class item, picked via an entity combobox and
 * linked automatically with a `part of` statement. Every class requires at
 * least one author (entity) at record creation.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddSource extends SpecialAddExternalEntity {

	/**
	 * Classes with no external authority: the picker sends them straight to
	 * the adapted manual form (no search step).
	 */
	private const MANUAL_ONLY_CLASSES = [ 'website', 'webpage', 'bookExcerpt' ];

	/** @var string|null class key selected for the current request */
	private ?string $currentClassKey = null;

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddSource', $config, $client );
	}

	protected function kindKey(): string {
		return 'source';
	}

	/**
	 * Manual-form autofill from the search inputs (issue #35): title/isbn/doi
	 * pass through via the base (same field names); the search's `author`
	 * field maps to the manual `authors` field (the review/manual form calls
	 * the multi-value author list "authors").
	 *
	 * @param array<string,mixed> $search
	 * @return array<string,mixed>
	 */
	protected function autofillRecord( array $search ): array {
		$out = parent::autofillRecord( $search );
		$author = trim( (string)( $search['author'] ?? '' ) );
		if ( $author !== '' ) {
			$out['authors'] = $author;
		}
		return $out;
	}

	protected function classUrlPrefix(): string {
		return $this->currentClassKey ?? '';
	}

	/**
	 * Class-first dispatch: / → class picker, /<classKey> → class-scoped
	 * search (or manual for manual-only classes), /<classKey>/<token>,
	 * /<classKey>/<token>/review/<i>, /<classKey>/manual.
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );

		$parts = explode( '/', trim( (string)$subPage ) );
		$first = $parts[0] ?? '';

		if ( $first === '' || !$this->isSourceClassKey( $first ) ) {
			// Root, or an unknown subpage (e.g. a stale pre-rework token).
			$this->executeClassPicker();
			return;
		}

		$this->currentClassKey = $first;
		$second = $parts[1] ?? '';
		if ( $second === '' ) {
			if ( in_array( $first, self::MANUAL_ONLY_CLASSES, true ) ) {
				$this->getOutput()->redirect( $this->stepTitle( 'manual' )->getFullURL() );
				return;
			}
			$this->executeSearch();
			return;
		}
		if ( $second === 'manual' ) {
			$this->executeManual();
			return;
		}
		if ( $second === 'complete' && ( $parts[2] ?? '' ) !== '' ) {
			// Finalize step for a just-created Source: page (base class).
			$this->executeComplete( $parts[2] );
			return;
		}
		if ( ( $parts[2] ?? '' ) === 'review' && ( $parts[3] ?? '' ) !== '' ) {
			$this->executeReview( $second, (int)$parts[3] );
			return;
		}
		$this->executeSelection( $second );
	}

	// ------------------------------------------------------------- class picker

	private function executeClassPicker(): void {
		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-source-pick-title' )->text() );

		$options = [];
		foreach ( $this->config->sourceClasses() as $key => $_ ) {
			$label = $this->msg( 'embeddablecontent-source-class-' . $key )->text();
			$parentKey = $this->config->sourceParents()[$key] ?? null;
			if ( $parentKey !== null ) {
				$label .= ' ' . $this->msg(
					'embeddablecontent-source-parent-suffix',
					$this->msg( 'embeddablecontent-source-class-' . $parentKey )->text()
				)->text();
			}
			$options[$label] = $key;
		}

		$form = \MediaWiki\HTMLForm\HTMLForm::factory( 'ooui', [
			'class' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-source-pick-label',
				'options' => $options,
				'required' => true,
			],
			// Manual entry on the picker itself (issue #35): check it and
			// Continue routes straight to /<classKey>/manual — the search
			// step is skipped (for manual-only classes it is a no-op).
			'manual' => [
				'type' => 'check',
				'label-message' => 'embeddablecontent-source-pick-manual',
				'help-message' => 'embeddablecontent-source-pick-manual-help',
				'default' => false,
			],
		], $this->getContext() );
		$form->setTitle( $this->getPageTitle() )
			->setSubmitTextMsg( 'embeddablecontent-source-pick-continue' )
			->setSubmitCallback( [ $this, 'onClassPickerSubmit' ] )
			->setSubmitID( 'wb-ext-add-pick-class' )
			->setWrapperLegendMsg( 'embeddablecontent-source-pick-legend' );
		$form->show();
	}

	/**
	 * @param array<string,mixed> $data
	 * @return bool|string
	 */
	public function onClassPickerSubmit( array $data ) {
		$classKey = (string)( $data['class'] ?? '' );
		if ( !$this->isSourceClassKey( $classKey ) ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}
		$sub = in_array( $classKey, self::MANUAL_ONLY_CLASSES, true ) || !empty( $data['manual'] )
			? $classKey . '/manual'
			: $classKey;
		$this->getOutput()->redirect( $this->getPageTitle( $sub )->getFullURL() );
		return true;
	}

	private function isSourceClassKey( string $key ): bool {
		return isset( $this->config->sourceClasses()[$key] );
	}

	// ------------------------------------------------------------- step 1 (class-scoped)

	protected function buildSearchFields(): array {
		switch ( $this->currentClassKey ) {
			case 'book':
				return $this->titleAuthorFields() + [ 'isbn' => [
					'type' => 'text',
					'label-message' => 'embeddablecontent-extsearch-isbn',
					'required' => false,
					'maxlength' => 17,
					'placeholder' => '978-0-00-000000-0',
				] ];
			case 'scholarlyArticle':
				return $this->titleAuthorFields() + [ 'doi' => [
					'type' => 'text',
					'label-message' => 'embeddablecontent-extsearch-doi',
					'required' => false,
					'maxlength' => 250,
					'placeholder' => '10.1000/xxxx',
				] ];
			case 'film':
			case 'song':
			case 'video':
				return $this->titleAuthorFields();
			case 'youtubeChannel':
			case 'youtubeVideo':
				return [ 'query' => [
					'type' => 'text',
					'label-message' => 'embeddablecontent-extsearch-youtube',
					'help-message' => 'embeddablecontent-extsearch-youtube-help',
					'required' => true,
					'maxlength' => 500,
				] ];
			default:
				return [];
		}
	}

	/** @return array<string,mixed> */
	private function titleAuthorFields(): array {
		return [
			'title' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-title',
				'required' => false,
				'maxlength' => 250,
			],
			'author' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-author',
				'required' => false,
				'maxlength' => 250,
				'help-message' => 'embeddablecontent-extsearch-author-help',
			],
			'authorMode' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-extsearch-author-mode',
				'options-messages' => [
					'embeddablecontent-extsearch-author-mode-text' => 'text',
					'embeddablecontent-extsearch-author-mode-entity' => 'entity',
				],
				'default' => 'text',
			],
		];
	}

	protected function search( array $data ): ProviderResult {
		switch ( $this->currentClassKey ) {
			case 'book':
				$isbn = trim( (string)( $data['isbn'] ?? '' ) );
				if ( $isbn !== '' ) {
					return $this->client->byIsbn( $isbn );
				}
				return $this->searchByTitleAuthor( $data );
			case 'scholarlyArticle':
				$doi = trim( (string)( $data['doi'] ?? '' ) );
				if ( $doi !== '' ) {
					return $this->client->byDoi( $doi );
				}
				return $this->searchByTitleAuthor( $data );
			case 'film':
			case 'song':
			case 'video':
				return $this->searchByTitleAuthor( $data );
			case 'youtubeChannel':
			case 'youtubeVideo':
				return $this->searchYouTube( $data );
			default:
				return new ProviderResult( [], [ $this->msg( 'embeddablecontent-extsearch-nohits' )->text() ] );
		}
	}

	private function searchByTitleAuthor( array $data ): ProviderResult {
		$title = trim( (string)( $data['title'] ?? '' ) );
		$author = trim( (string)( $data['author'] ?? '' ) );
		if ( $author !== '' ) {
			if ( ( $data['authorMode'] ?? 'text' ) === 'entity' ) {
				$qids = array_values( array_filter(
					ItemIdList::split( $author ),
					static fn ( string $id ): bool => preg_match( '/^Q[1-9]\d*$/i', $id ) === 1
				) );
				if ( $qids === [] ) {
					return new ProviderResult( [], [ 'Entity-mode author search needs Wikidata Q-ids (e.g. Q42, Q179)' ] );
				}
				return $this->client->searchWorksByAuthorEntities( $qids, $title );
			}
			return $this->client->searchWorksByAuthorName( $author, $title );
		}
		if ( $title === '' ) {
			return new ProviderResult( [], [ 'No title or author given' ] );
		}
		return $this->client->searchWorks( $title );
	}

	/**
	 * YouTube search: a URL resolves EXACTLY (no match → localized "no match
	 * for the provided URL"), a name runs the capped search.list.
	 */
	private function searchYouTube( array $data ): ProviderResult {
		$query = trim( (string)( $data['query'] ?? '' ) );
		if ( $query === '' ) {
			return new ProviderResult( [], [ $this->msg( 'embeddablecontent-extsearch-nohits' )->text() ] );
		}
		if ( YouTubeProvider::isVideoUrl( $query ) || YouTubeProvider::isChannelUrl( $query ) ) {
			$result = $this->client->byYouTubeUrl( $query );
			if ( $result->records === [] && $result->warnings === [] ) {
				return new ProviderResult( [], [ $this->msg( 'embeddablecontent-extsearch-youtube-urlnomatch' )->text() ] );
			}
			return $result;
		}
		return $this->currentClassKey === 'youtubeChannel'
			? $this->client->searchYouTubeChannels( $query )
			: $this->client->searchYouTubeVideos( $query );
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		return (string)( $record['title'] ?? '' );
	}

	/** Class-specific authority identifiers (per-class review/create). */
	protected function externalIdRecordMap(): array {
		switch ( $this->currentClassKey ) {
			case 'book':
				return [ 'wikidata' => 'wikidataId', 'isbn' => 'isbn' ];
			case 'scholarlyArticle':
				return [ 'wikidata' => 'wikidataId', 'doi' => 'doi', 'openalex' => 'openalexId', 'pubmed' => 'pubmedId' ];
			case 'film':
			case 'song':
			case 'video':
				return [ 'wikidata' => 'wikidataId' ];
			default:
				return [];
		}
	}

	protected function harvest( string $qid ): ProviderResult {
		return $this->client->harvestWork( $qid );
	}

	protected function reviewFieldSpecs( array $record ): array {
		$fields = $this->labelFieldSpec( 'title', 'embeddablecontent-extsearch-title', (string)( $record['title'] ?? '' ) )
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ $this->authorsFieldSpec( $record );

		switch ( $this->currentClassKey ) {
			case 'book':
				$fields['publisher'] = $this->publisherFieldSpec( $record );
				$fields['pages'] = $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) );
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->accessFieldSpec( $record );
				break;
			case 'scholarlyArticle':
				$fields['containerTitle'] = $this->plainTextField( 'embeddablecontent-field-publishedin', (string)( $record['containerTitle'] ?? '' ) );
				$fields['publisher'] = $this->publisherFieldSpec( $record );
				$fields['volume'] = $this->plainTextField( 'embeddablecontent-field-volume', (string)( $record['volume'] ?? '' ) );
				$fields['issue'] = $this->plainTextField( 'embeddablecontent-field-issue', (string)( $record['issue'] ?? '' ) );
				$fields['pages'] = $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) );
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->accessFieldSpec( $record );
				break;
			case 'film':
			case 'song':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->durationFieldSpec( $record );
				$fields += $this->accessFieldSpec( $record );
				break;
			case 'video':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->durationFieldSpec( $record );
				$fields += $this->urlFieldSpec( $record );
				break;
			case 'youtubeChannel':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->urlFieldSpec( $record );
				$fields['youtubeChannelId'] = $this->plainTextField(
					'embeddablecontent-source-field-youtubechannelid',
					(string)( $record['youtubeChannelId'] ?? '' ),
					64
				);
				break;
			case 'youtubeVideo':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->durationFieldSpec( $record );
				$fields += $this->urlFieldSpec( $record );
				$fields['youtubeVideoId'] = $this->plainTextField(
					'embeddablecontent-source-field-youtubevideoid',
					(string)( $record['youtubeVideoId'] ?? '' ),
					16
				);
				break;
			case 'website':
			case 'webpage':
				$fields += $this->urlFieldSpec( $record );
				$fields += $this->issuedYearFieldSpec( $record );
				break;
			case 'bookExcerpt':
				$fields['pages'] = $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) );
				$fields['volume'] = $this->plainTextField( 'embeddablecontent-field-volume', (string)( $record['volume'] ?? '' ) );
				$fields['chapters'] = $this->plainTextField( 'embeddablecontent-source-field-chapters', (string)( $record['chapters'] ?? '' ) );
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->accessFieldSpec( $record );
				// The description auto-generates from pages/volume + the
				// parent book when left blank; year/authors fall back to the
				// parent book's own statements (see beforeCreate).
				$fields['description']['help'] = $this->msg(
					'embeddablecontent-source-bookexcerpt-desc-help'
				)->parse();
				$parentLabel = $this->parentClassLabel();
				$sameAsParent = $this->msg(
					'embeddablecontent-source-bookexcerpt-sameparent-help',
					$parentLabel
				)->parse();
				$fields['issuedYear']['help'] = $sameAsParent;
				$fields['authors']['help'] = ( $fields['authors']['help'] ?? '' ) . ' ' . $sameAsParent;
				// Authors may be left blank: they (like the year) are
				// inferred from the parent book in beforeCreate, so the
				// HTMLForm required flag must not block the submit.
				$fields['authors']['required'] = false;
				break;
		}

		$fields += $this->externalIdFieldSpecs( $record );
		$fields += $this->parentFieldSpec( $record );
		return $fields;
	}

	/** @return array<string,mixed> */
	private function authorsFieldSpec( array $record ): array {
		return [ 'authors' => [
			'type' => 'combobox',
			'options' => [],
			'label-message' => 'embeddablecontent-source-field-authors',
			'cssclass' => 'wb-entity-combobox wb-entity-combobox-multi',
			'default' => (string)( $record['authors'] ?? '' ),
			'help' => $this->msg( 'embeddablecontent-source-field-authors-help' )->parse(),
			'required' => true,
		] ];
	}

	/** @return array<string,mixed> */
	private function issuedYearFieldSpec( array $record ): array {
		return [ 'issuedYear' => [
			'type' => 'text',
			'label-message' => 'embeddablecontent-field-year',
			'default' => (string)( $record['issuedYear'] ?? '' ),
			'maxlength' => 4,
		] ];
	}

	/** @return array<string,mixed> */
	private function durationFieldSpec( array $record ): array {
		$seconds = (int)( $record['durationSeconds'] ?? 0 );
		return [ 'duration' => [
			'type' => 'text',
			'label-message' => 'embeddablecontent-source-field-duration',
			'default' => $seconds > 0 ? Duration::formatSeconds( $seconds ) : '',
			'placeholder' => 'MM:SS or HH:MM:SS',
			'maxlength' => 12,
			'help' => $this->msg( 'embeddablecontent-source-field-duration-help' )->parse(),
		] ];
	}

	/** @return array<string,mixed> */
	private function urlFieldSpec( array $record ): array {
		return [ 'url' => [
			'type' => 'url',
			'label-message' => 'embeddablecontent-source-field-url',
			'default' => (string)( $record['url'] ?? '' ),
			'maxlength' => 250,
		] ];
	}

	/**
	 * Publisher field (entity-only, issue #35): an entity combobox
	 * referencing an existing publisher item. A harvested STRING publisher
	 * (Open Library etc.) is resolved to a local item by exact label when
	 * one exists; otherwise the string is shown as context with a
	 * "create the item first" hint (AddSoftware harvested-fact pattern) —
	 * the submitted value must be an item id.
	 *
	 * @return array<string,mixed>
	 */
	private function publisherFieldSpec( array $record ): array {
		$harvested = (string)( $record['publisher'] ?? '' );
		$default = '';
		$help = '';
		if ( $harvested !== '' && preg_match( '/^Q[1-9]\d*$/i', $harvested ) !== 1 ) {
			$resolved = $this->findItemIdByLabel( $harvested );
			if ( $resolved !== null ) {
				$default = $resolved;
			} else {
				// Plain text, HTML-escaped: the value comes from an external
				// API and must never inject markup.
				$help = htmlspecialchars(
					$this->msg( 'embeddablecontent-source-field-publisher-unresolved', $harvested )->text()
				);
			}
		} elseif ( $harvested !== '' ) {
			$default = $harvested;
		}
		$field = [
			'type' => 'combobox',
			'options' => [],
			'label-message' => 'embeddablecontent-field-publisher',
			'cssclass' => 'wb-entity-combobox',
			'default' => $default,
			'help' => $this->msg( 'embeddablecontent-source-field-publisher-help' )->parse(),
		];
		if ( $help !== '' ) {
			$field['help'] .= ' ' . $help;
		}
		return $field;
	}

	/**
	 * Access field group (issue #35): an `accessMode` toggle between
	 *  - url      — a non-direct access URL (landing page), no license;
	 *  - download — a direct download link, auto-fetched and saved server-side;
	 *  - file     — a local file from the browser.
	 * The download/file modes expand the `license` field (entity combobox,
	 * reusing the P275-aligned license property) with the copyright warning.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	private function accessFieldSpec( array $record ): array {
		$mode = (string)( $record['accessMode'] ?? 'url' );
		return [
			'accessMode' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-source-field-access-mode',
				'options-messages' => [
					'embeddablecontent-source-field-access-mode-url' => 'url',
					'embeddablecontent-source-field-access-mode-download' => 'download',
					'embeddablecontent-source-field-access-mode-file' => 'file',
				],
				'default' => $mode,
				'help-message' => 'embeddablecontent-source-field-access-mode-help',
			],
			'accessUrl' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-source-field-access-url',
				'default' => (string)( $record['accessUrl'] ?? '' ),
				'maxlength' => 250,
				'hide-if' => [ '!==', 'accessMode', 'url' ],
			],
			'downloadUrl' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-source-field-access-download',
				'default' => (string)( $record['downloadUrl'] ?? '' ),
				'maxlength' => 500,
				'help-message' => 'embeddablecontent-source-field-access-download-help',
				'hide-if' => [ '!==', 'accessMode', 'download' ],
			],
			'accessFile' => [
				'type' => 'file',
				'label-message' => 'embeddablecontent-source-field-access-file',
				'hide-if' => [ '!==', 'accessMode', 'file' ],
			],
			// Required is NOT set here: the license is only mandatory in the
			// download/file modes, and HTMLForm validates required fields
			// even when hide-if hides them client-side — the requirement is
			// enforced in beforeCreate (validateAccessField) instead.
			'license' => [
				'type' => 'combobox',
				'options' => [],
				'label-message' => 'embeddablecontent-source-field-license',
				'cssclass' => 'wb-entity-combobox',
				'default' => (string)( $record['license'] ?? '' ),
				'help' => $this->msg( 'embeddablecontent-source-field-license-help' )->parse(),
				'hide-if' => [ '===', 'accessMode', 'url' ],
			],
		];
	}

	/**
	 * Parent-class combobox for child classes (bookExcerpt→book,
	 * youtubeVideo→youtubeChannel, webpage→website), with the
	 * "not yet imported? import it yourself" line.
	 *
	 * @return array<string,mixed>
	 */
	private function parentFieldSpec( array $record ): array {
		$parentKey = $this->config->sourceParents()[$this->currentClassKey] ?? null;
		if ( $parentKey === null ) {
			return [];
		}
		$parentLabel = $this->msg( 'embeddablecontent-source-class-' . $parentKey )->text();
		return [ 'parent' => [
			'type' => 'combobox',
			'options' => [],
			'label-message' => 'embeddablecontent-source-field-parent',
			'cssclass' => 'wb-entity-combobox',
			'default' => (string)( $record['parent'] ?? '' ),
			'help' => $this->msg( 'embeddablecontent-source-parent-help', $parentLabel, $parentKey )->parse(),
			'required' => true,
		] ];
	}

	protected function classOptions(): array {
		$key = $this->currentClassKey;
		$id = $this->config->sourceClasses()[$key] ?? null;
		return $id === null ? [] : [ $key => $id ];
	}

	protected function defaultClassItemId( array $record ): ?string {
		return $this->config->sourceClasses()[$this->currentClassKey] ?? null;
	}

	protected function executeManual(): void {
		if ( $this->currentClassKey === 'website' ) {
			$this->getOutput()->addHTML(
				\MediaWiki\Html\Html::warningBox( $this->msg( 'embeddablecontent-source-website-explanation' )->parse() )
			);
		}
		parent::executeManual();
	}

	// ------------------------------------------------------------- classic page
	// The base afterCreate() writes a sitelinked Source:<label> page (the
	// issue-#26 AddSoftware pattern); this class declares the page facts.
	// bookExcerpt gets NO page — it is part of a book.

	/** Per-class template transcluded by the Source: page skeleton. */
	private const SOURCE_PAGE_TEMPLATES = [
		'book' => 'Book',
		'scholarlyArticle' => 'ScholarlyArticle',
		'website' => 'Website',
		'song' => 'Song',
		'film' => 'Film',
		'video' => 'Video',
		'youtubeChannel' => 'YouTubeChannel',
		'youtubeVideo' => 'YouTubeVideo',
		'webpage' => 'Webpage',
	];

	protected function pageNamespace(): ?int {
		return defined( 'NS_SOURCE' ) ? NS_SOURCE : null;
	}

	/**
	 * No classic page for bookExcerpt (a part of a book, not a standalone
	 * work); every other source class gets a Source: page.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageTitleForRecord( array $record ): ?Title {
		if ( $this->currentClassKey === 'bookExcerpt' ) {
			return null;
		}
		return parent::pageTitleForRecord( $record );
	}

	protected function pageTemplate(): string {
		return self::SOURCE_PAGE_TEMPLATES[$this->currentClassKey] ?? '';
	}

	/**
	 * Source: page skeleton — prose lives on the page, facts in the item.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$template = $this->pageTemplate();
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		if ( $template === '' ) {
			return $marker;
		}
		return "{{" . $template . "}}\n\n== Overview ==\n\n<!-- What this source is and where it comes from. -->\n\n"
			. "== Content ==\n\n== See also ==\n" . $marker;
	}

	// ------------------------------------------------------------- validation

	/**
	 * Cross-field validation before creation (review AND manual paths):
	 * duration format, author entities (≥1, agent-class), parent item
	 * (child classes: exists and is instance of the parent class), and the
	 * YouTube id derived from the URL when not typed. Book excerpts also
	 * auto-generate their description and infer year/authors from the parent
	 * book when left blank (BEFORE the author validation, so an inferred
	 * author set passes). Returning a non-null string aborts the creation
	 * with the message as a form error.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function beforeCreate( array &$record ): ?string {
		$rawDuration = trim( (string)( $record['duration'] ?? '' ) );
		if ( $rawDuration !== '' ) {
			$seconds = Duration::parseSeconds( $rawDuration );
			if ( $seconds === null ) {
				return $this->msg( 'embeddablecontent-source-error-duration' )->text();
			}
			$record['durationSeconds'] = $seconds;
		}

		// Access field (issue #35): license + upload for the download/file
		// modes; a filled-in access field that cannot be honoured aborts the
		// creation with a form error (never silent).
		$error = $this->validateAccessField( $record );
		if ( $error !== null ) {
			return $error;
		}

		// bookExcerpt: description autogen + year/authors from the parent
		// book when blank — before validateAuthors (blank authors must be
		// inferred, not rejected).
		if ( $this->currentClassKey === 'bookExcerpt' ) {
			$error = $this->fillBookExcerptFromParent( $record );
			if ( $error !== null ) {
				return $error;
			}
		}

		$error = $this->validateAuthors( $record );
		if ( $error !== null ) {
			return $error;
		}

		// Derive the YouTube identifier from the URL when not typed.
		if ( $this->currentClassKey === 'youtubeChannel' && empty( $record['youtubeChannelId'] ) ) {
			$record['youtubeChannelId'] = YouTubeProvider::extractChannelId( (string)( $record['url'] ?? '' ) ) ?? '';
		}
		if ( $this->currentClassKey === 'youtubeVideo' && empty( $record['youtubeVideoId'] ) ) {
			$record['youtubeVideoId'] = YouTubeProvider::extractVideoId( (string)( $record['url'] ?? '' ) ) ?? '';
		}

		return $this->validateParent( $record );
	}

	/**
	 * Access field validation + upload (issue #35): in the `url` mode only
	 * the URL field exists (validated by the form's url type); the
	 * `download` and `file` modes require a license entity and produce the
	 * uploaded file (server-side fetch for download, browser file for file),
	 * stored as File:<label>.<ext> with auto-generated page text — the
	 * original filename is ignored. Mutates $record: accessMode normalized,
	 * license serialized, fileTitle set on upload.
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	private function validateAccessField( array &$record ): ?string {
		$mode = (string)( $record['accessMode'] ?? 'url' );
		if ( !in_array( $mode, [ 'url', 'download', 'file' ], true ) ) {
			$mode = 'url';
		}
		$record['accessMode'] = $mode;
		if ( $mode === 'url' ) {
			return null;
		}

		$licenseId = trim( (string)( $record['license'] ?? '' ) );
		$licenseItem = $this->parseItemId( $licenseId );
		if ( $licenseItem === null ) {
			return $this->msg( 'embeddablecontent-source-error-license' )->text();
		}
		$record['license'] = $licenseItem->getSerialization();

		try {
			if ( $mode === 'file' ) {
				$title = $this->uploadAccessFileFromRequest( $record );
				if ( $title === null ) {
					$upload = $this->getRequest()->getUpload( 'wpAccessFile' );
					if ( $upload instanceof \MediaWiki\Request\WebRequestUpload && $upload->getSize() > 0 ) {
						return $this->msg( 'embeddablecontent-source-error-access-upload', 'unsupported file type' )->text();
					}
					return $this->msg( 'embeddablecontent-source-error-access-file-required' )->text();
				}
			} else {
				$url = ( new FragmentSanitizer() )->validateUrl( (string)( $record['downloadUrl'] ?? '' ) );
				if ( $url === null ) {
					return $this->msg( 'embeddablecontent-source-error-access-upload', 'invalid or unreachable URL' )->text();
				}
				$title = $this->uploadAccessFileFromUrl( $url, $record );
				if ( $title === null ) {
					return $this->msg( 'embeddablecontent-source-error-access-upload', 'unreachable or unsupported URL' )->text();
				}
			}
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-source-error-access-upload', $e->getMessage() )->text();
		}
		if ( $title !== null ) {
			$record['fileTitle'] = $title->getDBkey();
		}
		return null;
	}

	/**
	 * Local-file access upload (accessMode=file). Returns the file title, or
	 * null when no file was provided (the caller distinguishes "no file"
	 * from a failed verification).
	 *
	 * @param array<string,mixed> $record
	 */
	private function uploadAccessFileFromRequest( array $record ): ?\MediaWiki\Title\Title {
		$request = $this->getRequest();
		$upload = $request->getUpload( 'wpAccessFile' );
		if ( !$upload instanceof \MediaWiki\Request\WebRequestUpload
			|| $upload->getSize() <= 0 || $upload->getTempName() === ''
		) {
			return null;
		}
		$tempPath = $upload->getTempName();
		$mime = \MediaWiki\MediaWikiServices::getInstance()
			->getMimeAnalyzer()->guessMimeType( $tempPath, false );
		$destName = $this->accessDestName( $record, $upload->getName(), $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new \MediaWiki\Upload\UploadFromFile();
		$base->initializePathInfo( $destName, $tempPath, $upload->getSize() );
		return $this->performAccessUpload( $base, $record );
	}

	/**
	 * Direct-download access upload (accessMode=download) — goes through
	 * UploadFromUrl so the instance's SSRF guards (IsUploadAllowedFromUrl)
	 * apply. Returns the file title, or null when the fetch failed.
	 *
	 * @param array<string,mixed> $record
	 */
	private function uploadAccessFileFromUrl( string $url, array $record ): ?\MediaWiki\Title\Title {
		if ( !\MediaWiki\Upload\UploadFromUrl::isAllowed( $this->getUser() ) ) {
			return null;
		}
		$path = parse_url( $url, PHP_URL_PATH );
		$name = $path !== false && $path !== null && $path !== '' ? basename( $path ) : 'file';
		$mime = $this->mimeFromUrl( $url );
		$destName = $this->accessDestName( $record, $name, $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new \MediaWiki\Upload\UploadFromUrl();
		$base->initialize( $destName, $url );
		$tempPath = $base->getTempPath();
		if ( $tempPath === '' || !is_file( $tempPath ) ) {
			return null;
		}
		return $this->performAccessUpload( $base, $record );
	}

	/**
	 * Best-effort remote MIME probe (HEAD) for download-mode files; '' when
	 * the probe fails (the destination-name extension fallback applies).
	 */
	private function mimeFromUrl( string $url ): string {
		try {
			$http = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory()
				->create( $url, [], __METHOD__ );
			$http->execute();
			return (string)$http->getResponseHeader( 'Content-Type' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Computes the destination file name "<label>.<ext>" for the access
	 * upload: the extension comes from the original name, restricted to the
	 * instance's configured $wgFileExtensions allow-list, with a fallback to
	 * the MIME type's canonical extension. The original filename is
	 * otherwise IGNORED (the file is named after the item, issue #35).
	 * Returns '' when the format is unsupported or the label is unusable.
	 *
	 * @param array<string,mixed> $record
	 */
	private function accessDestName( array $record, string $originalName, string $mime ): string {
		$allowed = \MediaWiki\MediaWikiServices::getInstance()
			->getMainConfig()->get( 'FileExtensions' );
		$allowed = is_array( $allowed ) ? array_map( 'strtolower', $allowed ) : [];
		$ext = strtolower( (string)pathinfo( $originalName, PATHINFO_EXTENSION ) );
		if ( !in_array( $ext, $allowed, true ) ) {
			$ext = strtolower( (string)( \MediaWiki\MediaWikiServices::getInstance()
				->getMimeAnalyzer()->getExtensionFromMimeTypeOrNull( $mime ) ?? '' ) );
		}
		if ( $ext === '' || !in_array( $ext, $allowed, true ) ) {
			return '';
		}
		$label = (string)preg_replace( '/[#<>\[\]|{}:]/', '', trim( $this->primaryLabel( $record ) ) );
		$label = trim( (string)preg_replace( '/\s+/', ' ', $label ) );
		if ( $label === '' ) {
			return '';
		}
		return "{$label}.{$ext}";
	}

	/**
	 * Runs verifyUpload + performUpload on a prepared UploadBase for the
	 * access file; the File: page text is auto-generated from the item label
	 * and description (the original filename is not used). Returns the file
	 * title, or null when the target already exists. A FAILED verification
	 * or upload throws — the caller (validateAccessField) surfaces the
	 * reason as a form error instead of silently dropping the file.
	 *
	 * @param array<string,mixed> $record
	 * @throws \RuntimeException when the upload is rejected
	 */
	private function performAccessUpload( \MediaWiki\Upload\UploadBase $base, array $record ): ?\MediaWiki\Title\Title {
		$title = $base->getTitle();
		if ( $title === null ) {
			return null;
		}
		if ( $title->exists() ) {
			return $title; // idempotent: already uploaded on an earlier run
		}
		$verify = $base->verifyUpload();
		if ( ( $verify['status'] ?? null ) !== \MediaWiki\Upload\UploadBase::OK ) {
			$details = $verify['details'] ?? [];
			$detail = is_array( $details ) && $details !== []
				? (string)( $details[0] ?? '' )
				: (string)( $verify['status'] ?? 'rejected' );
			throw new \RuntimeException( 'verifyUpload rejected (' . $detail . ')' );
		}
		$label = $this->primaryLabel( $record );
		$description = trim( (string)( $record['description'] ?? '' ) );
		// Auto-generated File: page text — description/meta-data/file name
		// come from the item, never from the original filename (issue #35).
		$pageText = $this->msg( 'embeddablecontent-source-access-file-page', $label, $description )->text();
		$status = $base->performUpload(
			$this->msg( 'embeddablecontent-source-access-file-edit-summary', $label )->inContentLanguage()->text(),
			$pageText,
			false,
			$this->getUser()
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException( 'performUpload: ' . ( $status->getMessage()->getParams()[0] ?? 'rejected' ) );
		}
		return $title;
	}

	/**
	 * Book-excerpt conveniences (the parent book item is validated by
	 * validateParent, which runs after this): when the description is blank,
	 * auto-generate "Pages a-b (Volume c) of {book}" from the pages/volume
	 * fields and the parent's label; when the year or the authors are blank,
	 * copy them from the parent book's own `date` / `attributed to`
	 * statements. Never errors (the parent was validated) — returns null.
	 *
	 * @param array<string,mixed> $record
	 */
	private function fillBookExcerptFromParent( array &$record ): ?string {
		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( preg_match( '/^Q[1-9]\d*$/', $parentId ) !== 1 ) {
			return null; // missing/invalid parent is validateParent's error
		}
		$parent = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $parentId ) );
		if ( !$parent instanceof Item ) {
			return null;
		}

		if ( trim( (string)( $record['description'] ?? '' ) ) === '' ) {
			$parts = [];
			$pages = trim( (string)( $record['pages'] ?? '' ) );
			if ( $pages !== '' ) {
				$parts[] = $this->msg( 'embeddablecontent-source-bookexcerpt-desc-pages', $pages )->text();
			}
			$volume = trim( (string)( $record['volume'] ?? '' ) );
			if ( $volume !== '' ) {
				$parts[] = $this->msg( 'embeddablecontent-source-bookexcerpt-desc-volume', $volume )->text();
			}
			$parentLabel = '';
			$labelTerm = $parent->getLabels()->getByLanguage( 'en' );
			if ( $labelTerm !== null ) {
				$parentLabel = $labelTerm->getText();
			}
			if ( $parts !== [] && $parentLabel !== '' ) {
				$record['description'] = $this->msg(
					'embeddablecontent-source-bookexcerpt-desc',
					implode( ' ', $parts ),
					$parentLabel
				)->text();
			}
		}

		if ( empty( $record['issuedYear'] ) ) {
			$year = $this->itemYear( $parent );
			if ( $year !== null ) {
				$record['issuedYear'] = $year;
			}
		}
		if ( empty( $record['authors'] ) ) {
			$attributedTo = $this->config->provenancePropertyIds()['attributedTo'] ?? null;
			if ( $attributedTo !== null ) {
				$parentAuthors = $this->itemEntityValues( $parent, $attributedTo );
				if ( $parentAuthors !== [] ) {
					$record['authors'] = implode( ', ', $parentAuthors );
				}
			}
		}
		return null;
	}

	/** Year of an item's `date` (P577-aligned) statement, or null. */
	private function itemYear( Item $item ): ?int {
		$dateProp = $this->config->provenancePropertyIds()['date'] ?? null;
		if ( $dateProp === null ) {
			return null;
		}
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $dateProp ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof TimeValue ) {
				// "+2020-00-00T00:00:00Z" → 2020
				return (int)substr( $value->getTime(), 1, 4 );
			}
		}
		return null;
	}

	/** Entity ids of an item's statements on the given property. */
	private function itemEntityValues( Item $item, string $propertyId ): array {
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$out[] = $value->getEntityId()->getSerialization();
			}
		}
		return $out;
	}

	/** Label of the current class's parent class (e.g. "book"), or ''. */
	private function parentClassLabel(): string {
		$parentKey = $this->config->sourceParents()[$this->currentClassKey] ?? null;
		return $parentKey !== null
			? $this->msg( 'embeddablecontent-source-class-' . $parentKey )->text()
			: '';
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function validateAuthors( array $record ): ?string {
		$ids = ItemIdList::split( (string)( $record['authors'] ?? '' ) );
		if ( $ids === [] ) {
			return $this->msg( 'embeddablecontent-source-error-noauthor' )->text();
		}
		$agentIds = array_values( $this->config->agentClasses() );
		foreach ( $ids as $id ) {
			if ( preg_match( '/^Q[1-9]\d*$/', $id ) !== 1 ) {
				return $this->msg( 'embeddablecontent-add-error-baditemid', $id )->text();
			}
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $id ) );
			if ( !$item instanceof Item ) {
				return $this->msg( 'embeddablecontent-source-error-author-entity', $id )->text();
			}
			if ( !$this->itemHasClass( $item, $agentIds ) ) {
				return $this->msg( 'embeddablecontent-source-error-author-class', $id )->text();
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function validateParent( array $record ): ?string {
		$parentKey = $this->config->sourceParents()[$this->currentClassKey] ?? null;
		if ( $parentKey === null ) {
			return null; // not a child class
		}
		$parentClassId = $this->config->sourceClasses()[$parentKey] ?? null;
		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( $parentClassId === null || preg_match( '/^Q[1-9]\d*$/', $parentId ) !== 1 ) {
			return $this->msg( 'embeddablecontent-source-error-parent-required' )->text();
		}
		$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $parentId ) );
		if ( !$item instanceof Item || !$this->itemHasClass( $item, [ $parentClassId ] ) ) {
			return $this->msg( 'embeddablecontent-source-error-parent-class', $parentId )->text();
		}
		return null;
	}

	/** @param string[] $classItemIds */
	private function itemHasClass( Item $item, array $classItemIds ): bool {
		$propertyId = new NumericPropertyId( $this->config->instanceOfPropertyId() );
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue
				&& in_array( $value->getEntityId()->getSerialization(), $classItemIds, true )
			) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------- creation

	/**
	 * The publisher is written as an ENTITY value (entity-only, issue #35) —
	 * exclude it from the base string citation metadata.
	 *
	 * @return string[]
	 */
	protected function citationMetadataFieldExclusions(): array {
		return [ 'publisher' ];
	}

	/**
	 * Class-aware statement specs (base-class contract): external ids +
	 * citation metadata + duration (seconds, quantity) + item URL + YouTube
	 * identifiers + author entities (attributed to) + the child→parent
	 * `part of` link + the entity publisher + the access facts.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed> property id => DataValue | DataValue[]
	 */
	protected function statementSpecs( array $record ): array {
		$specs = $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
		$props = $this->config->sourcePropertyIds();

		// Publisher: entity-only — the value is an item id (combobox), written
		// as an entity statement on the entity-typed publisher property.
		$publisherId = trim( (string)( $record['publisher'] ?? '' ) );
		$publisherItem = $this->parseItemId( $publisherId );
		$publisherProp = $this->config->citationMetadataPropertyIds()['publisher'] ?? null;
		if ( $publisherItem !== null && $publisherProp !== null ) {
			$specs[$publisherProp] = new EntityIdValue( $publisherItem );
		}

		if ( !empty( $record['durationSeconds'] ) && isset( $props['duration'] ) ) {
			$specs[$props['duration']] = QuantityValue::newFromNumber( (int)$record['durationSeconds'] );
		}

		// Chapters (book excerpts): optional count/range string.
		if ( !empty( $record['chapters'] ) && isset( $props['chapters'] ) ) {
			$specs[$props['chapters']] = new StringValue( (string)$record['chapters'] );
		}

		// Year: publication/creation date at YEAR precision on the shared
		// `date` property (P577-aligned — the citation engine already reads
		// it as publicationDate). Book-excerpt inference copies this
		// statement from the parent book item.
		$year = (int)( $record['issuedYear'] ?? 0 );
		if ( $year > 0 ) {
			$dateProp = $this->config->provenancePropertyIds()['date'] ?? null;
			if ( $dateProp !== null ) {
				$specs[$dateProp] = new TimeValue(
					sprintf( '+%04d-00-00T00:00:00Z', $year ),
					0, 0, 0,
					TimeValue::PRECISION_YEAR,
					'http://www.wikidata.org/entity/Q1985727'
				);
			}
		}

		$url = ( new FragmentSanitizer() )->validateUrl( (string)( $record['url'] ?? '' ) );
		if ( $url !== null && isset( $props['url'] ) ) {
			$specs[$props['url']] = new StringValue( $url );
		}

		if ( !empty( $record['youtubeChannelId'] ) && isset( $props['youtubeChannelId'] ) ) {
			$specs[$props['youtubeChannelId']] = new StringValue( (string)$record['youtubeChannelId'] );
		}
		if ( !empty( $record['youtubeVideoId'] ) && isset( $props['youtubeVideoId'] ) ) {
			$specs[$props['youtubeVideoId']] = new StringValue( (string)$record['youtubeVideoId'] );
		}

		// Access facts (issue #35): non-direct access URL, the uploaded file
		// (File: page URL) and the license entity — all set by
		// validateAccessField before creation.
		$accessUrl = ( new FragmentSanitizer() )->validateUrl( (string)( $record['accessUrl'] ?? '' ) );
		if ( $accessUrl !== null && isset( $props['accessUrl'] ) ) {
			$specs[$props['accessUrl']] = new StringValue( $accessUrl );
		}
		if ( !empty( $record['fileTitle'] ) && isset( $props['file'] ) ) {
			$fileTitle = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['fileTitle'] );
			if ( $fileTitle !== null ) {
				$specs[$props['file']] = new StringValue( $fileTitle->getFullURL() );
			}
		}
		if ( !empty( $record['license'] ) && isset( $props['license'] ) ) {
			$licenseItem = $this->parseItemId( (string)$record['license'] );
			if ( $licenseItem !== null ) {
				$specs[$props['license']] = new EntityIdValue( $licenseItem );
			}
		}

		// Authors: one `attributed to` statement per entity (≥1 enforced in
		// beforeCreate; multi-valued specs write one statement per element).
		$authorIds = ItemIdList::split( (string)( $record['authors'] ?? '' ) );
		$attributedTo = $this->config->provenancePropertyIds()['attributedTo'] ?? null;
		if ( $authorIds !== [] && $attributedTo !== null ) {
			foreach ( $authorIds as $authorId ) {
				$specs[$attributedTo][] = new EntityIdValue( new ItemId( $authorId ) );
			}
		}

		// Child→parent link (`part of`), validated in beforeCreate.
		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( $parentId !== '' && isset( $props['partOf'] ) && preg_match( '/^Q[1-9]\d*$/', $parentId ) === 1 ) {
			$specs[$props['partOf']] = new EntityIdValue( new ItemId( $parentId ) );
		}

		return $specs;
	}

	// ------------------------------------------------------------- duration helpers

	/**
	 * Parses "(HH):MM:SS" (or "MM:SS") into seconds; null on a malformed
	 * value, so the caller can surface a form error.
	 */
	
}
