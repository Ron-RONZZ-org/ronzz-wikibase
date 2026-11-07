<?php

declare( strict_types = 1 );

namespace EmbeddableContent\SourceArchive;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * The prominent disclaimer banner on a `Source:<label>/archivedText` page.
 *
 * Server-rendered by Hooks::onBeforePageDisplay (OutputPage, not parser
 * output) so it can be dismissed for the current view only — a page refresh
 * shows it again — and it prints with the page (Save-as-PDF / Ctrl-P).
 *
 * The "(Media content: view on original URL)" note inside the archived text
 * links the same source URL; the banner repeats it prominently together with
 * the archive timestamp (the page's first revision).
 *
 * @license GPL-2.0-or-later
 */
final class SourceArchiveBanner {

	public static function isArchivePage( Title $title ): bool {
		return SourceArchive::isArchiveTitle( $title );
	}

	/**
	 * @param Title $title the archive page (`…/archivedText`)
	 */
	public static function html(
		Title $title,
		IContextSource $context,
		EmbeddableContentConfig $config
	): string {
		$revision = MediaWikiServices::getInstance()->getRevisionStore()
			->getFirstRevision( $title );
		$timestamp = $revision !== null ? $revision->getTimestamp() : null;

		$url = self::sourceUrl( $title, $config );
		$link = $url !== null
			? Html::element(
				'a',
				[ 'href' => $url, 'class' => 'external', 'rel' => 'nofollow' ],
				$url
			)
			// No URL recorded: name the source page rather than emit a dead
			// "at $1" — the banner still carries the disclaimer.
			: htmlspecialchars( $title->getBaseTitle()->getPrefixedText() );

		$dateHtml = '';
		if ( $timestamp !== null ) {
			$dateHtml = Html::element(
				'time',
				[ 'datetime' => wfTimestamp( TS_ISO_8601, $timestamp ) ],
				$context->getLanguage()->userTimeAndDate( $timestamp, $context->getUser() )
			);
		}

		$message = $context->msg( 'embeddablecontent-source-archive-banner' )
			->rawParams( $link, $dateHtml )
			->parse();

		$dismiss = $context->msg( 'embeddablecontent-source-archive-dismiss' )->text();

		return Html::rawElement(
			'div',
			[ 'class' => 'wb-sourcearchive-banner', 'role' => 'note' ],
			Html::rawElement( 'div', [ 'class' => 'wb-sourcearchive-body' ], $message )
			. Html::element(
				'button',
				[
					'type' => 'button',
					'class' => 'mw-ui-button wb-sourcearchive-dismiss',
					'aria-label' => $dismiss,
				],
				$dismiss
			)
		);
	}

	/** The external URL the page was archived from, if the item records one. */
	private static function sourceUrl( Title $title, EmbeddableContentConfig $config ): ?string {
		$base = $title->getBaseTitle();
		$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( 'wikibase', $base->getPrefixedText() );
		if ( $itemId === null ) {
			return null;
		}
		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return null;
		}

		$props = $config->sourcePropertyIds();
		foreach ( [ 'url', 'accessUrl' ] as $key ) {
			$propertyId = $props[$key] ?? null;
			if ( $propertyId === null ) {
				continue;
			}
			foreach ( $entity->getStatements() as $statement ) {
				$snak = $statement->getMainSnak();
				if ( !$snak instanceof PropertyValueSnak
					|| $snak->getPropertyId()->getSerialization() !== $propertyId
				) {
					continue;
				}
				$value = $snak->getDataValue();
				if ( $value instanceof StringValue
					&& preg_match( '#^https?://#i', $value->getValue() )
				) {
					return $value->getValue();
				}
			}
		}
		return null;
	}
}
