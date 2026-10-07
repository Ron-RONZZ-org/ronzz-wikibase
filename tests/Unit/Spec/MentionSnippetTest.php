<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\MentionSnippet;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\MentionSnippet
 * @license GPL-2.0-or-later
 */
class MentionSnippetTest extends TestCase {

	public function testMainNamespaceProperNameIsKept(): void {
		// Every word capitalized → proper name → unchanged.
		$this->assertSame( '[[Main Page]]', MentionSnippet::fromPrefixedTitle( 'Main Page', true ) );
		$this->assertSame( '[[Albert Einstein]]', MentionSnippet::fromPrefixedTitle( 'Albert Einstein', true ) );
	}

	public function testMainNamespaceSingleCapitalizedWordIsKept(): void {
		// A single capitalized word counts as a proper name.
		$this->assertSame( '[[Nextcloud]]', MentionSnippet::fromPrefixedTitle( 'Nextcloud', true ) );
		$this->assertSame( '[[Orthonormality]]', MentionSnippet::fromPrefixedTitle( 'Orthonormality', true ) );
	}

	public function testMainNamespaceSentenceCaseIsLowercased(): void {
		$this->assertSame( '[[classical mechanics]]', MentionSnippet::fromPrefixedTitle( 'Classical mechanics', true ) );
		$this->assertSame( '[[screw theory]]', MentionSnippet::fromPrefixedTitle( 'Screw theory', true ) );
	}

	public function testMainNamespaceAlreadyLowercaseIsUnchanged(): void {
		$this->assertSame( '[[vector space]]', MentionSnippet::fromPrefixedTitle( 'vector space', true ) );
	}

	public function testMainNamespaceLowercasesAccentedFirstLetter(): void {
		// UTF-8 aware: "É" → "é".
		$this->assertSame( '[[école normale]]', MentionSnippet::fromPrefixedTitle( 'École normale', true ) );
	}

	public function testNonMainNamespaceIsNeverLowercased(): void {
		// "Help:Contributing" is not a proper name, but non-Main namespaces
		// keep the exact page title.
		$this->assertSame( '[[Help:Contributing]]', MentionSnippet::fromPrefixedTitle( 'Help:Contributing', false ) );
		$this->assertSame( '[[Help:Some page]]', MentionSnippet::fromPrefixedTitle( 'Help:Some page', false ) );
	}

	public function testEmptyTitleYieldsEmptySnippet(): void {
		$this->assertSame( '', MentionSnippet::fromPrefixedTitle( '', true ) );
		$this->assertSame( '', MentionSnippet::fromPrefixedTitle( '   ', false ) );
	}

}
