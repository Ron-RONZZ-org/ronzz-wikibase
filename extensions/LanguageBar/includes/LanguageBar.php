<?php

declare( strict_types = 1 );

namespace LanguageBar;

use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;

/**
 * The "languages bar": a static-translation switcher linking a content page
 * to its English original and its /fr and /eo copies.
 *
 * The bar is included by default on every content page
 * (Hooks::onOutputPageBeforeHTML) instead of being added by hand; the
 * on-wiki Template:Languages is a thin wrapper around the same builder (the
 * `{{#languagebar:}}` parser function), so both render identically.
 *
 * @license GPL-2.0-or-later
 */
final class LanguageBar {

	/** CSS class of the bar (also the marker the injection checks for). */
	public const BAR_CLASS = 'languages-bar';

	/** Inline styling of the bar — kept identical to the historical template. */
	public const BAR_STYLE = 'border:1px solid #a2a9b1;background:#f8f9fa;padding:0.3em 1em;margin:0 0 1em;';

	/** Static translation languages: code => autonym shown in the bar. */
	public const TRANSLATION_LANGUAGES = [
		'fr' => 'français',
		'eo' => 'Esperanto',
	];

	/**
	 * Pure: assemble the bar from a label and pre-rendered link HTML.
	 *
	 * The `<p>` matches the historical Template:Languages rendering (the
	 * wikitext line is paragraph-wrapped by the parser).
	 *
	 * @param string $label e.g. "Languages"
	 * @param string[] $links pre-rendered, already-escaped <a> elements
	 */
	public static function compose( string $label, array $links ): string {
		return '<div class="' . self::BAR_CLASS . '" style="' . self::BAR_STYLE . '">'
			. '<p><b>' . htmlspecialchars( $label, ENT_QUOTES ) . ':</b> '
			. implode( ' · ', $links )
			. '</p></div>';
	}

	/**
	 * The bar for $title, linking $title, $title/fr and $title/eo. A target
	 * equal to the current page renders as a self-link, matching the parser's
	 * rendering of `[[{{FULLPAGENAME}}|English]]`.
	 */
	public static function buildHtml(
		Title $title,
		Title $currentTitle,
		LinkRenderer $linkRenderer,
		string $label
	): string {
		$links = [ self::renderLink( $title, 'English', $currentTitle, $linkRenderer ) ];
		foreach ( self::TRANSLATION_LANGUAGES as $code => $autonym ) {
			$subpage = $title->getSubpage( $code );
			if ( $subpage !== null ) {
				$links[] = self::renderLink( $subpage, $autonym, $currentTitle, $linkRenderer );
			}
		}
		return self::compose( $label, $links );
	}

	/**
	 * Parser-function body for `{{#languagebar:}}` — the current page by
	 * default, or the page named in the first argument.
	 *
	 * @param mixed[] $args
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function renderFromParser( Parser $parser, array $args ): array {
		$page = trim( (string)( $args[0] ?? '' ) );
		$currentTitle = $parser->getTitle();
		$title = $page === '' ? $currentTitle : Title::newFromText( $page );
		if ( $title === null || $currentTitle === null ) {
			return [ 'text' => '', 'noparse' => true, 'isHTML' => true ];
		}
		$label = wfMessage( 'languagebar-label' )
			->inLanguage( $parser->getTargetLanguage() )
			->text();
		return [
			'text' => self::buildHtml(
				$title,
				$currentTitle,
				MediaWikiServices::getInstance()->getLinkRenderer(),
				$label
			),
			'noparse' => true,
			'isHTML' => true,
		];
	}

	/**
	 * @param int[] $allowed namespace ids
	 */
	public static function isEnabledNamespace( int $namespace, array $allowed ): bool {
		return in_array( $namespace, $allowed, true );
	}

	/**
	 * True when a page name is a /fr or /eo translation copy (which carries
	 * the {{Translation}} banner and must not get a second bar).
	 */
	public static function isTranslationSubpageName( string $pageName ): bool {
		foreach ( array_keys( self::TRANSLATION_LANGUAGES ) as $code ) {
			if ( str_ends_with( $pageName, '/' . $code ) ) {
				return true;
			}
		}
		return false;
	}

	private static function renderLink(
		Title $target,
		string $text,
		Title $currentTitle,
		LinkRenderer $linkRenderer
	): string {
		if ( $target->equals( $currentTitle ) ) {
			return Linker::makeSelfLinkObj( $target, htmlspecialchars( $text, ENT_QUOTES ) );
		}
		return $linkRenderer->makeLink( $target, $text );
	}
}
