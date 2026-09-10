<?php

declare( strict_types = 1 );

namespace LanguageBar;

use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;

/**
 * LanguageBar entry-point hooks.
 *
 * @license GPL-2.0-or-later
 */
final class Hooks {

	/** Guard against OutputPageBeforeHTML firing more than once per view. */
	private static bool $injected = false;

	/**
	 * Prepend the languages bar to a content page's HTML — the automatic
	 * inclusion. Skipped when the page already renders a bar (an explicit
	 * {{Languages}} call, including one reached through a transcluded
	 * template) or is a /fr|/eo translation copy.
	 */
	public static function onOutputPageBeforeHTML( OutputPage $out, string &$text ): void {
		if ( self::$injected || !$out->isArticle() ) {
			return;
		}
		// Diffs set the article flag too — never decorate a diff.
		if ( $out->getRequest()->getInt( 'diff' ) !== 0 ) {
			return;
		}
		$title = $out->getTitle();
		if ( $title === null ) {
			return;
		}
		if ( !LanguageBar::isEnabledNamespace( $title->getNamespace(), self::allowedNamespaces() ) ) {
			return;
		}
		if ( str_contains( $text, LanguageBar::BAR_CLASS )
			|| str_contains( $text, 'translation-banner' )
			|| LanguageBar::isTranslationSubpageName( $title->getPrefixedText() )
		) {
			return;
		}
		$services = MediaWikiServices::getInstance();
		$label = wfMessage( 'languagebar-label' )->text();
		$text = LanguageBar::buildHtml( $title, $title, $services->getLinkRenderer(), $label ) . $text;
		self::$injected = true;
	}

	/**
	 * `{{#languagebar:}}` — the parser-function form used by
	 * Template:Languages (single source of truth with the automatic bar).
	 */
	public static function onParserFirstCallInit( Parser $parser ): void {
		$parser->setFunctionHook( 'languagebar', static function ( Parser $parser, ...$args ): array {
			return LanguageBar::renderFromParser( $parser, $args );
		} );
	}

	/**
	 * @return int[] namespace ids the bar is injected on
	 */
	private static function allowedNamespaces(): array {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$allowed = $config->get( 'LanguageBarNamespaces' );
		if ( is_array( $allowed ) ) {
			return $allowed;
		}
		$allowed = $config->get( 'ContentNamespaces' );
		$allowed[] = NS_HELP;
		return $allowed;
	}
}
