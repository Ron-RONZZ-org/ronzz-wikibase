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
 *    surviving footnote;
 *  - the surviving footnote's backlink span gains one ↑ link per merged
 *    usage, so every anchor stays valid in both directions;
 *  - the collapsed list is then RENUMBERED consecutively.
 *
 * The renumber pass exists because the `<ol class="references">` markers are
 * POSITIONAL — the browser numbers the surviving `<li>`s 1..K regardless of
 * their `cite_note-N` ids. The surviving entries are assigned their final
 * 1..K positions and every in-text superscript label is rewritten to the
 * position of the footnote it points at. Without it the body kept the
 * survivors' ORIGINAL note numbers (a list whose survivors were ids 1/3/9
 * displayed 1. 2. 3. while the superscripts read [1] [3] [9]): clicking [3]
 * highlighted the entry shown as "2.", and the list appeared to have no
 * entry for the higher numbers (the UFR-SciFA report). After the pass the
 * output numbers exactly like a page that had used Cite *named* refs from
 * the start (first-use order, consecutive).
 *
 * Only the **default group's numeric-id footnotes** (`cite_note-N`) are
 * merged: named refs (`cite_note-name-N`) and `group=` lists carry
 * non-numeric note ids and are left untouched — they pass through in place
 * and still consume a list position, which the renumbering accounts for.
 *
 * @license GPL-2.0-or-later
 */
class ReferencesMerger {

	/**
	 * Merges duplicate footnotes in a rendered page.
	 *
	 * @param string $html the final parser output (after tidy)
	 * @return string the page with duplicate default-group footnotes merged
	 *  and the surviving entries renumbered consecutively
	 */
	public static function mergeDuplicateFootnotes( string $html ): string {
		$remap = [];
		$renumber = [];
		$html = (string)preg_replace_callback(
			// Cite's default group. The non-greedy body stops at the first
			// `</ol>`: a reference text that itself contains a list splits
			// the block there and the remainder is left unprocessed — a
			// safe degradation, never corruption.
			'#<ol class="references">(.*?)</ol>#s',
			static function ( array $m ) use ( &$remap, &$renumber ): string {
				[ $newInner, $blockRemap, $blockRenumber ] = self::mergeBlock( $m[1] );
				$remap += $blockRemap;
				$renumber += $blockRenumber;
				return '<ol class="references">' . $newInner . '</ol>';
			},
			$html
		);
		foreach ( $remap as $from => $to ) {
			// Array keys numeric-cast: restore the string form for the regex.
			$html = self::retargetSuperscript( $html, (string)$from, (string)$to );
		}
		// The renumber pass runs after every href was re-pointed: each
		// superscript's visible number becomes the final position of the
		// footnote it points at (the positional markers of the surviving
		// `<li>`s after the collapse).
		$html = self::renumberSuperscripts( $html, $renumber );
		return $html;
	}

