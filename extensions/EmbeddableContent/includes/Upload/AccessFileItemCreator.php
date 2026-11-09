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
use Wikibase\Repo\WikibaseRepo;

/**
 * Item-per-access-file: creates (or reuses) the sitelinked `access file`
 * item for a File: page uploaded through the AddSource/UpdateSource access
 * editor.
 *
 * Identity is the File: page's `wikibase` sitelink — one item per file. The
 * item holds the file's semantic facts (`instance of access file`, `file` =
 * the File: page URL, `license`) while the SOURCE item links to it through
 * `has access file`; the source statement carries the public/login status
 * and the language qualifier. This mirrors the Special:Upload item-per-upload
 * (ImageItemCreator): facts on the file's item, the consumer only references
 * it.
 *
 * An existing sitelinked item is reused as-is (it may already be an image
 * item); the missing access-file class/file statements are added and the
 * license is replaced when a value is supplied. A same-label item that is
 * NOT this file's item is never reused — the sitelink is authoritative.
 *
 * Never fatal when the vocabulary is missing (an instance seeded before the
 * access-file class/property): returns null and the caller falls back to the
 * legacy `file` URL statement.
 *
 * @license GPL-2.0-or-later
 */
final class AccessFileItemCreator {

	/**
	 * @param EmbeddableContentConfig $config
	 * @param User $user
	 * @param string $filePageName prefixed File: page name
	 * @param string|null $license license item id, or null to keep the existing one
	 * @param string $editSummary
	 * @param string $description item description ('' = leave unchanged)
	 * @return string|null the access-file item id, or null when the
	 *   access-file vocabulary is not configured (legacy fallback)
	 */
	public static function createOrReuse(
		EmbeddableContentConfig $config,
		User $user,
		string $filePageName,
		?string $license,
		string $editSummary,
		string $description = ''
	): ?string {
		$classId = $config->accessFileClasses()['accessFile'] ?? null;
		$props = $config->sourcePropertyIds();
		$fileProp = $props['file'] ?? null;
		if ( $classId === null || $fileProp === null ) {
			return null;
		}
		$title = Title::newFromText( $filePageName );
		if ( $title === null || $title->getNamespace() !== NS_FILE ) {
			return null;
		}
		$filePageName = $title->getPrefixedText();

		$existing = self::findSitelinkedItemId( $filePageName );
		if ( $existing !== null ) {
			try {
				$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $existing ) );
			} catch ( \Throwable $e ) {
				$item = null;
			}
			if ( $item instanceof Item ) {
				$changed = self::applyFacts( $config, $item, $title, $classId, $license );
				if ( $description !== '' && !self::hasDescription( $item, $description ) ) {
					$item->setDescription( 'en', mb_substr( $description, 0, 2000 ) );
					$changed = true;
				}
				if ( $changed ) {
					WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_UPDATE );
				}
				return $existing;
			}
		}

		$item = new Item();
		$item->setLabel( 'en', $title->getText() );
		if ( $description !== '' ) {
			$item->setDescription( 'en', mb_substr( $description, 0, 2000 ) );
		}
		$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $filePageName );
		// Save first so the item has an id (the statement GUIDs need it).
		WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_NEW );
		self::applyFacts( $config, $item, $title, $classId, $license );
		WikibaseRepo::getEntityStore()->saveEntity( $item, $editSummary, $user, EDIT_UPDATE );
		WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
		return $item->getId()?->getSerialization();
	}

	/**
	 * Ensure `instance of access file` + `file` (File: URL); replace the
	 * `license` statement when a value is supplied. Returns whether the item
	 * changed.
	 */
	private static function applyFacts(
		EmbeddableContentConfig $config,
		Item $item,
		Title $fileTitle,
		string $classId,
		?string $license
	): bool {
		$props = $config->sourcePropertyIds();
		$guidGenerator = new GuidGenerator();
		$changed = false;

		$classProp = $config->instanceOfPropertyId();
		if ( !self::hasEntityStatement( $item, $classProp, $classId ) ) {
			$item->getStatements()->addStatement( new Statement(
				new PropertyValueSnak(
					new NumericPropertyId( $classProp ),
					new EntityIdValue( new ItemId( $classId ) )
				),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			) );
			$changed = true;
		}

		$fileProp = $props['file'] ?? null;
		$fileUrl = $fileTitle->getFullURL();
		if ( $fileProp !== null && !self::hasStringStatement( $item, $fileProp, $fileUrl ) ) {
			$item->getStatements()->addStatement( new Statement(
				new PropertyValueSnak(
					new NumericPropertyId( $fileProp ),
					new StringValue( $fileUrl )
				),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			) );
			$changed = true;
		}

		$licenseProp = $props['license'] ?? null;
		$licenseId = self::normalizeItemId( $license );
		if ( $licenseProp !== null && $licenseId !== null
			&& !self::hasEntityStatement( $item, $licenseProp, $licenseId )
		) {
			self::removeStatements( $item, $licenseProp );
			$item->getStatements()->addStatement( new Statement(
				new PropertyValueSnak(
					new NumericPropertyId( $licenseProp ),
					new EntityIdValue( new ItemId( $licenseId ) )
				),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			) );
			$changed = true;
		}

		return $changed;
	}

	private static function hasDescription( Item $item, string $description ): bool {
		return $item->getDescriptions()->hasTermForLanguage( 'en' )
			&& $item->getDescriptions()->getByLanguage( 'en' )->getText() === $description;
	}

	private static function hasStringStatement( Item $item, string $propertyId, string $value ): bool {
		foreach ( self::statementsFor( $item, $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			$data = $snak instanceof PropertyValueSnak ? $snak->getDataValue() : null;
			if ( $data instanceof StringValue && (string)$data->getValue() === $value ) {
				return true;
			}
		}
		return false;
	}

	private static function hasEntityStatement( Item $item, string $propertyId, string $itemId ): bool {
		foreach ( self::statementsFor( $item, $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			$data = $snak instanceof PropertyValueSnak ? $snak->getDataValue() : null;
			if ( $data instanceof EntityIdValue
				&& $data->getEntityId()->getSerialization() === $itemId
			) {
				return true;
			}
		}
		return false;
	}

	/** @return Statement[] */
	private static function statementsFor( Item $item, string $propertyId ): array {
		try {
			$id = new NumericPropertyId( $propertyId );
		} catch ( \Throwable $e ) {
			return [];
		}
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( $id ) as $statement ) {
			$out[] = $statement;
		}
		return $out;
	}

	private static function removeStatements( Item $item, string $propertyId ): void {
		foreach ( self::statementsFor( $item, $propertyId ) as $statement ) {
			$guid = $statement->getGuid();
			if ( $guid !== null ) {
				$item->getStatements()->removeStatementsWithGuid( $guid );
			}
		}
	}

	private static function normalizeItemId( ?string $itemId ): ?string {
		$itemId = trim( (string)$itemId );
		return preg_match( '/^Q[1-9]\d*$/', $itemId ) === 1 ? strtoupper( $itemId ) : null;
	}

	private static function findSitelinkedItemId( string $filePageName ): ?string {
		try {
			$id = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $filePageName );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $id?->getSerialization();
	}

}
