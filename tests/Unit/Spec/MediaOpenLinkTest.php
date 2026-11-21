<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\MediaOpenLink;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure "Open in a new tab" media fragment
 * (Spec\MediaOpenLink).
 *
 * @covers \EmbeddableContent\Spec\MediaOpenLink
 * @license GPL-2.0-or-later
 */
final class MediaOpenLinkTest extends TestCase {

	public function testBuildsTheSharedLinkFragment(): void {
		$html = MediaOpenLink::html( 'https://example.org/images/a/ab/Foo.png', 'Open in a new tab' );

		$this->assertStringContainsString( 'class="wb-media-open noprint"', $html );
		$this->assertStringContainsString( 'href="https://example.org/images/a/ab/Foo.png"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( 'class="wb-media-open-link"', $html );
		$this->assertStringContainsString( '>Open in a new tab</a>', $html );
	}

	public function testEscapesTheUrlAndLabel(): void {
		$html = MediaOpenLink::html(
			'https://example.org/a?x=1&y="2"',
			'Open <b>here</b> & now'
		);

		$this->assertStringContainsString( '&amp;', $html );
		$this->assertStringContainsString( '&quot;', $html );
		$this->assertStringContainsString( '&lt;b&gt;here&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

}
