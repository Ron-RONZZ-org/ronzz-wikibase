<?php

declare( strict_types = 1 );

namespace WikibaseCitation\ParserFunctions;

use MediaWiki\Parser\Parser;
use WikibaseCitation\CitationDependencies;
use WikibaseCitation\CitationEngine;
use WikibaseCitation\CitationEntityNotFoundException;
use WikibaseCitation\CitationException;
use WikibaseCitation\InvalidCitationIdException;
use WikibaseCitation\SourceLink;
use WikibaseCitation\SourcePageResolver;

/**
 * `{{#cite:Q42|Q7|style=apa|output=html}}` parser function (issue #24 v1,
 * issue #25 v2 multi-entity).
 *
 * Cite-by-QID: renders a citation for one or more Wikibase items from the
 * item graph. Usable inside the stock Cite extension's `<ref>` — the
 * returned HTML is marked `noparse`/`isHTML`, so it is strip-marker-
 * protected through Cite's re-parse of the ref content and never
 * re-processed as wikitext. Each call records the cited entities' *source
 * item ids* on the ParserOutput extension data, where `{{#citations:}}`
 * picks them up (deduped by source item), and registers the entities +
 * sources as parser-cache dependencies (edit → re-render, issue #25 v2).
 *
 * Error output is a localized `<span class="error">` — visible inline but
 * never fatal (the ADR's "missing fields are omitted, never fatal" spirit).
 *
 * 2026-09 UX batch: a single-entity citation whose source item has a classic
 * wiki page (a `wikibase` sitelink — Source:/Book:…) is wrapped in an anchor
 * to that page, so a `<references/>` footnote links back to its source. The
 * wrap happens AFTER the engine's sanitizer, so the cached and API outputs
 * stay link-free and the href follows the current sitelink.
 *
 * @license GPL-2.0-or-later
 */
class CiteQ {

	/** ParserOutput extension-data key for the accumulated source ids. */
	public const EXT_DATA_KEY = 'WikibaseCitationCitedSources';

	/** Default style when the `style` argument is absent. */
	public const DEFAULT_STYLE = 'apa';

	/** Default output format when the `output` argument is absent. */
	public const DEFAULT_OUTPUT = 'html';

	/**
	 * @param string[] $args raw arguments (entity ids + style/output)
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onCite(
		CitationEngine $engine,
		CitationDependencies $dependencies,
		SourcePageResolver $sourcePages,
		Parser $parser,
		array $args
	): array {
		$opts = CitationArgs::parse( $args );
		$entities = $opts['entities'];

		$style = $opts['style'] !== '' ? $opts['style'] : self::DEFAULT_STYLE;
		$format = $opts['output'] !== '' ? $opts['output'] : self::DEFAULT_OUTPUT;
		// Labels follow the READER's language (the parser cache splits per
		// user language automatically, see ParserOptions::getUserLangObj).
		$language = $parser->getOptions()->getUserLangObj()->getCode();

		if ( $entities === [] ) {
			// wfMessage()->text() HTML-escapes message + params: the result
			// is safe to embed inside the error span without re-escaping.
			return self::html( self::error(
				wfMessage( 'wikibasecitation-cite-error-missing-id' )->inContentLanguage()->text()
			) );
		}

		try {
			if ( count( $entities ) === 1 ) {
				[ $html, $sourceId ] = $engine->renderWithSourceId( $entities[0], $style, $format, $language );
				$sourceIds = $sourceId !== null ? [ $sourceId->getSerialization() ] : [];
				// Footnote → Source: page hyperlink (2026-09 UX batch): the
				// citation for a source item that has a classic page is
				// wrapped in a link to it. Only the single-entity form gets
				// the wrapper (a multi-entity footnote has several sources).
				// Wrapped AFTER the engine's sanitizer, so the cached/API
				// output stays link-free and the link follows the CURRENT
				// sitelink (a page rename never serves a stale href).
				if ( $sourceId !== null ) {
					$html = SourceLink::wrap( $html, $sourcePages->pageUrl( $sourceId ) );
				}
			} else {
				[ $html, $sourceIds ] = $engine->renderList( $entities, $style, $format, $language );
			}
		} catch ( InvalidCitationIdException $e ) {
			return self::html( self::error(
				wfMessage( 'wikibasecitation-cite-error-invalidentity' )->params( $e->getEntityId() )->inContentLanguage()->text()
			) );
		} catch ( CitationEntityNotFoundException $e ) {
			return self::html( self::error(
				wfMessage( 'wikibasecitation-cite-error-notfound' )->params( $e->getEntityId() )->inContentLanguage()->text()
			) );
		} catch ( CitationException $e ) {
			return self::html( self::error(
				wfMessage( 'wikibasecitation-cite-error-style' )->params( implode( ', ', $entities ), $style )->inContentLanguage()->text()
			) );
		}

		foreach ( $sourceIds as $sourceId ) {
			// Deduped by construction: appendExtensionData's UNION strategy
			// stores values as a set keyed by the appended value.
			$parser->getOutput()->appendExtensionData( self::EXT_DATA_KEY, $sourceId );
		}

		// ParserCache invalidation: editing a cited entity or its source
		// re-renders this page (templatelinks + RefreshLinksJob, issue #25).
		$dependencies->register( $parser, array_merge( $entities, $sourceIds ) );

		return self::html( $html );
	}

	/**
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	private static function html( string $html ): array {
		return [ 'text' => $html, 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * Wraps an already-escaped message text in a parser-error span.
	 */
	private static function error( string $messageText ): string {
		return '<span class="error">' . $messageText . '</span>';
	}
}
