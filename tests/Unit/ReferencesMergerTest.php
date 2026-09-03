<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\ReferencesMerger;

/**
 * Duplicate-footnote merge for Cite's rendered references list: unnamed
 * `<ref>{{#cite:Q…}}</ref>` repeated on a page must render ONE footnote
 * per distinct source (N backlinks), not one footnote per use.
 *
 * @covers \WikibaseCitation\ReferencesMerger
 * @license GPL-2.0-or-later
 */
class ReferencesMergerTest extends TestCase {

	/** The stock Cite REL1_46 superscript for an unnamed ref (live markup). */
	private function sup( int $id ): string {
		return '<sup id="cite&#95;ref-' . $id . '" class="reference">'
			. '<a href="#cite_note-' . $id . '"><span class="cite-bracket">&#91;</span>'
			. $id . '<span class="cite-bracket">&#93;</span></a></sup>';
	}

	/** The rendered label of a superscript that displays the number $n. */
	private function label( int $n ): string {
		return '<span class="cite-bracket">&#91;</span>' . $n
			. '<span class="cite-bracket">&#93;</span>';
	}

	/** The stock Cite REL1_46 footnote <li> for an unnamed ref. */
	private function li( int $id, string $text ): string {
		return '<li id="cite&#95;note-' . $id . '">'
			. '<span class="mw-cite-backlink"><a href="#cite_ref-' . $id . '">↑</a></span> '
			. '<span class="reference-text">' . $text . '</span></li>';
	}

	private function page( array $sups, array $lis ): string {
		$head = '<p>' . implode( ' ', $sups ) . '</p>';
		$list = '<div class="mw-references-wrap"><ol class="references">'
			. implode( "\n", $lis ) . '</ol></div>';
		return '<div class="mw-parser-output">' . $head . $list . '</div>';
	}

	public function testMergesDuplicateFootnotesLikeTheManskePage(): void {
		$bookA = 'Manske, H. M. (n.d.). <i>Magnus Manske (website)</i>. Magnus Manske (website).';
		$thesisB = 'Manske, H. M. (2006). GENtle, a free multi-purpose molecular biology tool.';
		// 6 refs: 1=A, 2=B, 3..6=A — the exact duplicate-citation scenario.
		$html = $this->page(
			[ $this->sup( 1 ), $this->sup( 2 ), $this->sup( 3 ), $this->sup( 4 ), $this->sup( 5 ), $this->sup( 6 ) ],
			[ $this->li( 1, $bookA ), $this->li( 2, $thesisB ), $this->li( 3, $bookA ),
				$this->li( 4, $bookA ), $this->li( 5, $bookA ), $this->li( 6, $bookA ) ]
		);

		$out = ReferencesMerger::mergeDuplicateFootnotes( $html );

		// One footnote per distinct source.
		$this->assertSame( 2, substr_count( $out, '<li id="cite&#95;note-' ) );
		$this->assertStringContainsString( '<li id="cite&#95;note-1"', $out );
		$this->assertStringContainsString( '<li id="cite&#95;note-2"', $out );

		// The surviving footnote carries 5 backlinks (its own + the 4 merged).
		$note1 = $this->extract( $out, 'cite&#95;note-1' );
		$this->assertSame( 5, substr_count( $note1, 'href="#cite_ref-' ) );

		// The merged sups re-point at note-1 with label [1]…
		$this->assertSame( 5, substr_count( $out, 'href="#cite_note-1"' ) );
		$this->assertSame( 5, substr_count( $out, '<span class="cite-bracket">&#91;</span>1<span class="cite-bracket">&#93;</span>' ) );
		$this->assertSame( 1, substr_count( $out, 'href="#cite_note-2"' ) );
		// …their own ids survive (they are the backlink targets)…
		$this->assertStringContainsString( 'id="cite&#95;ref-3"', $out );
		$this->assertStringContainsString( 'id="cite&#95;ref-6"', $out );
		// …and no dropped-note anchor dangles anywhere.
		foreach ( [ 3, 4, 5, 6 ] as $dropped ) {
			$this->assertStringNotContainsString( '#cite_note-' . $dropped, $out );
		}
	}

	public function testNoDuplicatesLeavesThePageUntouched(): void {
		$html = $this->page(
			[ $this->sup( 1 ), $this->sup( 2 ) ],
			[ $this->li( 1, 'First source.' ), $this->li( 2, 'Second source.' ) ]
		);
		$this->assertSame( $html, ReferencesMerger::mergeDuplicateFootnotes( $html ) );
	}

	public function testWhitespaceDifferencesStillMerge(): void {
		$html = $this->page(
			[ $this->sup( 1 ), $this->sup( 2 ) ],
			[ $this->li( 1, "Author. (2026). <i>Title</i>.\n  Long line." ),
				$this->li( 2, 'Author. (2026). <i>Title</i>. Long line.' ) ]
		);
		$out = ReferencesMerger::mergeDuplicateFootnotes( $html );
		$this->assertSame( 1, substr_count( $out, '<li id="cite&#95;note-' ) );
		$this->assertSame( 2, substr_count( $out, 'href="#cite_ref-' ) );
		$this->assertSame( 2, substr_count( $out, 'href="#cite_note-1"' ) );
	}

