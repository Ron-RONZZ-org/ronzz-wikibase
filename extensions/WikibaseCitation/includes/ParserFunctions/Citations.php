<?php

declare( strict_types = 1 );

namespace WikibaseCitation\ParserFunctions;

use MediaWiki\Parser\Parser;
use WikibaseCitation\CitationDependencies;
use WikibaseCitation\CitationEngine;
use WikibaseCitation\CitationException;

/**
 * `{{#citations:Q42|Q7}}` parser function (issue #24 v1, issue #25 v2).
 *
 * Aggregated bibliography of the distinct *source items* cited on the page:
 * `{{#cite}}` calls record each cited entity's source item id on the
 * ParserOutput extension data (deduped by source item — multiple quotes
 * from one book collapse to one entry); this function renders one entry per
 * distinct source item, in first-citation order.
 *
 * Two modes (issue #25 v2):
 * - **No arguments** — the accumulated mode: every source item cited on the
 *   page (via `{{#cite}}` or embed URLs) is collected. Rendering cannot
 *   happen at call time (cite calls inside `<ref>` tags are expanded during
 *   the same document-order DOM walk), so the function emits an
 *   unregistered `UNIQ-…-QINU` marker and the `ParserAfterTidy` hook (see
 *   `Hooks::onParserAfterTidy`) substitutes the bibliography once the whole
 *   page — including Cite's own ref processing — has finished.
 * - **Explicit entity ids** — `{{#citations:Q42|Q7}}` renders the sources
 *   of exactly those entities immediately (no parse-time accumulation
 *   dependency), as a fallback for unusual embedding contexts.
 *
 * @license GPL-2.0-or-later
 */
class Citations {

	/**
	 * Invisible placeholder that ParserAfterTidy substitutes.
	 *
	 * Deliberately an *unregistered* ASCII marker in MediaWiki's marker
	 * shape (`UNIQ-…-QINU`) rather than an HTML comment: comments are
	 * stripped by Sanitizer during internalParse, and registered strip
	 * markers are unstripped before ParserAfterTidy fires. An unregistered
	 * marker passes through StripState untouched (`return $m[0]` in
	 * StripState::unstripType) and survives tidy (no control characters —
	 * the real markers carry \x7f, which RemexHtml drops), so the
	 * ParserAfterTidy hook sees it intact and can substitute the
	 * bibliography. If substitution is ever missed, the marker would render
	 * literally — a rare, visible, harmless failure.
	 */
	public const PLACEHOLDER = 'UNIQ-wbc-citations-00000001-QINU';

	/**
	 * @param string[] $args raw arguments (explicit entity ids or none)
	 * @return array{text:string,noparse:bool,isHTML?:bool}
	 */
	public static function onCitations(
		CitationEngine $engine,
		CitationDependencies $dependencies,
		Parser $parser,
		array $args
	): array {
		$opts = CitationArgs::parse( $args );

		if ( $opts['entities'] !== [] ) {
			return self::explicit( $engine, $dependencies, $parser, $opts['entities'] );
		}
		// Accumulated mode: plain text (the placeholder marker); noparse
		// keeps it out of the preprocessor, so ParserAfterTidy sees it
		// intact in the final text.
		return [ 'text' => self::PLACEHOLDER, 'noparse' => true ];
	}

	/**
	 * Explicit mode: resolve the source item of each given entity and render
	 * the bibliography right away (no accumulation dependency). Per-entity
	 * failures render as inline errors — never fatal.
	 *
	 * @param string[] $entityIds
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	private static function explicit(
		CitationEngine $engine,
		CitationDependencies $dependencies,
		Parser $parser,
		array $entityIds
	): array {
		$sources = [];
		$errors = '';
		foreach ( $entityIds as $entityId ) {
			try {
				$sourceId = $engine->sourceIdFor( $entityId );
				if ( $sourceId !== null ) {
					$sources[] = $sourceId->getSerialization();
				}
			} catch ( CitationException $e ) {
				$errors .= '<span class="error">'
					. wfMessage( 'wikibasecitation-cite-error-notfound' )->params( $entityId )->inContentLanguage()->text()
					. '</span>';
			}
		}
		$dependencies->register( $parser, $entityIds );
		$sources = array_values( array_unique( $sources ) );

		return [ 'text' => $errors . self::buildBibliography( $engine, $sources ), 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * Renders the bibliography for a list of source item ids (deduped by
	 * the caller). One entry per id via the shared CitationEngine, default
	 * apa/html, wrapped in an ordered list. Failures render as an inline
	 * error entry — never fatal.
	 *
	 * @param string[] $sourceIds deduplicated source item ids in citation order
	 */
	public static function buildBibliography( CitationEngine $engine, array $sourceIds ): string {
		if ( $sourceIds === [] ) {
			return '';
		}
		$entries = '';
		foreach ( $sourceIds as $sourceId ) {
			$entries .= '<li>' . self::renderEntry( $engine, $sourceId ) . "</li>\n";
		}
		return '<ol class="wikibasecitation-sources">' . "\n" . $entries . '</ol>';
	}

	private static function renderEntry( CitationEngine $engine, string $sourceId ): string {
		try {
			return $engine->render( $sourceId, 'apa', 'html' );
		} catch ( CitationException $e ) {
			// wfMessage()->text() HTML-escapes message + params.
			return '<span class="error">'
				. wfMessage( 'wikibasecitation-cite-error-notfound' )->params( $sourceId )->inContentLanguage()->text()
				. '</span>';
		}
	}
}
