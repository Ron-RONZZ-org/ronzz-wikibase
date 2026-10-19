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

	public function testMainNamespaceProperNameCategoryKeepsTheCapital(): void {
		// Category:Proper name is the explicit opt-out.
		$this->assertSame( '[[Main Page]]', MentionSnippet::fromPrefixedTitle( 'Main Page', true, true ) );
		$this->assertSame( '[[Albert Einstein]]', MentionSnippet::fromPrefixedTitle( 'Albert Einstein', true, true ) );
		$this->assertSame( '[[Nextcloud]]', MentionSnippet::fromPrefixedTitle( 'Nextcloud', true, true ) );
	}

	public function testMainNamespaceWithoutTheCategoryIsSentenceCased(): void {
		// The old "every word capitalized" heuristic is gone: a capitalised
		// single word is NOT a proper name unless the page opts in.
		$this->assertSame( '[[permutation]]', MentionSnippet::fromPrefixedTitle( 'Permutation', true, false ) );
		$this->assertSame( '[[nextcloud]]', MentionSnippet::fromPrefixedTitle( 'Nextcloud', true, false ) );
		$this->assertSame( '[[albert Einstein]]', MentionSnippet::fromPrefixedTitle( 'Albert Einstein', true, false ) );
		$this->assertSame( '[[main Page]]', MentionSnippet::fromPrefixedTitle( 'Main Page', true, false ) );
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
		// keep the exact page title; the category does not change that.
		$this->assertSame( '[[Help:Contributing]]', MentionSnippet::fromPrefixedTitle( 'Help:Contributing', false ) );
		$this->assertSame( '[[Help:Some page]]', MentionSnippet::fromPrefixedTitle( 'Help:Some page', false, true ) );
	}

	public function testEmptyTitleYieldsEmptySnippet(): void {
		$this->assertSame( '', MentionSnippet::fromPrefixedTitle( '', true ) );
		$this->assertSame( '', MentionSnippet::fromPrefixedTitle( '   ', false ) );
	}

	public function testProperNameCategoryNameIsTheContract(): void {
		$this->assertSame( 'Proper name', MentionSnippet::PROPER_NAME_CATEGORY );
	}

}
