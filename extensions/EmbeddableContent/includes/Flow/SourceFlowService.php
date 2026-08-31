<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use DataValues\QuantityValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\Duration;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Fetch\YouTubeProvider;
use EmbeddableContent\Spec\ItemIdList;
use EmbeddableContent\Spec\LabelSanitizer;
use Wikibase\DataModel\DataValue;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;

/**
 * The entity-mode AddSource pipeline — the logic the action=addsource API
 * module (and through it the MCP embeddable-add-citation-source tool) runs.
 * Pure PHP: no MediaWiki runtime, unit-testable. Mirrors the Special:AddSource
 * validation and statement building for the machine-facing contract, with the
 * field exposure owned by SourceFieldMap so the API surface, the discovery
 * endpoint and the MCP tool can never drift apart.
 *
 * The classic Source: page + sitelink step is MediaWiki-bound and lives in
 * ClassicPageCreator, which the API module calls after a successful create.
 *
 * @license GPL-2.0-or-later
 */
final class SourceFlowService {

	public const ERROR_TITLE_REQUIRED = 'title is required when creating a source item.';
	public const ERROR_NO_AUTHOR = 'At least one author item ID is required (except book-excerpt, which copies the parent book\'s authors when blank). Resolve author names with wikibase-search-entities and pass the item IDs.';
	public const ERROR_PARENT_REQUIRED = 'classKey %s requires parent: an existing item of class %s.';
	public const ERROR_PARENT_CLASS = 'parent "%s" is not an item of class %s.';
	public const ERROR_PARENT_MISSING = 'parent "%s" does not exist.';
	public const ERROR_AUTHOR_CLASS = 'author "%s" is not an agent-class item.';

	/**
	 * Record keys the API contract does not expose but the browser forms'
	 * access flow feeds in (the uploaded file's URL and the license entity).
	 * Accepted by prepare (no exposure check) and written by statementSpecs.
	 */
	private const INTERNAL_FIELDS = [ 'license', 'accessFileUrl' ];

	/**
	 * @param \Closure(string $messageKey, string[] $params): string $message
	 *  Formats i18n messages for CONTENT (the book-excerpt auto-description,
	 *  the class-label disambiguation suffix); validation errors are plain
	 *  English strings returned by this service.
	 */
	public function __construct(
		private readonly EmbeddableContentConfig $config,
		private readonly EntityLookup $lookup,
		private readonly \Closure $message
	) {
	}

	/**
	 * Validates and normalizes a record for create or update. Returns an
	 * error string, or null after mutating $record (durationSeconds,
	 * book-excerpt year/authors/description, derived YouTube ids).
	 *
	 * @param array<string,mixed> $record
	 */
	public function prepare( string $classKey, array &$record, bool $creating ): ?string {
		if ( !in_array( $classKey, SourceFieldMap::CLASS_KEYS, true ) ) {
			return "classKey {$classKey} is not one of " . implode( ', ', SourceFieldMap::CLASS_KEYS ) . '.';
		}

		$unknown = array_diff( array_keys( $record ), array_merge( SourceFieldMap::ALL_FIELDS, self::INTERNAL_FIELDS ) );
		if ( $unknown !== [] ) {
			return 'unknown field(s): ' . implode( ', ', $unknown ) . '.';
		}
		$provided = array_filter(
			$record,
			static fn ( $v ) => $v !== null && $v !== '',
		);
		$disallowed = array_diff(
			array_keys( $provided ),
			array_merge( SourceFieldMap::fieldsForClass( $classKey ), self::INTERNAL_FIELDS )
		);
		if ( $disallowed !== [] ) {
			return "classKey {$classKey} does not expose the field(s) " . implode( ', ', $disallowed )
				. '. Its fields are ' . implode( ', ', SourceFieldMap::fieldsForClass( $classKey ) ) . '.';
		}

		if ( $creating ) {
			foreach ( SourceFieldMap::requiredOnCreate( $classKey ) as $required ) {
				if ( !isset( $provided[$required] ) ) {
					if ( $required === 'title' ) {
						return self::ERROR_TITLE_REQUIRED;
					}
					if ( $required === 'authors' ) {
						return self::ERROR_NO_AUTHOR;
					}
					return self::ERROR_PARENT_REQUIRED;
				}
			}
		}

		$duration = trim( (string)( $record['duration'] ?? '' ) );
		if ( $duration !== '' ) {
			$seconds = Duration::parseSeconds( $duration );
			if ( $seconds === null ) {
				return "duration \"{$duration}\" is not MM:SS or HH:MM:SS.";
			}
			$record['durationSeconds'] = $seconds;
		}

		$year = trim( (string)( $record['year'] ?? '' ) );
		if ( $year !== '' && preg_match( '/^\d{4}$/', $year ) !== 1 ) {
			return "year \"{$year}\" is not a four-digit year.";
		}

		foreach ( [ 'url', 'accessUrl' ] as $urlField ) {
			$url = trim( (string)( $record[$urlField] ?? '' ) );
			if ( $url !== '' && !$this->isHttpUrl( $url ) ) {
				return "{$urlField} \"{$url}\" is not an http(s) URL.";
			}
		}

		$error = $this->fillBookExcerptFromParent( $classKey, $record );
		if ( $error !== null ) {
			return $error;
		}

		$error = $this->validateAuthors( $classKey, $record, $creating );
		if ( $error !== null ) {
			return $error;
		}

		// Derive the YouTube identifier from the URL when not typed.
		$formKey = SourceFieldMap::formKey( $classKey );
		if ( $formKey === 'youtubeChannel' && empty( $record['youtubeChannelId'] ) ) {
			$record['youtubeChannelId'] = YouTubeProvider::extractChannelId( (string)( $record['url'] ?? '' ) ) ?? '';
		}
		if ( $formKey === 'youtubeVideo' && empty( $record['youtubeVideoId'] ) ) {
			$record['youtubeVideoId'] = YouTubeProvider::extractVideoId( (string)( $record['url'] ?? '' ) ) ?? '';
		}

		return $this->validateParent( $classKey, $record, $creating );
	}

