<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Merges duplicate footnotes in Cite's rendered references list.
 *
 * Stock Cite only merges *name*-attributed refs; every unnamed `<ref>` gets
 * its own footnote (`ReferenceStack::pushRef`). For pages citing semantic
 * entities this renders the same source repeatedly —
 * `<ref>{{#cite:Q985}}</ref>` × 5 → five identical footnotes. This pass
 * post-processes Cite's output (from `Hooks::onParserAfterTidy`, which runs
 * after Cite's `ParserAfterParse` auto-append) so the reader sees ONE
 * footnote per distinct source:
 *
 *  - footnotes whose reference text is identical collapse into the first
 *    occurrence;
 *  - each merged footnote's in-text superscript is re-pointed at the
 *    surviving footnote (its visible number relabelled — the same UX Cite
 *    gives to a reused named ref);
 *  - the surviving footnote's backlink span gains one ↑ link per merged
 *    usage, so every anchor stays valid in both directions.
 *
 * Only the **default group's numeric-id footnotes** (`cite_note-N`) are
 * considered: named refs (`cite_note-name-N`) and `group=` lists carry
 * non-numeric note ids and are left untouched.
 *
 * @license GPL-2.0-or-later
 */
class ReferencesMerger {

	/**
	 * Merges duplicate footnotes in a rendered page.
	 *
	 * @param string $html the final parser output (after tidy)
	 * @return string the page with duplicate default-group footnotes merged
	 */
	public static function mergeDuplicateFootnotes( string $html ): string {
		$remap = [];
		$html = (string)preg_replace_callback(
			// Cite's default group. The non-greedy body stops at the first
			// `</ol>`: a reference text that itself contains a list splits
			// the block there and the remainder is left unprocessed — a
			// safe degradation, never corruption.
			'#<ol class="references">(.*?)</ol>#s',
			static function ( array $m ) use ( &$remap ): string {
				[ $newInner, $blockRemap ] = self::mergeBlock( $m[1] );
				$remap += $blockRemap;
				return '<ol class="references">' . $newInner . '</ol>';
			},
			$html
		);
		foreach ( $remap as $from => $to ) {
			// Array keys numeric-cast: restore the string form for the regex.
			$html = self::retargetSuperscript( $html, (string)$from, (string)$to );
		}
		return $html;
	}

	/**
	 * Merges the `<li>` footnotes of one references block.
	 *
	 * @return array{0:string,1:array<string,string>} [new list inner HTML,
	 *  dropped note id => surviving note id]
	 */
	private static function mergeBlock( string $inner ): array {
		// Numeric note ids only — named refs and group lists are skipped.
		if ( !preg_match_all(
			'~<li id="cite(?:_|&#95;)note-(\d+)"([^>]*)>(.*?)</li>~s',
			$inner,
			$matches,
			PREG_SET_ORDER
		) ) {
			return [ $inner, [] ];
		}

		$groups = [];
		$keptByIndex = [];
		foreach ( $matches as $i => $row ) {
			$text = self::referenceText( $row[3] );
			if ( $text === null ) {
				// No reference-text span (an empty or malformed ref): never
				// merge — the li passes through in its original position.
				$keptByIndex[$i] = $row[0];
				continue;
			}
			$groups[$text][] = [ 'index' => $i, 'noteId' => $row[1], 'liHtml' => $row[0] ];
		}

		$remap = [];
		foreach ( $groups as $entries ) {
			$first = array_shift( $entries );
			$extra = [];
			foreach ( $entries as $dup ) {
				$remap[$dup['noteId']] = $first['noteId'];
				$extra[] = $dup['noteId'];
			}
			$keptByIndex[$first['index']] = $extra === []
				? $first['liHtml']
				: self::withExtraBacklinks( $first['liHtml'], $extra );
		}

		ksort( $keptByIndex );
		return [ implode( "\n", $keptByIndex ), $remap ];
	}

	/**
	 * The reference text (inner HTML of the `reference-text` span),
	 * whitespace-normalized for grouping; null when the span is absent.
	 */
	private static function referenceText( string $liContent ): ?string {
		if ( !preg_match( '#<span class="reference-text">(.*)</span>#s', $liContent, $m ) ) {
			return null;
		}
		// Collapse whitespace runs so byte-identical citations that differ
		// only in line breaks still group together.
		return preg_replace( '/\s+/u', ' ', $m[1] );
	}

	/**
	 * Appends one ↑ backlink per merged usage inside the surviving
	 * footnote's `mw-cite-backlink` span.
	 */
	private static function withExtraBacklinks( string $liHtml, array $extraIds ): string {
		$extra = '';
		foreach ( $extraIds as $id ) {
			$extra .= "\n<a href=\"#cite_ref-{$id}\">↑</a>";
		}
		return (string)preg_replace(
			'#(<span class="mw-cite-backlink">.*?)</span>#s',
			'$1' . $extra . '</span>',
			$liHtml,
			1
		);
	}

	/**
	 * Re-points one in-text superscript at the surviving footnote: the
	 * `#cite_note-M` href becomes `#cite_note-K` and the visible number is
	 * relabelled `[K]` (Cite's named-ref UX). The sup's own `cite_ref-M` id
	 * is kept — it is the backlink target. A malformed sup gets a loose
	 * href-only fix as a fallback, so no anchor dangles.
	 */
	private static function retargetSuperscript( string $html, string $from, string $to ): string {
		$pattern = '~(<sup id="cite(?:_|&#95;)ref-)' . $from
			. '("[^>]*><a href="#cite(?:_|&#95;)note-)' . $from
			. '("><span class="cite-bracket">&#91;</span>)\d+'
			. '(<span class="cite-bracket">&#93;</span></a></sup>)~';
		$rewritten = preg_replace(
			$pattern,
			'${1}' . $from . '${2}' . $to . '${3}' . $to . '${4}',
			$html,
			1
		);
		if ( $rewritten !== null && $rewritten !== $html ) {
			return $rewritten;
		}
		// Loose fallback: at least fix the href (the label may stay
		// stale, but the anchor no longer dangles).
		return (string)preg_replace(
			'~(<a href="#cite(?:_|&#95;)note-)' . $from . '(">)~',
			'${1}' . $to . '${2}',
			$html,
			1
		);
	}
}
