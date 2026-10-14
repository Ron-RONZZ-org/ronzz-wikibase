<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Pure text assembly for the "Add section" append action
 * (`EmbeddableContent\Action\AppendSectionAction`): appends the contributor's
 * wikitext to the END of the page's current content, separated by a blank
 * line so the appended block starts as its own paragraph. No heading is
 * added (the old core `section=new` flow inserted a `== Subject ==` heading
 * from the summary field; this action deliberately does not).
 *
 * MediaWiki-free, so it is unit-testable without a runtime.
 *
 * @license GPL-2.0-or-later
 */
final class AppendSectionText {

	/**
	 * @param string $current the page's current wikitext
	 * @param string $added the text to append (trimmed)
	 * @return string the new page wikitext
	 */
	public static function append( string $current, string $added ): string {
		$added = trim( $added );
		if ( $added === '' ) {
			return $current;
		}
		// An empty (or whitespace-only) page: the added text IS the content.
		if ( trim( $current ) === '' ) {
			return $added;
		}
		// Trim the trailing whitespace of the current content so the separator
		// is exactly one blank line, regardless of how the page ended.
		return rtrim( $current ) . "\n\n" . $added;
	}
}
