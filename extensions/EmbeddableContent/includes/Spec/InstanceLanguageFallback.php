<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use MediaWiki\Language\LanguageFallback;
use MediaWiki\Language\LanguageFallbackMode;

/**
 * A `LanguageFallback` that appends the instance's configured fallback
 * languages (Req 3 + Req 4). It decorates MediaWiki's own service and is
 * installed ONLY on Wikibase's `LanguageFallbackChainFactory` (via a
 * `MediaWikiServices` service manipulator), never on the global
 * `LanguageFallback` service — so message fallback is untouched.
 *
 * Wikibase's chain builder calls `getAll($code, STRICT)`; this subclass
 * returns the parent chain plus `fallbackLanguages` (see
 * {@see FallbackChain::append}). `getAllIncludingSiteLanguage()` keeps the
 * parent behaviour (it is not used by the term factory).
 *
 * @license GPL-2.0-or-later
 */
class InstanceLanguageFallback extends LanguageFallback {

	/** @var string[] */
	private $extra;

	/**
	 * @param string $siteLangCode the wiki content-language code
	 * @param mixed $localisationCache the LocalisationCache service
	 * @param mixed $langNameUtils the LanguageNameUtils service
	 * @param string[] $extra the instance fallback languages to append
	 */
	public function __construct( $siteLangCode, $localisationCache, $langNameUtils, array $extra ) {
		parent::__construct( $siteLangCode, $localisationCache, $langNameUtils );
		$this->extra = $extra;
	}

	/** @inheritDoc */
	public function getAll( $code, $mode = LanguageFallbackMode::MESSAGES ) {
		return FallbackChain::append(
			parent::getAll( $code, $mode ),
			(string)$code,
			$this->extra
		);
	}

}
