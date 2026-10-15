<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\Pyodide4MediaWiki;

/**
 * Pure detection of `pyodide`-marked code blocks in parsed HTML.
 *
 * A block opts into client-side execution by carrying the `pyodide` marker in
 * its `class` attribute — typically a SyntaxHighlight block:
 *
 *     <syntaxhighlight lang="python" class="pyodide" copy>…</syntaxhighlight>
 *
 * which SyntaxHighlight renders as
 * `<div class="pyodide mw-highlight mw-highlight-lang-python …">`.
 * The marker must be an exact whitespace-delimited token, so a class such as
 * `pyodide-something` does not opt in.
 *
 * @license GPL-2.0-or-later
 */
final class PyodideBlockDetector {

	/** The marker class that opts a code block into client-side execution. */
	public const CLASS_NAME = 'pyodide';

	/**
	 * Whether the parsed HTML contains at least one `pyodide` code block.
	 */
	public static function containsPyodideBlock( string $html ): bool {
		if ( $html === '' || stripos( $html, self::CLASS_NAME ) === false ) {
			// Fast guard: most pages never mention the marker at all.
			return false;
		}

		if ( preg_match_all( '/(?<![\w-])class\s*=\s*(["\'])(.*?)\1/si', $html, $matches ) === 0 ) {
			return false;
		}

		foreach ( $matches[2] as $classAttribute ) {
			$classes = preg_split( '/\s+/', trim( $classAttribute ), -1, PREG_SPLIT_NO_EMPTY );
			if ( in_array( self::CLASS_NAME, $classes, true ) ) {
				return true;
			}
		}
		return false;
	}
}
