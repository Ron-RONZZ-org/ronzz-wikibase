<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\ItemIdentityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The label-reuse identity rules: a same-label item is reusable only when it
 * is the item of THAT page. The regression: uploading File:Logic.png (label
 * "Logic") reused the article item Q1838 and silently dropped every fact.
 *
 * @license GPL-2.0-or-later
 */
final class ItemIdentityPolicyTest extends TestCase {

	/** @return iterable<string,array{?string,bool,bool}> */
	public static function fileMatchProvider(): iterable {
		yield 'sitelinked to this file page' => [ 'File:Logic.png', true, true ];
		yield 'sitelinked to this file page, class missing' => [ 'File:Logic.png', false, true ];
		yield 'sitelinked to the article (the bug)' => [ 'Logic', true, false ];
		yield 'sitelinked to another file' => [ 'File:Other.png', true, false ];
		yield 'legacy image item without a sitelink' => [ null, true, true ];
		yield 'legacy non-image item without a sitelink' => [ null, false, false ];
		yield 'foreign item without a sitelink' => [ null, false, false ];
	}

	/** @dataProvider fileMatchProvider */
	public function testCanAdoptFileLabelMatch(
		?string $sitelinkPage,
		bool $isImageItem,
		bool $expected
	): void {
		$this->assertSame(
			$expected,
			ItemIdentityPolicy::canAdoptFileLabelMatch(
				'File:Logic.png',
				$sitelinkPage,
				$isImageItem
			)
		);
	}

	/** @return iterable<string,array{bool,bool,bool}> */
	public static function pageMatchProvider(): iterable {
		yield 'ordinary article item' => [ false, false, true ];
		yield 'same-label image item (the bug)' => [ true, false, false ];
		yield 'sitelinked to a File page' => [ false, true, false ];
		yield 'image item sitelinked to a file page' => [ true, true, false ];
	}

	/** @dataProvider pageMatchProvider */
	public function testCanAdoptPageLabelMatch(
		bool $isImageItem,
		bool $sitelinkIsFilePage,
		bool $expected
	): void {
		$this->assertSame(
			$expected,
			ItemIdentityPolicy::canAdoptPageLabelMatch( $isImageItem, $sitelinkIsFilePage )
		);
	}

}
