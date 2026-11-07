<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\SourceArchive\SourceArchive;
use MediaWiki\Parser\Parser;

/**
 * `{{#source-archive:}}` parser function — the "Archived text" auto-link on
 * a Source: page.
 *
 * Resolves the CURRENT page (a `Source:` page) and renders a complete table
 * row when its `Source:<label>/archivedText` subpage exists:
 *
 *   | Archived text || [[Source:…/archivedText|Archived text]]
 *
 * When the subpage does not exist it returns '' — the row disappears
 * entirely, mirroring `{{#quotations-of:}}` / `{{#child-items-of:}}` (the
 * instance has no ParserFunctions `#if`, so the hiding lives in the
 * function). The subpage save/delete invalidates this page's parser cache
 * (SourceArchive::onPageSaved / onPageDeleted), so the row appears without a
 * manual purge.
 *
 * Returns WIKITEXT (not HTML): the row participates in the template's
 * normal parse.
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

		$archive = SourceArchive::titleFor( $title );
		if ( $archive === null || !$archive->exists() ) {
			return $empty;
		}

		$language = $parser->getTargetLanguage();
		$label = wfMessage( 'embeddablecontent-source-archive-row-label' )
			->inLanguage( $language )->text();
		$linkText = wfMessage( 'embeddablecontent-source-archive-row-link' )
			->inLanguage( $language )->text();

		return [
			'text' => "\n|-\n| " . $label . ' || [['
				. $archive->getPrefixedText() . '|' . $linkText . ']]',
			'noparse' => false,
			'isHTML' => false,
		];
	}
}
