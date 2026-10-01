<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

/**
 * Renders stored content fragments as wikitext (rich content): quotation
 * payloads and math notes may carry `[[File:…]]`, links, emphasis, `$…$`
 * etc. Two entry points:
 *
 *  - renderWithParser() — the `{{#content:}}` parser-function path, which
 *    already has a Parser: expands the wikitext in the current parse
 *    (`recursiveTagParse`), so the fragment's files/links/modules land in the
 *    page's ParserOutput and the parser cache dependency is recorded.
 *  - render() — the parser-less paths (the embed renderer and the
 *    `Special:QuotationsOf` listing): creates a Parser, parses the fragment
 *    and returns the HTML plus the module metadata the caller must load.
 *
 * Both are exception-safe: a parse failure degrades to escaped text, never a
 * 500. Wikitext parsing is MediaWiki's own sanitizer — the XSS boundary for
 * rich content (the parse never enables raw HTML).
 *
 * @license GPL-2.0-or-later
 */
final class RichTextRenderer {

	/**
	 * Expands a wikitext fragment inside an EXISTING parse (the
	 * `{{#content:}}` path). The returned HTML is half-parsed parser output
	 * meant to be embedded in the current function's HTML result.
	 */
	public function renderWithParser( Parser $parser, string $wikitext ): string {
		if ( trim( $wikitext ) === '' ) {
			return '';
		}
		try {
			return $parser->recursiveTagParse( $wikitext );
		} catch ( \Throwable $e ) {
			error_log( 'EmbeddableContent: rich-text parse (parser context) failed: '
				. get_class( $e ) . ': ' . $e->getMessage() );
			return self::escape( $wikitext );
		}
	}

	/**
	 * Parses a wikitext fragment OUTSIDE a parser context (the embed renderer
	 * and the `Special:QuotationsOf` listing). The caller must load the
	 * returned modules/styles on its output page.
	 *
	 * @param string $wikitext
	 * @param Title|null $title parse context (magic words, redlinks); the
	 *  request title is the fallback
	 */
	public function render( string $wikitext, ?Title $title = null ): RichTextResult {
		if ( trim( $wikitext ) === '' ) {
			return new RichTextResult( '', [], [] );
		}
		try {
			$services = MediaWikiServices::getInstance();
			$parser = $services->getParserFactory()->create();
			$context = RequestContext::getMain();
			$options = ParserOptions::newFromContext( $context );
			$target = $title ?? $context->getTitle() ?? Title::newMainPage();
			$output = $parser->parse( $wikitext, $target, $options );
			return new RichTextResult(
				$output->getContentHolderText(),
				array_values( array_unique( $output->getModules() ) ),
				array_values( array_unique( $output->getModuleStyles() ) )
			);
		} catch ( \Throwable $e ) {
			// A parse failure degrades to the escaped source text — the
			// fragment still shows, never a 500.
			error_log( 'EmbeddableContent: rich-text parse failed: '
				. get_class( $e ) . ': ' . $e->getMessage() );
			return new RichTextResult( self::escape( $wikitext ), [], [] );
		}
	}

	private static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
