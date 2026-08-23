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
		], $this->getContext() );
		$form->setTitle( $this->getPageTitle() )
			->setSubmitTextMsg( 'embeddablecontent-extselect-continue' )
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
		$sub = in_array( $classKey, self::MANUAL_ONLY_CLASSES, true )
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
				$fields['publisher'] = $this->plainTextField( 'embeddablecontent-field-publisher', (string)( $record['publisher'] ?? '' ) );
				$fields['pages'] = $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) );
				$fields += $this->issuedYearFieldSpec( $record );
				break;
			case 'scholarlyArticle':
				$fields['containerTitle'] = $this->plainTextField( 'embeddablecontent-field-publishedin', (string)( $record['containerTitle'] ?? '' ) );
				$fields['publisher'] = $this->plainTextField( 'embeddablecontent-field-publisher', (string)( $record['publisher'] ?? '' ) );
				$fields['volume'] = $this->plainTextField( 'embeddablecontent-field-volume', (string)( $record['volume'] ?? '' ) );
				$fields['issue'] = $this->plainTextField( 'embeddablecontent-field-issue', (string)( $record['issue'] ?? '' ) );
				$fields['pages'] = $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) );
				$fields += $this->issuedYearFieldSpec( $record );
				break;
			case 'film':
			case 'song':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->durationFieldSpec( $record );
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
				$fields += $this->issuedYearFieldSpec( $record );
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

	// ------------------------------------------------------------- validation

	/**
	 * Cross-field validation before creation (review AND manual paths):
	 * duration format, author entities (≥1, agent-class), parent item
	 * (child classes: exists and is instance of the parent class), and the
	 * YouTube id derived from the URL when not typed. Returning a non-null
	 * string aborts the creation with the message as a form error.
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
	 * Class-aware statement specs (base-class contract): external ids +
	 * citation metadata + duration (seconds, quantity) + item URL + YouTube
	 * identifiers + author entities (attributed to) + the child→parent
	 * `part of` link.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed> property id => DataValue | DataValue[]
	 */
	protected function statementSpecs( array $record ): array {
		$specs = $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
		$props = $this->config->sourcePropertyIds();

		if ( !empty( $record['durationSeconds'] ) && isset( $props['duration'] ) ) {
			$specs[$props['duration']] = QuantityValue::newFromNumber( (int)$record['durationSeconds'] );
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