	/**
	 * Merges the `<li>` footnotes of one references block.
	 *
	 * Every `<li id="cite_note-…">` is enumerated — numeric, named and group
	 * ids alike — so that:
	 *  - duplicate NUMERIC footnotes (identical reference text) collapse
	 *    into their first occurrence (removed ids are remapped);
	 *  - everything else (unique numeric footnotes, named refs, group refs,
	 *    and the markup between entries) passes through in its original
	 *    position, byte-identical;
	 * and the surviving entries are numbered 1..K in list order — exactly
	 * the numbering the browser's `<ol>` markers will show — so the caller
	 * can rewrite the in-text superscript labels to match.
	 *
	 * @return array{0:string,1:array<string,string>,2:array<int,int>}
	 *  [new list inner HTML, dropped note id => surviving note id,
	 *  surviving numeric note id => final 1..K list position]
	 */
	private static function mergeBlock( string $inner ): array {
		// Numeric note ids only merge. No numeric footnote in the block:
		// nothing to do, return it byte-identical (named-only / group-only
		// reference lists keep stock behavior).
		if ( !preg_match_all(
			'~<li id="cite(?:_|&#95;)note-(\d+)"([^>]*)>(.*?)</li>~s',
			$inner,
			$numericMatches,
			PREG_SET_ORDER
		) ) {
			return [ $inner, [], [] ];
		}

		// Enumerate EVERY li in the block with its byte offsets, so the
		// rebuild below preserves the unmatched markup (whitespace,
		// comments) and the non-numeric footnotes verbatim in place.
		if ( !preg_match_all(
			'~<li id="cite(?:_|&#95;)note-([^"]*)"([^>]*)>.*?</li>~s',
			$inner,
			$allMatches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		) ) {
			return [ $inner, [], [] ];
		}

		$gapBefore = [];
		$keptHtml = array_fill( 0, count( $allMatches ), null );
		$groups = [];
		$cursor = 0;
		foreach ( $allMatches as $i => $m ) {
			$full = $m[0][0];
			$start = $m[0][1];
			$gapBefore[$i] = substr( $inner, $cursor, $start - $cursor );
			$cursor = $start + strlen( $full );
			$noteId = $m[1][0];
			if ( preg_match( '/^\d+$/', $noteId ) !== 1 ) {
				// Named ref / group list: never merged — passes through in
				// its original position (and consumes a list position).
				$keptHtml[$i] = $full;
				continue;
			}
			$text = self::referenceText( $full );
			if ( $text === null ) {
				// No reference-text span (an empty or malformed ref): never
				// merge — the li passes through in its original position.
				$keptHtml[$i] = $full;
				continue;
			}
			$groups[$text][] = [ 'row' => $i, 'noteId' => $noteId, 'html' => $full ];
		}

		$remap = [];
		foreach ( $groups as $entries ) {
			$first = array_shift( $entries );
			$extra = [];
			foreach ( $entries as $dup ) {
				$remap[$dup['noteId']] = $first['noteId'];
				$extra[] = $dup['noteId'];
				$keptHtml[$dup['row']] = null;
			}
			$keptHtml[$first['row']] = $extra === []
				? $first['html']
				: self::withExtraBacklinks( $first['html'], $extra );
		}

		// Renumber: the surviving lis render as 1..K in DOM order — assign
		// every surviving NUMERIC footnote its final list position (named /
		// group lis count towards the position but are never relabelled).
		$renumber = [];
		$position = 0;
		foreach ( $keptHtml as $i => $html ) {
			if ( $html === null ) {
				continue;
			}
			$position++;
			$noteId = $allMatches[$i][1][0];
			if ( preg_match( '/^\d+$/', $noteId ) === 1 ) {
				$renumber[$noteId] = $position;
			}
		}

		$out = '';
		foreach ( $allMatches as $i => $_ ) {
			$out .= $gapBefore[$i];
			if ( $keptHtml[$i] !== null ) {
				$out .= $keptHtml[$i];
			}
		}
		$out .= substr( $inner, $cursor );

		return [ $out, $remap, $renumber ];
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
	 * `#cite_note-M` href becomes `#cite_note-K`. The sup's own `cite_ref-M`
	 * id is kept — it is the backlink target. A malformed sup gets a loose
	 * href-only fix as a fallback, so no anchor dangles (the label is then
	 * corrected, when the markup allows it, by the renumber pass).
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

	/**
	 * Rewrites the visible number of every in-text superscript to the final
	 * 1..K position of the footnote it points at (after the duplicate
	 * `<li>`s were collapsed from the references block). Superscripts keep
	 * their own `cite_ref-N` id — only the label changes.
	 *
	 * @param array<int,int> $renumber numeric note id => final list position
	 */
	private static function renumberSuperscripts( string $html, array $renumber ): string {
		if ( $renumber === [] ) {
			return $html;
		}
		return (string)preg_replace_callback(
			'~(<sup id="cite(?:_|&#95;)ref-\d+"[^>]*><a href="#cite(?:_|&#95;)note-)(\d+)'
			. '("><span class="cite-bracket">&#91;</span>)\d+(<span class="cite-bracket">&#93;</span></a></sup>)~',
			static function ( array $m ) use ( $renumber ): string {
				$position = $renumber[$m[2]] ?? null;
				if ( $position === null ) {
					// Not a renumbered footnote (named ref / other group):
					// leave the label untouched.
					return $m[0];
				}
				return $m[1] . $m[2] . $m[3] . $position . $m[4];
			},
			$html
		);
	}
}
