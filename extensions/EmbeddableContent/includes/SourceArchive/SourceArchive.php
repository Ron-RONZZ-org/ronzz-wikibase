<?php

declare( strict_types = 1 );

namespace EmbeddableContent\SourceArchive;

use MediaWiki\Content\WikitextContent;
use MediaWiki\Context\IContextSource;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

/**
 * Automatic page-text archive for AddSource: stores the fetched external
 * text as the wikitext subpage `Source:<label>/archivedText`.
 *
 * The archive is deliberately NOT a Wikibase statement — the subpage itself
 * is the record, and the row on the Source: page is emitted by
 * `{{#source-archive:}}` only when the subpage exists (no vocabulary change,
 * no field-contract ripple).
 *
 * Failure policy: best-effort. A fetch/conversion/save failure logs a
 * warning and leaves the item and its classic page intact.
 *
 * @license GPL-2.0-or-later
 */
final class SourceArchive {

	/** Fixed English subpage name (decided; not localized). */
	public const SUBPAGE = 'archivedText';

	/**
	 * The archived-text title for a Source: page (or an existing archived
	 * page): `<base>/archivedText`.
	 */
	public static function titleFor( Title $sourceTitle ): ?Title {
		$base = $sourceTitle->isSubpage() ? $sourceTitle->getBaseTitle() : $sourceTitle;
		return Title::newFromText( $base->getPrefixedText() . '/' . self::SUBPAGE );
	}

	/**
	 * Fetches, converts and saves the archive page. No-op when the page
	 * already exists (idempotent). Never throws.
	 *
	 * @return bool whether the archive page exists after the call
	 */
	public static function create(
		Title $sourceTitle,
		string $sourceUrl,
		IContextSource $context,
		UserIdentity $user
	): bool {
		$title = self::titleFor( $sourceTitle );
		if ( $title === null ) {
			return false;
		}
		if ( $title->exists() ) {
			return true;
		}

		$html = ( new \EmbeddableContent\Fetch\PageMetadataFetcher() )
			->fetchHtml( $sourceUrl, 8.0 );
		if ( $html === null ) {
			return false;
		}

		$mediaNote = $context->msg( 'embeddablecontent-source-archive-media-note' )
			->inContentLanguage()->text();
		try {
			$wikitext = ( new ArchivedTextConverter() )->convert( $html, $sourceUrl, $mediaNote );
		} catch ( ArchivedTextException $e ) {
			LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
				'Source archive conversion failed for {url}: {error}',
				[ 'url' => $sourceUrl, 'error' => $e->getMessage() ]
			);
			return false;
		}
		if ( $wikitext === null ) {
			return false;
		}

		try {
			$page = MediaWikiServices::getInstance()
				->getWikiPageFactory()->newFromTitle( $title );
			$status = $page->doUserEditContent(
				new WikitextContent( $wikitext ),
				$user,
				$context->msg(
					'embeddablecontent-source-archive-edit-summary',
					$sourceTitle->getText()
				)->inContentLanguage()->text(),
				EDIT_NEW
			);
			if ( !$status->isOK() ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
				'Source archive save failed for {title}: {error}',
				[ 'title' => $title->getPrefixedText(), 'error' => $e->getMessage() ]
			);
			return false;
		}

		// The parent Source: page's cached row must see the new subpage.
		$sourceTitle->invalidateCache();
		return true;
	}

	/**
	 * PageSaveComplete hook: an archive subpage edit/creation invalidates
	 * the parent page (its `{{#source-archive:}}` row depends on the
	 * subpage's existence, which is not a template dependency).
	 */
	public static function onPageSaved( WikiPage $wikiPage ): void {
		$title = $wikiPage->getTitle();
		if ( $title !== null && self::isArchiveTitle( $title ) ) {
			$title->getBaseTitle()->invalidateCache();
		}
	}

	/** PageDeleteComplete hook: deleting the subpage restores the row-less state. */
	public static function onPageDeleted( ProperPageIdentity $page ): void {
		$title = Title::newFromText( $page->getDBkey(), $page->getNamespace() );
		if ( $title !== null && self::isArchiveTitle( $title ) ) {
			$title->getBaseTitle()->invalidateCache();
		}
	}

	public static function isArchiveTitle( Title $title ): bool {
		return defined( 'NS_SOURCE' )
			&& $title->getNamespace() === NS_SOURCE
			&& $title->isSubpage()
			&& $title->getSubpageText() === self::SUBPAGE;
	}
}