	/**
	 * Statement specs (property id => DataValue or DataValue[]) for the
	 * entity-mode record, mirroring Special:AddSource::statementSpecs for the
	 * fields the API accepts (no file/download access — those are browser
	 * uploads). The caller persists them.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string, DataValue|DataValue[]>
	 */
	public function statementSpecs( string $classKey, array $record ): array {
		$specs = $this->externalIdStatements( $classKey, $record )
			+ $this->citationMetadataStatements( $classKey, $record );
		$props = $this->config->sourcePropertyIds();

		$publisherItem = $this->parseItemId( (string)( $record['publisher'] ?? '' ) );
		$publisherProp = $this->config->citationMetadataPropertyIds()['publisher'] ?? null;
		if ( $publisherItem !== null && $publisherProp !== null ) {
			$specs[$publisherProp] = new EntityIdValue( $publisherItem );
		}

		$journalItem = $this->parseItemId( (string)( $record['journal'] ?? '' ) );
		$journalProp = $this->config->citationMetadataPropertyIds()['journal'] ?? null;
		if ( $journalItem !== null && $journalProp !== null ) {
			$specs[$journalProp] = new EntityIdValue( $journalItem );
		}

		if ( !empty( $record['durationSeconds'] ) && isset( $props['duration'] ) ) {
			$specs[$props['duration']] = QuantityValue::newFromNumber( (int)$record['durationSeconds'] );
		}

		if ( !empty( $record['chapters'] ) && isset( $props['chapters'] ) ) {
			$specs[$props['chapters']] = new StringValue( (string)$record['chapters'] );
		}

		$year = (int)( $record['year'] ?? 0 );
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

		$url = trim( (string)( $record['url'] ?? '' ) );
		if ( $url !== '' && isset( $props['url'] ) && $this->isHttpUrl( $url ) ) {
			$specs[$props['url']] = new StringValue( $url );
		}
		if ( !empty( $record['youtubeChannelId'] ) && isset( $props['youtubeChannelId'] ) ) {
			$specs[$props['youtubeChannelId']] = new StringValue( (string)$record['youtubeChannelId'] );
		}
		if ( !empty( $record['youtubeVideoId'] ) && isset( $props['youtubeVideoId'] ) ) {
			$specs[$props['youtubeVideoId']] = new StringValue( (string)$record['youtubeVideoId'] );
		}
		$accessUrl = trim( (string)( $record['accessUrl'] ?? '' ) );
		if ( $accessUrl !== '' && isset( $props['accessUrl'] ) && $this->isHttpUrl( $accessUrl ) ) {
			$specs[$props['accessUrl']] = new StringValue( $accessUrl );
		}
		// Access facts from the browser forms (the uploaded file's URL and the
		// license entity) — internal record keys, not part of the API contract.
		$fileUrl = trim( (string)( $record['accessFileUrl'] ?? '' ) );
		if ( $fileUrl !== '' && isset( $props['file'] ) && $this->isHttpUrl( $fileUrl ) ) {
			$specs[$props['file']] = new StringValue( $fileUrl );
		}
		$licenseId = trim( (string)( $record['license'] ?? '' ) );
		if ( $licenseId !== '' && isset( $props['license'] ) && preg_match( '/^Q[1-9]\d*$/', $licenseId ) === 1 ) {
			$specs[$props['license']] = new EntityIdValue( new ItemId( $licenseId ) );
		}

		$authorIds = ItemIdList::split( (string)( $record['authors'] ?? '' ) );
		$attributedTo = $this->config->provenancePropertyIds()['attributedTo'] ?? null;
		if ( $authorIds !== [] && $attributedTo !== null ) {
			foreach ( $authorIds as $authorId ) {
				$specs[$attributedTo][] = new EntityIdValue( new ItemId( $authorId ) );
			}
		}

		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( $parentId !== '' && isset( $props['partOf'] ) && preg_match( '/^Q[1-9]\d*$/', $parentId ) === 1 ) {
			$specs[$props['partOf']] = new EntityIdValue( new ItemId( $parentId ) );
		}

		return $specs;
	}

