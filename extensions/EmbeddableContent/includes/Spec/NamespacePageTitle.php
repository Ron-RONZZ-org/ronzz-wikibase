<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Display-title text for a content page in a non-main namespace.
 *
 * A page such as `Source:Beloved` renders its H1 as "Beloved in Source":
 * the page name without the namespace prefix, the localized connector, and
 * the localized namespace name. The formatting is pure so the rule is
 * unit-tested independently of MediaWiki's Title/Message services.
 *
 * The `User:` namespace has its own title rule (UserPageTitle); this helper
 * is the generalisation for every other content namespace.
 *
 * @license GPL-2.0-or-later
 */
final class NamespacePageTitle {

	/**
	 * @param string $pageText the title text WITHOUT the namespace
	 *  (e.g. "Beloved" or "Beloved/fr")
	 * @param string $namespaceName the localized namespace name (e.g. "Source")
	 * @param string $in the localized connector word ("in", "dans", "en")
	 * @return string|null null when a part is blank (the caller keeps the
	 *  real page title)
	 */
	public static function format( string $pageText, string $namespaceName, string $in ): ?string {
		$pageText = trim( $pageText );
		$namespaceName = trim( $namespaceName );
		$in = trim( $in );
		if ( $pageText === '' || $namespaceName === '' || $in === '' ) {
			return null;
		}
		return $pageText . ' ' . $in . ' ' . $namespaceName;
	}

}
