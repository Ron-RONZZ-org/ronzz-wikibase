<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Title logic for the print cover of a `Source:<label>/archivedText` page.
 *
 * The source page title carries the AddSource class-disambiguation suffix
 * ("… - Science Feedback (Webpage)"). The cover shows the work's title and
 * the class separately, so the suffix is removed from the title exactly once
 * — only when it matches the class the item actually records. A title whose
 * trailing parenthetical is part of the work's name is preserved.
 *
 * Pure static — unit-tested without MediaWiki.
 *
 * @license GPL-2.0-or-later
 */
final class ArchiveCover {

	/**
	 * @param string $baseText the source page title WITHOUT the namespace
	 * @param string $class the source class label ("Webpage"), or ''
	 * @return string the title without its trailing " (Class)" group
	 */
	public static function title( string $baseText, string $class ): string {
		$baseText = trim( $baseText );
		if ( $class !== '' ) {
			$suffix = ' (' . $class . ')';
			if ( str_ends_with( $baseText, $suffix ) ) {
				return trim( substr( $baseText, 0, -strlen( $suffix ) ) );
			}
		}
		return $baseText;
	}

	/**
	 * The trailing "(…)" group of a title — a fallback for an item whose
	 * class statements cannot be resolved.
	 *
	 * @param string $text
	 * @return string the text inside the trailing group, or ''
	 */
	public static function suffix( string $text ): string {
		if ( preg_match( '/\S\s*\(([^()]*)\)\s*$/u', trim( $text ), $matches ) === 1 ) {
			return trim( $matches[1] );
		}
		return '';
	}
}
