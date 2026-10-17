<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
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
 * (P2093-aligned string), `additional license information` (string), and
 * the source URL (provenance, when configured). The file description page
 * itself carries the human-readable attribution (UploadHooks), so the item
 * holds the queryable facts and the page the prose — the Add* split.
 *
 * Mirrors the base SpecialAddExternalEntity create-or-skip + sitelink
 * pattern (label reuse is idempotent; existing sitelink state is never
 * rewritten). Never fatal: a missing vocabulary key (unconfigured instance)
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
		array $clearFields = []
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

		$existing = self::findItemIdByLabel( $label );
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
						$clearFields
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
		self::applyFacts( $config, $item, $license, $author, $licenseInfo, $sourceUrl, false, $clearFields );

		WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_UPDATE );
		self::assertSitelink( $item->getId()->getSerialization(), $filePageName, $user, $editSummary );
		return $item->getId()->getSerialization();
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
	 */
	private static function applyFacts(
		EmbeddableContentConfig $config,
		Item $item,
		?string $license,
		string $author,
		string $licenseInfo,
		string $sourceUrl,
		bool $replace,
		array $clearFields = []
	): void {
		$props = $config->imagePropertyIds();
		$provenance = $config->provenancePropertyIds();
		$licenseValue = null;
		if ( $license !== null && $license !== '' ) {
			try {
				$licenseItem = WikibaseRepo::getEntityIdParser()->parse( $license );
				if ( $licenseItem instanceof ItemId ) {
					$licenseValue = new EntityIdValue( $licenseItem );
				}
			} catch ( \Throwable $e ) {
				// A malformed license value is a form-level problem; the item
				// still gets created/updated with the rest of the statements.
			}
		}

		$facts = [
			'license' => [ $props['license'] ?? null, $licenseValue ],
			'author' => [
				$props['imageAuthor'] ?? null,
				$author !== '' ? new StringValue( mb_substr( $author, 0, 250 ) ) : null,
			],
			'licenseInfo' => [
				$props['imageLicenseInfo'] ?? null,
				$licenseInfo !== '' ? new StringValue( mb_substr( $licenseInfo, 0, 250 ) ) : null,
			],
			'sourceUrl' => [
				$provenance['sourceUrl'] ?? null,
				$sourceUrl !== '' ? new StringValue( mb_substr( $sourceUrl, 0, 500 ) ) : null,
			],
		];
		$clear = array_fill_keys( $clearFields, true );
		$guidGenerator = new GuidGenerator();
		foreach ( $facts as $field => [ $propertyId, $value ] ) {
			if ( $propertyId === null ) {
				continue;
			}
			if ( isset( $clear[$field] ) ) {
				self::removeStatements( $item, $propertyId );
				continue;
			}
			if ( $value === null ) {
				continue;
			}
			if ( $replace ) {
				self::removeStatements( $item, $propertyId );
			}
			$item->getStatements()->addStatement( new Statement(
				new PropertyValueSnak( new NumericPropertyId( $propertyId ), $value ),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			) );
		}
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
