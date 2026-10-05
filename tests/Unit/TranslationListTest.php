<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\TranslationList;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared added-translations vocabulary (TranslationList)
 * used by the AddQuotation and AddSource/law flows.
 *
 * @license GPL-2.0-or-later
 */
class TranslationListTest extends TestCase {

	public function testBlankRowsAreDroppedAndTextIsEscapedAtRest(): void {
		$result = TranslationList::normalize(
			[
				[ 'language' => '', 'content' => '' ],
				[ 'language' => 'en', 'content' => "line one\nline two" ],
			],
			'fr'
		);
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'en', $result[0]['language'] );
		$this->assertStringContainsString( '\\n', $result[0]['content'] );
	}

	public function testEmptyListIsValid(): void {
		$this->assertSame( [], TranslationList::normalize( [], 'fr' ) );
	}

	public function testInvalidLanguageIsRejected(): void {
		$this->assertIsString(
			TranslationList::normalize( [ [ 'language' => 'not a code!', 'content' => 'x' ] ], 'fr' )
		);
	}

	public function testLanguageWithoutTextIsRejected(): void {
		$this->assertIsString(
			TranslationList::normalize( [ [ 'language' => 'en', 'content' => '' ] ], 'fr' )
		);
	}

	public function testTranslationInTheOriginalLanguageIsRejected(): void {
		$this->assertIsString(
			TranslationList::normalize( [ [ 'language' => 'FR', 'content' => 'x' ] ], 'fr' )
		);
	}

	public function testDuplicateLanguageIsRejected(): void {
		$this->assertIsString(
			TranslationList::normalize(
				[
					[ 'language' => 'en', 'content' => 'one' ],
					[ 'language' => 'en', 'content' => 'two' ],
				],
				'fr'
			)
		);
	}

	public function testNonListIsRejected(): void {
		$this->assertIsString( TranslationList::normalize( 'nope', 'fr' ) );
	}
}
