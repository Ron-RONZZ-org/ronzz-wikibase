<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\SourceLink;

/**
 * SourceLink — the footnote → Source: page anchor wrapper (2026-09 UX
 * batch). The citeproc HTML is sanitized before this runs, so the wrapper
 * cannot nest anchors.
 *
 * @license GPL-2.0-or-later
 */
final class SourceLinkTest extends TestCase {

	public function testWrapsWithTheSourceLinkClassAndUrl(): void {
		$this->assertSame(
			'<a class="wikibasecitation-source-link" href="/wiki/Source:The_Hobbit">'
				. 'Tolkien, J. R. R. (1937). <i>The Hobbit</i>.</a>',
			SourceLink::wrap(
				'Tolkien, J. R. R. (1937). <i>The Hobbit</i>.',
				'/wiki/Source:The_Hobbit'
			)
		);
	}

	public function testNoUrlReturnsTheCitationUnchanged(): void {
		$citation = 'Lovelace, A. (1843). <i>Notes by the Translator</i>.';
		$this->assertSame( $citation, SourceLink::wrap( $citation, null ) );
		$this->assertSame( $citation, SourceLink::wrap( $citation, '' ) );
		$this->assertSame( $citation, SourceLink::wrap( $citation, '   ' ) );
	}

	public function testEmptyHtmlReturnsEmpty(): void {
		$this->assertSame( '', SourceLink::wrap( '', '/wiki/Source:X' ) );
	}

	public function testUrlIsAttributeEscaped(): void {
		$this->assertSame(
			'<a class="wikibasecitation-source-link" href="/wiki/Source:A&amp;B?x=1&quot;y">c</a>',
			SourceLink::wrap( 'c', '/wiki/Source:A&B?x=1"y' )
		);
	}
}
