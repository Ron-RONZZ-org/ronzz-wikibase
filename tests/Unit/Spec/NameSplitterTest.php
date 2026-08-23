<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\NameSplitter;
use PHPUnit\Framework\TestCase;

/**
 * NameSplitter — the mechanical "every word except the last is the given
 * name, the last word is the family name" rule used by the AddPerson search
 * autofill (issue #35).
 *
 * @license GPL-2.0-or-later
 */
final class NameSplitterTest extends TestCase {

	/** @return iterable<string,array{string,string,string}> */
	public static function splitProvider(): iterable {
		yield 'simple' => [ 'Marie Curie', 'Marie', 'Curie' ];
		yield 'multi-word-given' => [ 'Jean-Paul Charles Aymard Sartre', 'Jean-Paul Charles Aymard', 'Sartre' ];
		yield 'hyphenated' => [ 'Jean-Paul Sartre', 'Jean-Paul', 'Sartre' ];
		yield 'particle-kept-in-given' => [ 'Leonardo da Vinci', 'Leonardo da', 'Vinci' ];
		yield 'single-word-family' => [ 'Cher', '', 'Cher' ];
		yield 'empty' => [ '', '', '' ];
		yield 'whitespace' => [ '   ', '', '' ];
		yield 'trailing-space' => [ 'Marie Curie ', 'Marie', 'Curie' ];
		yield 'internal-whitespace-collapsed' => [ '  Marie   Curie  ', 'Marie', 'Curie' ];
		yield 'accented' => [ 'Édith Piaf', 'Édith', 'Piaf' ];
	}

	/** @dataProvider splitProvider */
	public function testSplitFullName( string $name, string $given, string $family ): void {
		$this->assertSame(
			[ 'givenName' => $given, 'familyName' => $family ],
			NameSplitter::splitFullName( $name )
		);
	}
}