	/**
	 * The item's label for a record: the title, disambiguated with the
	 * English class suffix ("The Hobbit" → "The Hobbit (Book)"). Updates
	 * keep the stored label verbatim (no suffix), matching the Update* flow.
	 *
	 * @param array<string,mixed> $record
	 */
	public function labelFor( string $classKey, array $record, bool $suffixed = true ): string {
		$title = LabelSanitizer::stripMarkup( trim( (string)( $record['title'] ?? '' ) ) );
		if ( !$suffixed ) {
			return $title;
		}
		$suffix = $this->classLabelSuffix( $classKey );
		if ( $suffix === '' || $title === '' ) {
			return $title;
		}
		if ( str_ends_with( strtolower( $title ), strtolower( $suffix ) ) ) {
			return $title;
		}
		return $title . $suffix;
	}

	/**
	 * The classic Source: page spec for a class, or null when the class
	 * creates no page (book-excerpt is part of its book).
	 */
	public function pageSpecFor( string $classKey ): ?ClassicPageSpec {
		if ( !defined( 'NS_SOURCE' ) ) {
			return null;
		}
		$formKey = SourceFieldMap::formKey( $classKey );
		$templates = [
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
		$template = $templates[$formKey] ?? '';
		if ( $template === '' ) {
			return null;
		}
		return new ClassicPageSpec( NS_SOURCE, $template );
	}

	// ------------------------------------------------------------- building

	/** Builds a new Item from a prepared record (label + description + specs). */
	public function buildItem( string $classKey, array $record ): Item {
		$item = new Item();
		$item->setLabel( 'en', $this->labelFor( $classKey, $record ) );
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( 'en', $description );
		}
		foreach ( $this->statementSpecs( $classKey, $record ) as $propertyId => $value ) {
			foreach ( is_array( $value ) ? $value : [ $value ] as $single ) {
				$item->getStatements()->addNewStatement(
					new PropertyValueSnak( $this->propertyId( $propertyId ), $single )
				);
			}
		}
		$classItemId = $this->config->sourceClasses()[SourceFieldMap::formKey( $classKey )] ?? null;
		if ( $classItemId !== null ) {
			$item->getStatements()->addNewStatement(
				new PropertyValueSnak(
					$this->propertyId( $this->config->instanceOfPropertyId() ),
					new EntityIdValue( new ItemId( $classItemId ) )
				)
			);
		}
		return $item;
	}

