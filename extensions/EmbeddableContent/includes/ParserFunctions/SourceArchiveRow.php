<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\Spec\AccessEntries;
use EmbeddableContent\SourceArchive\SourceArchive;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#source-archive:}}` parser function — the "Archived text" auto-link on
 * a Source: page (multi-access).
 *
 * Reads every URL access entry's `archivedText` qualifier and renders one
 * table row when at least one of those subpages exists; the legacy
 * `Source:<label>/archivedText` subpage (items predating multi-access) is
 * included too. Multiple archives are joined with ` · `.
 *
 * When no archive exists it returns '' — the row disappears entirely. The
 * subpage save/delete and item edits invalidate this page's parser cache, so
 * the row appears without a manual purge.
 *
 * Returns WIKITEXT (not HTML): the row participates in the template's normal
 * parse.
 *
 * @license GPL-2.0-or-later
 */
final class SourceArchiveRow {

	/**
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onSourceArchive( Parser $parser ): array {
		$empty = [ 'text' => '', 'noparse' => false, 'isHTML' => false ];

		$title = $parser->getTitle();
		if ( $title === null
			|| !defined( 'NS_SOURCE' )
			|| $title->getNamespace() !== NS_SOURCE
			|| $title->isSubpage()
		) {
			return $empty;
		}

		$titles = [];
		$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( 'wikibase', $title->getPrefixedText() );
		if ( $itemId !== null ) {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
			if ( $entity instanceof Item ) {
				foreach ( AccessEntries::fromItem( $entity, self::config() ) as $entry ) {
					if ( $entry['type'] !== AccessEntries::TYPE_URL || empty( $entry['archivedTitle'] ) ) {
						continue;
					}
					$archive = Title::newFromText( (string)$entry['archivedTitle'] );
					if ( $archive !== null && $archive->exists() ) {
						$titles[$archive->getPrefixedText()] = $archive;
					}
				}
			}
			self::registerCacheDependency( $parser, $itemId );
		}

		// Legacy single archive.
		$legacy = SourceArchive::titleFor( $title );
		if ( $legacy !== null && $legacy->exists() ) {
			$titles[$legacy->getPrefixedText()] = $legacy;
		}
		if ( $titles === [] ) {
			return $empty;
		}

		$language = $parser->getTargetLanguage();
		$label = wfMessage( 'embeddablecontent-source-archive-row-label' )
			->inLanguage( $language )->text();
		$linkText = wfMessage( 'embeddablecontent-source-archive-row-link' )
			->inLanguage( $language )->text();
		$links = [];
		foreach ( $titles as $archive ) {
			$links[] = '[[' . $archive->getPrefixedText() . '|' . $linkText . ']]';
		}

		return [
			'text' => "\n|-\n| " . $label . ' || ' . implode( ' · ', $links ),
			'noparse' => false,
			'isHTML' => false,
		];
	}

	private static function config(): \EmbeddableContent\EmbeddableContentConfig {
		return MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
	}

	private static function registerCacheDependency( Parser $parser, EntityId $itemId ): void {
		$services = MediaWikiServices::getInstance();
		$title = WikibaseRepo::getEntityTitleStoreLookup( $services )->getTitleForId( $itemId );
		if ( $title === null || !$title->exists() ) {
			return;
		}
		$revId = \EmbeddableContent\Spec\LatestRevision::id(
			WikibaseRepo::getEntityRevisionLookup( $services ),
			$itemId
		);
		$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
	}
}
