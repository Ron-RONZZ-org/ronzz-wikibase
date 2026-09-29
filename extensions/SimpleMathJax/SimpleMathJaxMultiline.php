<?php

/**
 * Multiline display-math normalization for the vendored SimpleMathJax
 * extension.
 *
 * MathJax 3 does NOT break a line at a top-level `\\`: at the top level of a
 * math expression the `\\` control sequence produces an empty `<mspace>`
 * (a space), so `$$a \\ b$$` renders on ONE line. Multiline display math
 * must use an environment (`gather`/`aligned`/`array`). Authors naturally
 * write the top-level `\\` form (standard LaTeX), so this pass wraps a
 * display span whose content carries a top-level `\\` in
 * `\begin{gathered}…\end{gathered}` — the centered multiline environment —
 * before MathJax sees it.
 *
 * A `\\` is "top-level" when it is neither inside a `\begin{env}…\end{env}`
 * environment nor inside a braced group; those already break lines
 * themselves (`cases`, `aligned`, …) and are left untouched. The delimiter
 * search is the quote guard's (`SimpleMathJaxQuotes::findClose`), so braced
 * groups, control sequences and the blank-line stop behave identically.
 *
 * The pass ALSO strips per-line leading blanks from every display span: a
 * wikitext line that begins with a space is MediaWiki's preformatted-text
 * marker (`<pre>`), which splits the `$$…$$` span across block elements so
 * MathJax never sees a balanced pair (the `Sandbox:Temp` report — a
 * continuation line indented under a `\\`). TeX ignores leading whitespace
 * in math, so removing it is safe.
 *
 * Pure PHP — no MediaWiki dependency; unit-testable standalone.
 *
 * @license GPL-2.0-or-later
 */
class SimpleMathJaxMultiline {

	/**
	 * Wrap every display-math span whose content has a top-level `\\` in
	 * `\begin{gathered}…\end{gathered}`.
	 *
	 * @param string $text wikitext/parser text to scan
	 * @param array[] $displayDelims list of [open, close] display pairs
	 * @return string text with multiline display spans wrapped
	 */
	public static function wrapMultilineDisplayMath( string $text, array $displayDelims ): string {
		if ( $text === '' || $displayDelims === [] ) {
			return $text;
		}

		$starts = [];
		foreach ( $displayDelims as [ $open, $close ] ) {
			$starts[$open] = $close;
		}
		uksort( $starts, static function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		} );
		$openLengths = [];
		foreach ( array_keys( $starts ) as $open ) {
			$openLengths[$open] = strlen( $open );
		}

		$len = strlen( $text );
		$out = '';
		$i = 0;
		while ( $i < $len ) {
			$matched = false;
			foreach ( $starts as $open => $close ) {
				$oL = $openLengths[$open];
				if ( substr( $text, $i, $oL ) !== $open ) {
					continue;
				}
				$end = SimpleMathJaxQuotes::findClose( $text, $i + $oL, $close );
				$out .= $open;
				if ( $end !== -1 ) {
					$content = substr( $text, $i + $oL, $end - $i - $oL - strlen( $close ) );
					$content = self::stripLineIndent( $content );
					$out .= self::hasTopLevelLineBreak( $content )
						? '\begin{gathered}' . $content . '\end{gathered}'
						: $content;
					$out .= $close;
					$i = $end;
				} else {
					// Unbalanced opener: MathJax ignores it — keep scanning.
					$i += $oL;
				}
				$matched = true;
				break;
			}
			if ( $matched ) {
				continue;
			}
			$out .= $text[$i];
			$i++;
		}

		return $out;
	}

	/**
	 * Remove per-line leading blanks from display content.
	 *
	 * A line that begins with a space is MediaWiki's preformatted-text
	 * marker; without this the parser splits the `$$…$$` span across a `<p>`
	 * and a `<pre>`, and MathJax never finds the closing delimiter. TeX
	 * ignores leading whitespace in math, so stripping it is safe.
	 */
	private static function stripLineIndent( string $content ): string {
		return preg_replace( '/^[ \t]+/m', '', $content ) ?? $content;
	}

	/**
	 * Whether the display content carries a `\\` outside every environment
	 * and braced group.
	 */
	private static function hasTopLevelLineBreak( string $content ): bool {
		$len = strlen( $content );
		$envDepth = 0;
		$braceDepth = 0;
		$i = 0;
		while ( $i < $len ) {
			$ch = $content[$i];
			if ( $ch === '\\' ) {
				if ( substr( $content, $i, 7 ) === '\begin{' ) {
					$envDepth++;
					$i += 7;
					continue;
				}
				if ( substr( $content, $i, 5 ) === '\end{' ) {
					if ( $envDepth > 0 ) {
						$envDepth--;
					}
					$i += 5;
					continue;
				}
				if ( $i + 1 < $len && $content[$i + 1] === '\\' ) {
					if ( $envDepth === 0 && $braceDepth === 0 ) {
						return true;
					}
					$i += 2;
					continue;
				}
				$i += 2; // control sequence / escape: backslash + one char
				continue;
			}
			if ( $ch === '{' ) {
				$braceDepth++;
			} elseif ( $ch === '}' && $braceDepth > 0 ) {
				$braceDepth--;
			}
			$i++;
		}
		return false;
	}
}
