<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\FallbackChain;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure instance language-fallback decoration (Req 3 +
 * Req 4): the instance `fallbackLanguages` are appended to Wikibase's term
 * fallback chain, de-duplicated and with the requested language skipped.
 *
 * @license GPL-2.0-or-later
 */
class FallbackChainTest extends TestCase {

	public function testAppendsInstanceLanguagesAfterTheBaseChain(): void {
		$this->assertSame(
			[ 'en', 'mul', 'fr', 'eo' ],
			FallbackChain::append( [ 'en', 'mul' ], 'en', [ 'fr', 'en', 'eo' ] )
		);
	}

	public function testSkipsTheRequestedLanguageAndDuplicates(): void {
		$this->assertSame(
			[ 'en', 'fr' ],
			FallbackChain::append( [ 'en' ], 'en', [ 'en', 'fr', 'fr' ] )
		);
	}

	public function testEmptyExtraKeepsTheChain(): void {
		$this->assertSame(
			[ 'en', 'mul' ],
			FallbackChain::append( [ 'en', 'mul' ], 'en', [] )
		);
	}

	public function testRequestedLanguageAlreadyInTheChainIsNotDuplicated(): void {
		$this->assertSame(
			[ 'fr', 'en', 'mul', 'eo' ],
			FallbackChain::append( [ 'fr', 'en', 'mul' ], 'fr', [ 'eo' ] )
		);
	}

}
