<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Auto-inferred edit summary for the content-page "Add section" /
 * "Add section below" flows: when the contributor leaves the Summary blank,
 * the summary defaults to the MediaWiki section-edit convention (a comment
 * wrapped in slash-star ... star-slash) built from the TOPMOST heading of the
 * submitted wikitext — the heading with the fewest `=` signs (an `== H2 ==`
 * wins over a nested `=== H3 ===`).
 *
 * The heading scan mirrors core's own heading grammar
 * (BlockLevelPass: `^(={1,6})(.+?)(\1)\s*$`), so the inferred level matches
 * exactly how core parses the same line — including core's quirk that a
 * shorter closing run reads as a lower-level heading (`== A =` is an H1
 * whose text is `= A`). The heading text keeps any inline markup exactly as
 * core keeps it in a section-edit summary.
 *
 * MediaWiki-free, so it is unit-testable without a runtime.
 *
 * @license GPL-2.0-or-later
 */
final class SectionSummary {

	/** MediaWiki's comment length limit (comment table char limit). */
	public const COMMENT_LIMIT = 500;

	/** The comment's opening and closing delimiters, including the spaces. */
	private const OPEN = '/* ';
	private const CLOSE = ' */';

	/**
	 * @param string $content the submitted wikitext
	 * @return string|null the inferred summary comment, or null with no heading
	 */
	public static function infer( string $content ): ?string {
		$bestLevel = 7;
		$bestText = null;
		foreach ( explode( "\n", $content ) as $line ) {
			if ( !preg_match( '/^(={1,6})(.+?)(\1)\s*$/', $line, $matches ) ) {
				continue;
			}
			$level = strlen( $matches[1] );
			if ( $level < $bestLevel ) {
				$bestLevel = $level;
				$bestText = trim( $matches[2] );
			}
		}
		if ( $bestText === null || $bestText === '' ) {
			return null;
		}
		// Keep the delimiters even when the heading is longer than the comment
		// limit (the delimiters are what makes core link the summary back to
		// the section); truncate only the heading text.
		$room = max( 0, self::COMMENT_LIMIT - strlen( self::OPEN . self::CLOSE ) );
		if ( mb_strlen( $bestText ) > $room ) {
			$bestText = mb_substr( $bestText, 0, $room );
		}
		return self::OPEN . $bestText . self::CLOSE;
	}
}
