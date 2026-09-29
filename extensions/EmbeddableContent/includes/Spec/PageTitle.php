<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use MediaWiki\Title\Title;

/**
 * Derives a valid classic-page Title from an item label — the single
 * label→title contract shared by the Add/Update flows and the
 * Special:NewItem hook.
 *
 * LabelSanitizer::normalizeForTitle strips markup and maps the characters
 * MediaWiki forbids in titles (`# < > [ ] { } |`) to dashes. It deliberately
 * does NOT change the case: the item label is stored verbatim.
 *
 * Title::makeTitle, however, does NOT normalize the first letter, and
 * Title::isValid() rejects a lowercase-initial title while $wgCapitalLinks
 * is on — so a label like "vector space" (the Q1862 report) produced an
 * INVALID title and every page-creation path silently returned early: no
 * page, no sitelink. Title::capitalize applies the namespace's
 * capitalization rule (per $wgCapitalLinks / $wgCapitalLinkOverrides),
 * exactly as Title::newFromText would, WITHOUT parsing a namespace prefix
 * out of the label (a label may legitimately contain a colon).
 *
 * MediaWiki-bound (Title); exercised by the integration/E2E suites rather
 * than the pure-PHP unit suite.
 *
 * @license GPL-2.0-or-later
 */
final class PageTitle {

	/**
	 * @param string $label the item label (raw; markup is stripped here)
	 * @param int $namespace the target namespace ID (NS_MAIN, NS_SOURCE, …)
	 * @return Title|null a valid title in $namespace, or null when the label
	 *   normalizes to empty or yields an unusable title
	 */
	public static function fromLabel( string $label, int $namespace ): ?Title {
		$label = LabelSanitizer::normalizeForTitle( $label );
		if ( $label === '' ) {
			return null;
		}
		$label = Title::capitalize( $label, $namespace );
		try {
			$title = Title::makeTitle( $namespace, $label );
		} catch ( \Throwable $e ) {
			return null;
		}
		return ( $title !== null && $title->getNamespace() === $namespace && $title->isValid() )
			? $title
			: null;
	}

}