	public function testNamedRefsAndGroupListsAreLeftAlone(): void {
		$named = '<li id="cite&#95;note-book-1"><span class="mw-cite-backlink"><a href="#cite_ref-book-1">↑</a></span> '
			. '<span class="reference-text">Named book ref.</span></li>';
		$group = '<li id="cite&#95;note-g1-2"><span class="mw-cite-backlink"><a href="#cite_ref-g1-2">↑</a></span> '
			. '<span class="reference-text">Grouped ref.</span></li>';
		$html = '<ol class="references">' . $named . "\n" . $group . '</ol>';
		$this->assertSame( $html, ReferencesMerger::mergeDuplicateFootnotes( $html ) );
	}

	public function testEmptyRefWithoutReferenceTextPassesThrough(): void {
		$empty = '<li id="cite&#95;note-1"><span class="mw-cite-backlink"><a href="#cite_ref-1">↑</a></span> </li>';
		$html = '<ol class="references">' . $empty . "\n" . $this->li( 2, 'A real source.' ) . '</ol>';
		$out = ReferencesMerger::mergeDuplicateFootnotes( $html );
		$this->assertStringContainsString( $empty, $out );
		$this->assertSame( 2, substr_count( $out, '<li id="cite&#95;note-' ) );
	}

	public function testNoReferencesListIsUnchanged(): void {
		$html = '<p>No refs here, only text.</p>';
		$this->assertSame( $html, ReferencesMerger::mergeDuplicateFootnotes( $html ) );
	}

	/**
	 * The UFR-SciFA regression: 14 refs over three sources (3×A, 7×B, 4×C —
	 * A at 1/2/13, B at 3–8/14, C at 9–12). The survivors keep their
	 * ORIGINAL ids (cite_note-1/-3/-9) but the browser's positional <ol>
	 * markers show them as 1./2./3. — so every superscript must be relabelled
	 * to the survivor's FINAL list position, or the body reads [1][3][9]
	 * while the list shows 1. 2. 3. (clicking [3] landed on the entry shown
	 * as "2."; the list "had no 9").
	 */
	public function testRenumbersSurvivorsToConsecutiveListPositions(): void {
		$a = 'A. (2026). <i>Source A</i>.';
		$b = 'B. (2026). <i>Source B</i>.';
		$c = 'C. (2026). <i>Source C</i>.';
		$sups = [];
		$lis = [];
		foreach ( [ 1 => $a, 2 => $a, 3 => $b, 4 => $b, 5 => $b, 6 => $b,
			7 => $b, 8 => $b, 9 => $c, 10 => $c, 11 => $c, 12 => $c,
			13 => $a, 14 => $b ] as $id => $text ) {
			$sups[] = $this->sup( $id );
			$lis[] = $this->li( $id, $text );
		}
		$html = $this->page( $sups, $lis );

		$out = ReferencesMerger::mergeDuplicateFootnotes( $html );

		// The survivors keep their original li ids — 1, 3, 9 — one per source.
		$this->assertSame( 3, substr_count( $out, '<li id="cite&#95;note-' ) );
		foreach ( [ 1, 3, 9 ] as $kept ) {
			$this->assertStringContainsString( '<li id="cite&#95;note-' . $kept . '"', $out );
		}
		// Every dropped-note anchor is gone (no dangling targets).
		foreach ( [ 2, 4, 5, 6, 7, 8, 10, 11, 12, 13, 14 ] as $dropped ) {
			$this->assertStringNotContainsString( '#cite_note-' . $dropped, $out );
		}
		// Every superscript label now equals the FINAL list position of the
		// footnote it points at: source A → 1, B → 2, C → 3. The body no
		// longer shows any survivor's original number ([3]/[9]) in isolation.
		$this->assertSame( 3, substr_count( $out, $this->label( 1 ) ) );  // sups 1, 2, 13
		$this->assertSame( 7, substr_count( $out, $this->label( 2 ) ) );  // sups 3–8, 14
		$this->assertSame( 4, substr_count( $out, $this->label( 3 ) ) );  // sups 9–12
		// No superscript still shows an original note number that is not its
		// final position (would mismatch the list markers).
		foreach ( [ 9, 13, 14 ] as $stale ) {
			$this->assertStringNotContainsString( $this->label( $stale ), $out );
		}
		// The surviving footnotes carry the right backlink sets.
		$this->assertSame( 3, substr_count( $this->extract( $out, 'cite&#95;note-1' ), 'href="#cite_ref-' ) );
		$this->assertSame( 7, substr_count( $this->extract( $out, 'cite&#95;note-3' ), 'href="#cite_ref-' ) );
		$this->assertSame( 4, substr_count( $this->extract( $out, 'cite&#95;note-9' ), 'href="#cite_ref-' ) );
	}