	/**
	 * The managed properties an update touches: the statement properties for
	 * fields provided with a new non-empty value. Blank fields keep the
	 * existing statements (no-clobber, matching the Update* flow).
	 *
	 * @param array<string,mixed> $record
	 * @return string[] property ids to replace on update
	 */
	public function managedPropertyIds( string $classKey, array $record ): array {
		$provided = array_filter( $record, static fn ( $v ) => $v !== null && $v !== '' );
		$ids = [];
		foreach ( $this->statementSpecs( $classKey, $provided ) as $propertyId => $_ ) {
			$ids[] = $propertyId;
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Applies an update to an existing item, no-clobber: statements on the
	 * managed properties (those the record provides a new non-empty value
	 * for) are replaced, every other statement is kept, and the class
	 * (instance-of) is never changed. The en label and description are
	 * replaced when title / description are provided (a blank description
	 * keeps the existing one). Mutates $item in place; the caller persists.
	 *
	 * @param array<string,mixed> $record
	 */
	public function applyUpdate( string $classKey, Item $item, array $record ): void {
		$managed = $this->managedPropertyIds( $classKey, $record );
		if ( $managed !== [] ) {
			// Rebuild the list without the managed properties (setStatements
			// is stable across data-model versions; removeStatement is not).
			$kept = new \Wikibase\DataModel\Statement\StatementList();
			foreach ( $item->getStatements() as $statement ) {
				if ( !in_array( $statement->getPropertyId()->getSerialization(), $managed, true ) ) {
					$kept->addStatement( $statement );
				}
			}
			$item->setStatements( $kept );
			foreach ( $this->statementSpecs( $classKey, $record ) as $propertyId => $value ) {
				foreach ( is_array( $value ) ? $value : [ $value ] as $single ) {
					$item->getStatements()->addNewStatement(
						new PropertyValueSnak( $this->propertyId( $propertyId ), $single )
					);
				}
			}
		}
		$title = trim( (string)( $record['title'] ?? '' ) );
		if ( $title !== '' ) {
			$item->setLabel( 'en', $this->labelFor( $classKey, $record, false ) );
		}
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( 'en', $description );
		}
	}

	// ------------------------------------------------------------- validation

	/**
	 * @param array<string,mixed> $record
	 */
	private function validateAuthors( string $classKey, array &$record, bool $creating ): ?string {
		$formKey = SourceFieldMap::formKey( $classKey );
		if ( $formKey === 'bookExcerpt' && empty( $record['authors'] ) ) {
			return null; // parent-filled (or absent — validateParent reports)
		}
		if ( $creating && empty( $record['authors'] ) ) {
			return self::ERROR_NO_AUTHOR;
		}
		if ( empty( $record['authors'] ) ) {
			return null; // update: blank authors keep the existing statements
		}
		$agentIds = array_values( $this->config->agentClasses() );
		foreach ( ItemIdList::split( (string)$record['authors'] ) as $authorId ) {
			if ( preg_match( '/^Q[1-9]\d*$/', $authorId ) !== 1 ) {
				return "author \"{$authorId}\" is not an item ID.";
			}
			$author = $this->lookup->getEntity( new ItemId( $authorId ) );
			if ( !$author instanceof Item ) {
				return "author \"{$authorId}\" does not exist.";
			}
			if ( !$this->itemHasClass( $author, $agentIds ) ) {
				return sprintf( self::ERROR_AUTHOR_CLASS, $authorId );
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function validateParent( string $classKey, array &$record, bool $creating ): ?string {
		$expectedFormKey = SourceFieldMap::PARENT_CLASS[$classKey] ?? null;
		if ( $expectedFormKey === null ) {
			return null; // not a child class
		}
		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( $parentId === '' ) {
			if ( $creating ) {
				return sprintf(
					self::ERROR_PARENT_REQUIRED,
					$classKey,
					$this->classLabel( $expectedFormKey )
				);
			}
			return null; // update without parent keeps the existing part-of
		}
		if ( preg_match( '/^Q[1-9]\d*$/', $parentId ) !== 1 ) {
			return "parent \"{$parentId}\" is not an item ID.";
		}
		$parentClassId = $this->config->sourceClasses()[$expectedFormKey] ?? null;
		$parent = $this->lookup->getEntity( new ItemId( $parentId ) );
		if ( !$parent instanceof Item ) {
			return sprintf( self::ERROR_PARENT_MISSING, $parentId );
		}
		if ( $parentClassId !== null && !$this->itemHasClass( $parent, [ $parentClassId ] ) ) {
			return sprintf( self::ERROR_PARENT_CLASS, $parentId, $this->classLabel( $expectedFormKey ) );
		}
		return null;
	}

	/**
	 * book-excerpt: auto-generate the description from pages/volume + the
	 * parent book label, and infer year/authors from the parent book's own
	 * statements when blank (mirrors Special:AddSource::fillBookExcerptFromParent).
	 *
	 * @param array<string,mixed> $record
	 */
	private function fillBookExcerptFromParent( string $classKey, array &$record ): ?string {
		if ( SourceFieldMap::formKey( $classKey ) !== 'bookExcerpt' ) {
			return null;
		}
		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( preg_match( '/^Q[1-9]\d*$/', $parentId ) !== 1 ) {
			return null; // missing/invalid parent is validateParent's error
		}
		$parent = $this->lookup->getEntity( new ItemId( $parentId ) );
		if ( !$parent instanceof Item ) {
			return null;
		}

		if ( trim( (string)( $record['description'] ?? '' ) ) === '' ) {
			$parts = [];
			$pages = trim( (string)( $record['pages'] ?? '' ) );
			if ( $pages !== '' ) {
				$parts[] = $this->message( 'embeddablecontent-source-bookexcerpt-desc-pages', [ $pages ] );
			}
			$volume = trim( (string)( $record['volume'] ?? '' ) );
			if ( $volume !== '' ) {
				$parts[] = $this->message( 'embeddablecontent-source-bookexcerpt-desc-volume', [ $volume ] );
			}
			$labelTerm = $parent->getLabels()->getByLanguage( 'en' );
			$parentLabel = $labelTerm !== null ? $labelTerm->getText() : '';
			if ( $parts !== [] && $parentLabel !== '' ) {
				$record['description'] = $this->message(
					'embeddablecontent-source-bookexcerpt-desc',
					[ implode( ' ', $parts ), $parentLabel ]
				);
			}
		}

		if ( empty( $record['year'] ) ) {
			$year = $this->yearOf( $parent, $this->config->provenancePropertyIds()['date'] ?? null );
			if ( $year !== null ) {
				$record['year'] = (string)$year;
			}
		}
		if ( empty( $record['authors'] ) ) {
			$attributedTo = $this->config->provenancePropertyIds()['attributedTo'] ?? null;
			if ( $attributedTo !== null ) {
				$parentAuthors = $this->entityValuesFor( $parent, $attributedTo );
				if ( $parentAuthors !== [] ) {
					$record['authors'] = implode( ', ', $parentAuthors );
				}
			}
		}
		return null;
	}

	// ------------------------------------------------------------- statement helpers

	/**
	 * @param array<string,mixed> $record
	 * @return array<string, DataValue>
	 */
	private function externalIdStatements( string $classKey, array $record ): array {
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
	 * @param array<string,mixed> $record
	 * @return array<string, DataValue>
	 */
	private function citationMetadataStatements( string $classKey, array $record ): array {
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
			if ( in_array( $key, $this->citationMetadataFieldExclusions( $classKey ), true ) ) {
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

	/** The publisher and journal are written as ENTITY values, not strings. */
	private function citationMetadataFieldExclusions( string $classKey ): array {
		return [ 'publisher', 'publishedIn' ];
	}

	/** @return array<string,string> config key => record field */
	private function externalIdRecordMap(): array {
		return [
			'wikidata' => 'wikidataId',
			'isbn' => 'isbn',
			'doi' => 'doi',
			'openalex' => 'openalexWorkId',
			'pubmed' => 'pubmedId',
		];
	}

	private function parseItemId( string $value ): ?ItemId {
		$value = trim( $value );
		if ( preg_match( '/^Q[1-9]\d*$/', $value ) !== 1 ) {
			return null;
		}
		return new ItemId( $value );
	}

	private function itemHasClass( Item $item, array $classItemIds ): bool {
		$propertyId = $this->config->instanceOfPropertyId();
		foreach ( $item->getStatements()->getByPropertyId( $this->propertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue
				&& in_array( $value->getEntityId()->getSerialization(), $classItemIds, true )
			) {
				return true;
			}
		}
		return false;
	}

	private function yearOf( Item $item, ?string $dateProperty ): ?int {
		if ( $dateProperty === null ) {
			return null;
		}
		foreach ( $item->getStatements()->getByPropertyId( $this->propertyId( $dateProperty ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof TimeValue ) {
				return (int)substr( $value->getTime(), 1, 4 );
			}
		}
		return null;
	}

	/** @return string[] entity ids of the item's statements on the property */
	private function entityValuesFor( Item $item, string $propertyId ): array {
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( $this->propertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$out[] = $value->getEntityId()->getSerialization();
			}
		}
		return $out;
	}

	/** The " (Class label)" English suffix for a class, or ''. */
	private function classLabelSuffix( string $classKey ): string {
		$label = $this->classLabel( $classKey );
		return $label === '' ? '' : ' (' . $label . ')';
	}

	private function classLabel( string $classKey ): string {
		return $this->message( 'embeddablecontent-source-class-' . SourceFieldMap::formKey( $classKey ), [] );
	}

	private function isHttpUrl( string $url ): bool {
		return preg_match( '#^https?://\S+$#i', $url ) === 1;
	}

	private function message( string $key, array $params ): string {
		return ( $this->message )( $key, $params );
	}

	/**
	 * Property-id factory across data-model versions: the Wikibase bundle
	 * (production, 9.7+) made PropertyId an interface with NumericPropertyId
	 * as the concrete class; the unit-test image's 9.6.1 has PropertyId
	 * concrete and no NumericPropertyId. Instantiate whichever exists.
	 */
	private function propertyId( string $serialization ): PropertyId {
		if ( class_exists( NumericPropertyId::class ) ) {
			return new NumericPropertyId( $serialization );
		}
		return new PropertyId( $serialization );
	}

}
