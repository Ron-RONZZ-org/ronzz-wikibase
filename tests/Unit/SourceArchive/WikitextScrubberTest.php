<?php

declare( strict_types = 1 );

namespace Tests\Unit\SourceArchive;

use EmbeddableContent\SourceArchive\WikitextScrubber;
use PHPUnit\Framework\TestCase;

/**
 * The archived-text wikitext scrub (pure, no MediaWiki).
 *
 * Rules under test: {{…}} removed, [[…]] unwrapped, media links become
 * "([url Media content: view on original URL]) Caption", <ref> kept,
 * behaviour switches removed.
 *
 * @license GPL-2.0-or-later
 */
class WikitextScrubberTest extends TestCase {

	private const URL = 'https://example.com/page';
	private const NOTE = 'Media content: view on original URL';

	private WikitextScrubber $scrubber;

	protected function setUp(): void {
		$this->scrubber = new WikitextScrubber();
	}

	public function testRemovesTemplatesAndKeepsSurroundingText(): void {
		$this->assertSame(
			"before after\n",
			$this->scrubber->scrub( 'before {{cite|X}} after', self::URL, self::NOTE )
		);
	}

	public function testRemovesNestedTemplates(): void {
		$this->assertSame(
			"A B\n",
			$this->scrubber->scrub( 'A {{outer|{{inner|x}}}} B', self::URL, self::NOTE )
		);
	}

	public function testUnwrapsPlainLinkToTarget(): void {
		$this->assertSame(
			"see target\n",
			$this->scrubber->scrub( 'see [[target]]', self::URL, self::NOTE )
		);
	}

	public function testUnwrapsPipedLinkToLabel(): void {
		$this->assertSame(
			"see label\n",
			$this->scrubber->scrub( 'see [[Target|label]]', self::URL, self::NOTE )
		);
	}

	public function testFileLinkBecomesMediaNoteAndCaption(): void {
		$this->assertSame(
			"([https://example.com/page " . self::NOTE . "]) A caption\n",
			$this->scrubber->scrub( '[[File:Photo.jpg|thumb|A caption]]', self::URL, self::NOTE )
		);
	}

	public function testFileLinkWithoutCaptionKeepsOnlyTheNote(): void {
		$this->assertSame(
			"([https://example.com/page " . self::NOTE . "])\n",
			$this->scrubber->scrub( '[[File:Photo.jpg|thumb]]', self::URL, self::NOTE )
		);
	}

	public function testImageUrlTargetIsTreatedAsMedia(): void {
		$this->assertSame(
			"([https://example.com/page " . self::NOTE . "]) pic\n",
			$this->scrubber->scrub( '[[https://e.test/x.png|pic]]', self::URL, self::NOTE )
		);
	}

	public function testRefIsKept(): void {
		$input = 'claim <ref name="a">source</ref>';
		$this->assertSame(
			$input . "\n",
			$this->scrubber->scrub( $input, self::URL, self::NOTE )
		);
	}

	public function testBehaviourSwitchesAreRemoved(): void {
		$this->assertSame(
			"text\n",
			$this->scrubber->scrub( '__TOC__ text __NOINDEX__', self::URL, self::NOTE )
		);
	}

	public function testEmptyResultIsEmptyString(): void {
		$this->assertSame(
			'',
			$this->scrubber->scrub( '{{only}}', self::URL, self::NOTE )
		);
	}
}
