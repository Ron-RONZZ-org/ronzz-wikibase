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
		string $editSummary
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
			return $existing;
		}

		$item = new Item();
		$item->setLabel( 'en', $label );
		$description = trim( $description );
		if ( $description !== '' ) {
			$item->setDescription( 'en', mb_substr( $description, 0, 250 ) );
		}
		WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_NEW );

		$props = $config->imagePropertyIds();
		$guidGenerator = new GuidGenerator();
		$add = static function ( string $propertyId, $value ) use ( $item, $guidGenerator ): void {
			$statement = new Statement(
				new PropertyValueSnak(
					new \Wikibase\DataModel\Entity\NumericPropertyId( $propertyId ),
					$value
				),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			);
			$item->getStatements()->addStatement( $statement );
		};

		$add( $config->instanceOfPropertyId(), new EntityIdValue( new ItemId( $classItemId ) ) );
		if ( isset( $props['image'] ) ) {
			$fileTitle = Title::makeTitle( NS_FILE, $filePageName );
			if ( $fileTitle !== null ) {
				$add( $props['image'], new StringValue( $fileTitle->getFullURL() ) );
			}
		}
		if ( $license !== null && $license !== '' && isset( $props['license'] ) ) {
			try {
				$licenseItem = WikibaseRepo::getEntityIdParser()->parse( $license );
				if ( $licenseItem instanceof ItemId ) {
					$add( $props['license'], new EntityIdValue( $licenseItem ) );
				}
			} catch ( \Throwable $e ) {
				// A malformed license value is a form-level problem; the item
				// still gets created with the rest of the statements.
			}
		}
		foreach ( [ 'imageAuthor' => $author, 'imageLicenseInfo' => $licenseInfo ] as $propKey => $value ) {
			if ( $value !== '' && isset( $props[$propKey] ) ) {
				$add( $props[$propKey], new StringValue( mb_substr( $value, 0, 250 ) ) );
			}
		}
		if ( $sourceUrl !== '' ) {
			$provenance = $config->provenancePropertyIds();
			if ( isset( $provenance['sourceUrl'] ) ) {
				$add( $provenance['sourceUrl'], new StringValue( mb_substr( $sourceUrl, 0, 500 ) ) );
			}
		}

		WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_UPDATE );
		self::assertSitelink( $item->getId()->getSerialization(), $filePageName, $user, $editSummary );
		return $item->getId()->getSerialization();
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
