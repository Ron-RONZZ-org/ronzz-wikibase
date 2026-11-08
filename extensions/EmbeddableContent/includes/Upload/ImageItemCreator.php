<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\EntityClassFilter;
use EmbeddableContent\ItemIdentityPolicy;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Lib\TermIndexEntry;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\Rdbms\DBError;

/**
 * Item-per-upload: creates (or reuses) the sitelinked image item for a file
 * uploaded through Special:Upload — the same semantic model the Add* pages
 * use (facts in the item, the classic page sitelinked to it; here the
 * classic page is the File: page).
 *
 * Statements written on the item: `instance of` → the image class, `image`
 * → the File: page URL, `license` (entity, P275-aligned), `image author`
 * (P2093-aligned string), `image source` (string), `additional license
 * information` (string), the source URL (provenance), and the ENTITY
 * variants of author/source (`attributed to` / `source` — the same
 * item-valued provenance properties the Add* flows use). The file
 * description page itself carries the human-readable attribution
 * (UploadHooks), so the item holds the queryable facts and the page the
 * prose — the Add* split.
 *
 * When no entity author is supplied but an entity source is, the item
 * inherits the source's `attributed to` statements (the upload form's
 * "source specified, author blank" rule).
 *
 * Mirrors the base SpecialAddExternalEntity create-or-skip + sitelink
 * pattern (label reuse is idempotent; existing sitelink state is never
 * rewritten). Identity by LABEL is guarded: a same-label item that is not
 * this file page's image item (e.g. the article item "Logic" for
 * File:Logic.png) is never reused — the upload gets its own item instead
 * (ItemIdentityPolicy). Never fatal: a missing vocabulary key (unconfigured instance)
 * skips the item silently — the file upload itself is unaffected.
 *
 * @license GPL-2.0-or-later
 */
final class ImageItemCreator {

