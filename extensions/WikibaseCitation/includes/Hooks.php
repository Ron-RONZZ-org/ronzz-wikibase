<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use WikibaseCitation\ParserFunctions\Citations;
use WikibaseCitation\ParserFunctions\CiteQ;

/**
 * Registration-time hooks (issue #6 §7; issue #24 cite-by-QID; issue #25 v2).
 *
 * @license GPL-2.0-or-later
 */
class Hooks {

	/**
	 * Wire the extension's own composer vendor autoloader into MediaWiki.
	 *
	 * The instance deployment installs composer deps inside the extension
	 * directory (not at the wiki root via the merge plugin), so the root
	 * autoloader knows nothing about seboettg/citeproc-php — without this,
	 * every apa/vancouver citation request fatals with
	 * 'Class "Seboettg\CiteProc\CiteProc" not found'. No-op when the vendor
	 * dir is absent (e.g. composer install at the wiki root was used).
	 */
	public static function onRegistration(): void {
		$autoload = __DIR__ . '/../vendor/autoload.php';
		if ( is_file( $autoload ) ) {
			require_once $autoload;
		}
	}

	/**
	 * Register the {{#cite}} and {{#citations:}} parser functions. The
	 * engine + dependencies services are captured by the closures so the
	 * function classes stay service-injected and MediaWikiServices-free.
	 */
	public static function onParserFirstCallInit( Parser $parser ): void {
		$services = MediaWikiServices::getInstance();
		$engine = $services->get( 'WikibaseCitation.CitationEngine' );
		$dependencies = $services->get( 'WikibaseCitation.CitationDependencies' );

		$parser->setFunctionHook( 'cite', static function ( Parser $parser, ...$args ) use ( $engine, $dependencies ): array {
			return CiteQ::onCite( $engine, $dependencies, $parser, $args );
		} );

		$parser->setFunctionHook( 'citations', static function ( Parser $parser, ...$args ) use ( $engine, $dependencies ): array {
			return Citations::onCitations( $engine, $dependencies, $parser, $args );
		} );
	}

	/**
	 * Substitute the `{{#citations:}}` placeholder with the page's
	 * bibliography (issue #24), extended by the v2 embed auto-collect
	 * (issue #25): the rendered HTML is scanned for embed URLs
	 * (`Special:Embed/<id>`, `/embed/<id>`, `action=embed&entity=<id>`) and
	 * the embedded entities' source items join the bibliography — so pages
	 * that embed content but never `{{#cite}}` it still get a "Sources"
	 * section. Best-effort and parse-order independent (the scan runs on
	 * the final text, after every expansion).
	 *
	 * Runs after the whole parse — including Cite's own `<references/>`
	 * processing — so every `{{#cite}}` source id has been accumulated on
	 * the ParserOutput. No-op when the page has no `{{#citations:}}` call.
	 */
	public static function onParserAfterTidy( Parser $parser, string &$text ): void {
		$sourceIds = $parser->getOutput()->getExtensionData( CiteQ::EXT_DATA_KEY );
		// appendExtensionData's UNION strategy stores values as a set
		// (id => true) — array_keys restores the deduped citation order.
		$sourceIds = is_array( $sourceIds ) ? array_keys( $sourceIds ) : [];

		// Duplicate-footnote merge: Cite merges only name-attributed refs, so
		// `<ref>{{#cite:Q985}}</ref>` used several times would otherwise
		// render one footnote per use. Whenever the page cited entities (even
		// without a {{#citations:}} collector — the classic page pattern), the
		// rendered references list collapses identical footnotes into one
		// with N backlinks (see ReferencesMerger).
		if ( $sourceIds !== [] ) {
			$text = ReferencesMerger::mergeDuplicateFootnotes( $text );
		}

		if ( strpos( $text, Citations::PLACEHOLDER ) === false ) {
			return;
		}
		$services = MediaWikiServices::getInstance();
		$engine = $services->get( 'WikibaseCitation.CitationEngine' );
		$dependencies = $services->get( 'WikibaseCitation.CitationDependencies' );

		// v2 embed auto-collect: embedded entities join the bibliography
		// through their source items; they also become cache dependencies.
		foreach ( self::extractEmbedEntityIds( $text ) as $embedId ) {
			try {
				$sourceId = $engine->sourceIdFor( $embedId );
				if ( $sourceId !== null ) {
					$sourceIds[] = $sourceId->getSerialization();
				}
			} catch ( CitationException $e ) {
				// Malformed / unknown embed ids are ignored (best-effort).
				continue;
			}
			$dependencies->register( $parser, [ $embedId ] );
		}
		$sourceIds = array_values( array_unique( $sourceIds ) );

		// The reader's language was recorded by the {{#citations:}} call
		// (accumulated mode); labels must follow it, not a hardcoded order.
		$language = $parser->getOutput()->getExtensionData( Citations::LANGUAGE_DATA_KEY );
		$html = Citations::buildBibliography( $engine, $sourceIds, is_string( $language ) ? $language : null );
		$text = str_replace( Citations::PLACEHOLDER, $html, $text );
	}

	/**
	 * Extracts entity ids from embed URLs in the rendered HTML: the
	 * `Special:Embed/QN` page, the nginx `/embed/QN` rewrite and the
	 * `api.php?action=embed&entity=QN` endpoint (both `&` and `&amp;`).
	 *
	 * @return string[] normalized entity ids (uppercase), in document order
	 */
	private static function extractEmbedEntityIds( string $text ): array {
		$ids = [];
		$patterns = [
			'#(?:Special:Embed|/embed)/\s*([QqPp]\d+)#',
			'#(?:[?&]|&amp;)entity=([QqPp]\d+)#',
		];
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $text, $m ) ) {
				foreach ( $m[1] as $id ) {
					$ids[] = strtoupper( $id );
				}
			}
		}
		return $ids;
	}
}