	/**
	 * The renumbering is per references block but the superscripts live
	 * outside it: a block whose survivors end up non-consecutive (a unique
	 * footnote after collapsed duplicates) must relabel its own superscript
	 * to its shifted position too — not only the merged duplicates.
	 */
	public function testUniqueFootnoteAfterCollapsedDuplicatesIsRenumbered(): void {
		// 1=A, 2=A(dup), 3=B(unique, comes after a collapse) — the list
		// renders 1. A, 2. B; B's original [3] must become [2].
		$html = $this->page(
			[ $this->sup( 1 ), $this->sup( 2 ), $this->sup( 3 ) ],
			[ $this->li( 1, 'A. (2026). <i>Same</i>.' ), $this->li( 2, 'A. (2026). <i>Same</i>.' ),
				$this->li( 3, 'B. (2026). <i>Other</i>.' ) ]
		);
		$out = ReferencesMerger::mergeDuplicateFootnotes( $html );
		$this->assertSame( 2, substr_count( $out, '<li id="cite&#95;note-' ) );
		$this->assertSame( 2, substr_count( $out, $this->label( 1 ) ) );  // sups 1 + 2
		$this->assertSame( 1, substr_count( $out, $this->label( 2 ) ) );  // sup 3 relabelled
		$this->assertStringNotContainsString( $this->label( 3 ), $out );
	}

	/**
	 * A named ref interleaved with numeric footnotes passes through and
	 * consumes a list position: the numeric survivor after it is numbered
	 * 2 (not 1), and the named ref's own li is not dropped.
	 */
	public function testNamedRefBetweenNumericFootnotesPassesThroughAndCounts(): void {
		$named = '<li id="cite&#95;note-book-1"><span class="mw-cite-backlink"><a href="#cite_ref-book-1">↑</a></span> '
			. '<span class="reference-text">Named book ref.</span></li>';
		$html = $this->page(
			[ $this->sup( 1 ), $this->sup( 2 ) ],
			[ $this->li( 1, 'Same text.' ), $named, $this->li( 2, 'Same text.' ) ]
		);
		$out = ReferencesMerger::mergeDuplicateFootnotes( $html );
		// note-2 collapses into note-1; the named li survives between them.
		$this->assertSame( 2, substr_count( $out, '<li id="cite&#95;note-' ) );
		$this->assertStringContainsString( 'id="cite&#95;note-book-1"', $out );
		$this->assertStringContainsString( 'id="cite&#95;note-1"', $out );
		$this->assertStringNotContainsString( '#cite_note-2', $out );
		// Survivor note-1 renders at list position 1 (named li is position 2).
		$this->assertSame( 2, substr_count( $out, $this->label( 1 ) ) );
	}

	public function testTwoBlocksMergeIndependentlyAndRemapGlobally(): void {
		$html = $this->page( [ $this->sup( 1 ), $this->sup( 2 ) ], [ $this->li( 1, 'A' ), $this->li( 2, 'A' ) ] )
			. $this->page( [ $this->sup( 3 ), $this->sup( 4 ) ], [ $this->li( 3, 'B' ), $this->li( 4, 'B' ) ] );
		$out = ReferencesMerger::mergeDuplicateFootnotes( $html );
		$this->assertSame( 2, substr_count( $out, '<li id="cite&#95;note-' ) );
		// Both blocks' duplicates re-point at their first footnote.
		$this->assertStringNotContainsString( '#cite_note-2', $out );
		$this->assertStringNotContainsString( '#cite_note-4', $out );
		$this->assertStringContainsString( 'id="cite&#95;ref-2"', $out );
		$this->assertStringContainsString( 'id="cite&#95;ref-4"', $out );
	}

	public function testRawIdFormIsHandledToo(): void {
		// Some renderers emit raw underscores in ids (no &#95; encoding).
		$html = '<p><sup id="cite_ref-1" class="reference"><a href="#cite_note-1">[1]</a></sup>'
			. '<sup id="cite_ref-2" class="reference"><a href="#cite_note-2">[2]</a></sup></p>'
			. '<ol class="references">'
			. '<li id="cite_note-1"><span class="mw-cite-backlink"><a href="#cite_ref-1">↑</a></span> '
			. '<span class="reference-text">Same text.</span></li>'
			. '<li id="cite_note-2"><span class="mw-cite-backlink"><a href="#cite_ref-2">↑</a></span> '
			. '<span class="reference-text">Same text.</span></li>'
			. '</ol>';
		$out = ReferencesMerger::mergeDuplicateFootnotes( $html );
		$this->assertSame( 1, substr_count( $out, '<li id="cite_note-' ) );
		// The sup pattern with cite-bracket spans does not match the plain
		// [1] label — the loose href-only fallback still fixes the anchor.
		$this->assertStringContainsString( 'href="#cite_note-1"', $out );
		$this->assertStringNotContainsString( 'href="#cite_note-2"', $out );
		$this->assertStringContainsString( 'id="cite_ref-2"', $out );
	}

	private function extract( string $html, string $needle ): string {
		$pos = strpos( $html, $needle );
		$this->assertNotFalse( $pos, "expected $needle in output" );
		// Return from the start of the <li> containing the needle to its end.
		$liStart = strrpos( substr( $html, 0, $pos ), '<li id=' );
		$liEnd = strpos( $html, '</li>', $pos );
		return substr( $html, $liStart, $liEnd - $liStart + 5 );
	}
}