	/**
	 * @param string $label item label (the file name without extension)
	 * @param string $filePageName "File:Example.jpg" (prefixed, space-normalized)
	 * @param string|null $license license item id ("Q42"), or null
	 * @param bool $replaceExistingFacts replace the provided facts on an existing item
	 * @param string[] $clearFields fact fields to remove on an existing item
	 * @param string|null $authorEntity entity author (Q-id) → `attributed to`
	 * @param string|null $sourceEntity entity source (Q-id) → `source`
	 * @param string $sourceText plain-text source → `image source`
	 * @return string|null created/reused item id, or null when the vocabulary
	 *   is not configured (image class/property missing) — never fatal
	 */
	public static function createOrReuse(
		EmbeddableContentConfig $config,
		User $user,
		string $label,
		string $description,
		string $filePageName,
		?string $license,
		string $author,
		string $licenseInfo,
		string $sourceUrl,
		string $editSummary,
		bool $replaceExistingFacts = false,
		array $clearFields = [],
		?string $authorEntity = null,
		?string $sourceEntity = null,
		string $sourceText = ''
	): ?string {
		$classes = $config->imageClasses();
		$classItemId = $classes['image'] ?? null;
		if ( $classItemId === null ) {
			return null;
		}
		$label = trim( $label );
		if ( $label === '' ) {
			return null;
		}

		$existing = self::findSitelinkedItemId( $filePageName );
		if ( $existing === null ) {
			$existing = self::findReusableLabelMatch( $label, $filePageName, $config );
		}
		if ( $existing !== null ) {
			self::assertSitelink( $existing, $filePageName, $user, $editSummary );
			if ( $replaceExistingFacts || $clearFields !== [] ) {
				$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $existing ) );
				if ( $item instanceof Item ) {
					self::applyFacts(
						$config,
						$item,
						$license,
						$author,
						$licenseInfo,
						$sourceUrl,
						$replaceExistingFacts,
						$clearFields,
						$authorEntity,
						$sourceEntity,
						$sourceText
					);
					WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_UPDATE );
				}
			}
			return $existing;
		}

		$item = new Item();
		$item->setLabel( 'en', $label );
		$description = trim( $description );
		if ( $description !== '' ) {
			// The term store's description limit was raised to 2000
			// (string-limits multilang length 2000, wbt_text VARBINARY(2000)).
			$item->setDescription( 'en', mb_substr( $description, 0, 2000 ) );
		}
		WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_NEW );

		$props = $config->imagePropertyIds();
		$guidGenerator = new GuidGenerator();
		$add = static function ( string $propertyId, $value ) use ( $item, $guidGenerator ): void {
			$statement = new Statement(
				new PropertyValueSnak( new NumericPropertyId( $propertyId ), $value ),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			);
			$item->getStatements()->addStatement( $statement );
		};

		$add( $config->instanceOfPropertyId(), new EntityIdValue( new ItemId( $classItemId ) ) );
		if ( isset( $props['image'] ) ) {
			// makeTitle takes the title TEXT (no namespace prefix) — the
			// caller passes the prefixed page name.
			$fileTitle = Title::makeTitle(
				NS_FILE,
				(string)preg_replace( '/^File:/i', '', $filePageName )
			);
			if ( $fileTitle !== null ) {
				$add( $props['image'], new StringValue( $fileTitle->getFullURL() ) );
			}
		}
		self::applyFacts(
			$config,
			$item,
			$license,
			$author,
			$licenseInfo,
			$sourceUrl,
			false,
			$clearFields,
			$authorEntity,
			$sourceEntity,
			$sourceText
		);

		WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_UPDATE );
		self::assertSitelink( $item->getId()->getSerialization(), $filePageName, $user, $editSummary );
		return $item->getId()->getSerialization();
	}

	/**
	 * Updates the facts of an EXISTING sitelinked image item (the
	 * Special:UpdateFile path). A provided value replaces that property's
	 * statements; a blank value keeps the current statement (no-clobber).
	 * `$clearFields` removes a field explicitly (the API's clear list).
	 *
	 * @param string[] $clearFields
	 */
	public static function updateFacts(
		EmbeddableContentConfig $config,
		string $itemId,
		string $description,
		?string $license,
		string $author,
		string $licenseInfo,
		string $sourceUrl,
		string $editSummary,
		User $user,
		array $clearFields = [],
		?string $authorEntity = null,
		?string $sourceEntity = null,
		string $sourceText = ''
	): void {
		$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		if ( !$entity instanceof Item ) {
			throw new \RuntimeException( "image item {$itemId} not found" );
		}
		self::applyFacts(
			$config,
			$entity,
			$license,
			$author,
			$licenseInfo,
			$sourceUrl,
			true,
			$clearFields,
			$authorEntity,
			$sourceEntity,
			$sourceText
		);
		$description = trim( $description );
		if ( $description !== '' ) {
			$entity->setDescription( 'en', mb_substr( $description, 0, 2000 ) );
		}
		WikibaseRepo::getEntityStore()->saveEntity( $entity, $editSummary, $user, EDIT_UPDATE );
	}

	/**
	 * Adds (or, in replace mode, replaces) the fact statements on an item.
	 *
	 * Replace mode is for `update-file`: a provided value replaces every
	 * existing statement for that property; a blank value keeps the current
	 * statement (no-clobber). Clear mode removes every statement for a named
	 * field even when no value is supplied. The image and instance-of
	 * statements are the caller's job and are never touched here.
	 *
	 * @param bool $replace remove existing statements for each PROVIDED property
	 * @param string[] $clearFields fields whose statements are removed regardless of value
	 * @param string|null $authorEntity entity author (Q-id) → `attributed to`
	 * @param string|null $sourceEntity entity source (Q-id) → `source`
	 * @param string $sourceText plain-text source → `image source`
	 */
	private static function applyFacts(
		EmbeddableContentConfig $config,
		Item $item,
		?string $license,
		string $author,
		string $licenseInfo,
		string $sourceUrl,
		bool $replace,
		array $clearFields = [],
		?string $authorEntity = null,
		?string $sourceEntity = null,
		string $sourceText = ''
	): void {
		$props = $config->imagePropertyIds();
		$provenance = $config->provenancePropertyIds();

		$authorEntityId = self::normalizeItemId( $authorEntity );
		$sourceEntityId = self::normalizeItemId( $sourceEntity );

		// "Source specified but not author": inherit the source's own
		// `attributed to` statements (the AddSource author model).
		$authorEntityIds = $authorEntityId !== null ? [ $authorEntityId ] : [];
		if ( $authorEntityIds === [] && $sourceEntityId !== null ) {
			$authorEntityIds = self::attributedToIds(
				$sourceEntityId,
				$provenance['attributedTo'] ?? null
			);
		}

		// Each field maps to a property + the list of values to add. A list
		// keeps the multi-author inheritance case uniform with single values.
		$facts = [
			'license' => [
				$props['license'] ?? null,
				array_values( array_filter( [ self::itemIdValue( $license ) ] ) ),
			],
			'author' => [
				$props['imageAuthor'] ?? null,
				$author !== '' ? [ new StringValue( mb_substr( $author, 0, 250 ) ) ] : [],
			],
			'licenseInfo' => [
				$props['imageLicenseInfo'] ?? null,
				$licenseInfo !== '' ? [ new StringValue( mb_substr( $licenseInfo, 0, 250 ) ) ] : [],
			],
			'sourceUrl' => [
				$provenance['sourceUrl'] ?? null,
				$sourceUrl !== '' ? [ new StringValue( mb_substr( $sourceUrl, 0, 500 ) ) ] : [],
			],
			'sourceText' => [
				$props['imageSource'] ?? null,
				$sourceText !== '' ? [ new StringValue( mb_substr( $sourceText, 0, 500 ) ) ] : [],
			],
			'authorEntity' => [
				$provenance['attributedTo'] ?? null,
				array_values( array_filter( array_map(
					static fn ( string $id ): ?EntityIdValue => self::itemIdValue( $id ),
					$authorEntityIds
				) ) ),
			],
			'sourceEntity' => [
				$provenance['source'] ?? null,
				array_values( array_filter( [ self::itemIdValue( $sourceEntityId ) ] ) ),
			],
		];
		$clear = array_fill_keys( $clearFields, true );
		$guidGenerator = new GuidGenerator();
		foreach ( $facts as $field => [ $propertyId, $values ] ) {
			if ( $propertyId === null ) {
				continue;
			}
			if ( isset( $clear[$field] ) ) {
				self::removeStatements( $item, $propertyId );
				continue;
			}
			if ( $values === [] ) {
				continue;
			}
			if ( $replace ) {
				self::removeStatements( $item, $propertyId );
			}
			foreach ( $values as $value ) {
				$item->getStatements()->addStatement( new Statement(
					new PropertyValueSnak( new NumericPropertyId( $propertyId ), $value ),
					null,
					null,
					$guidGenerator->newGuid( $item->getId() )
				) );
			}
		}
	}

	/**
	 * An EntityIdValue for a valid item id, or null (a malformed value is a
	 * form/API problem — the rest of the facts still land). Accepts an
	 * already-normalized id.
	 */
	private static function itemIdValue( ?string $itemId ): ?EntityIdValue {
		if ( $itemId === null || $itemId === '' ) {
			return null;
		}
		try {
			$parsed = WikibaseRepo::getEntityIdParser()->parse( $itemId );
			return $parsed instanceof ItemId ? new EntityIdValue( $parsed ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Trimmed/validated item id serialization, or null. */
	private static function normalizeItemId( ?string $itemId ): ?string {
		$value = self::itemIdValue( $itemId );
		return $value === null ? null : $value->getEntityId()->getSerialization();
	}

	/**
	 * The item ids of an entity's `attributed to` statements (the author
	 * inheritance rule), or [] when the entity/property is unavailable.
	 *
	 * @return string[]
	 */
	private static function attributedToIds( string $itemId, ?string $propertyId ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return [];
		}
		if ( !$entity instanceof Item ) {
			return [];
		}
		$ids = [];
		foreach ( $entity->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$ids[] = $value->getEntityId()->getSerialization();
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Removes every statement of one property (the mode=update replace/clear step). */
	private static function removeStatements( Item $item, string $propertyId ): void {
		$property = new NumericPropertyId( $propertyId );
		foreach ( $item->getStatements()->getByPropertyId( $property ) as $existing ) {
			$item->getStatements()->removeStatementsWithGuid( $existing->getGuid() );
		}
	}

	/**
	 * Sitelinks the File: page ↔ item (entity revision + sitelink table, the
	 * Add* pattern — the page's parse-time wikibase_item property fills in
	 * via the eventual job). Idempotent: never rewrites an existing link.
	 */
	private static function assertSitelink( string $itemId, string $filePageName, User $user, string $editSummary ): void {
		$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		if ( !$item instanceof Item || $item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			return;
		}
		$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $filePageName );
		WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_UPDATE );
		// Also write the sitelink table synchronously (the entity save's
		// secondary data update may run deferred — the Add* pattern).
		WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
	}

	/**
	 * The item sitelinked to this File: page — the authoritative one-item-
	 * per-file identity, or null when the page is unconnected.
	 */
	private static function findSitelinkedItemId( string $filePageName ): ?string {
		try {
			$id = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $filePageName );
		} catch ( \Throwable $e ) {
			wfLogWarning( 'EmbeddableContent: sitelink lookup failed for '
				. $filePageName . ': ' . $e->getMessage() );
			return null;
		}
		return $id?->getSerialization();
	}

	/**
	 * A LABEL match may be reused only when it IS this File: page's image
	 * item (see ItemIdentityPolicy). A same-label item belonging to another
	 * page (an article named like the uploaded file) is rejected — and
	 * logged, so the collision is observable instead of silently dropping
	 * the upload's facts. Returns null when a new item must be created.
	 */
	private static function findReusableLabelMatch(
		string $label,
		string $filePageName,
		EmbeddableContentConfig $config
	): ?string {
		$candidate = self::findItemIdByLabel( $label );
		if ( $candidate === null ) {
			return null;
		}
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $candidate ) );
		} catch ( \Throwable $e ) {
			wfLogWarning( "EmbeddableContent: could not load label candidate {$candidate}: "
				. $e->getMessage() );
			return null;
		}
		if ( !$entity instanceof Item ) {
			return null;
		}
		$sitelinkPage = self::sitelinkPage( $entity );
		$isImageItem = self::isImageItem( $entity, $config );
		if ( ItemIdentityPolicy::canAdoptFileLabelMatch( $filePageName, $sitelinkPage, $isImageItem ) ) {
			return $candidate;
		}
		wfLogWarning( sprintf(
			'EmbeddableContent: image item label collision for %s — %s is not this file page'
				. ' (sitelink: %s, image class: %s); creating a new item',
			$filePageName,
			$candidate,
			$sitelinkPage ?? '(none)',
			$isImageItem ? 'yes' : 'no'
		) );
		return null;
	}

	/** The item's `wikibase` sitelink page name, or null. */
	private static function sitelinkPage( Item $item ): ?string {
		$links = $item->getSiteLinkList();
		return $links->hasLinkWithSiteId( 'wikibase' )
			? $links->getBySiteId( 'wikibase' )->getPageName()
			: null;
	}

	/** Whether the item is `instance of` the configured image class. */
	private static function isImageItem( Item $item, EmbeddableContentConfig $config ): bool {
		$classId = $config->imageClasses()['image'] ?? null;
		if ( $classId === null ) {
			return false;
		}
		return EntityClassFilter::hasClass( $item, $classId, $config->instanceOfPropertyId() );
	}

	/** Item id whose English label matches exactly (case-insensitive), or null. */
	private static function findItemIdByLabel( string $label ): ?string {
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
}
