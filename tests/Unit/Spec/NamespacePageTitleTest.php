<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Tests\Unit\Spec;

use EmbeddableContent\Spec\NamespacePageTitle;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\NamespacePageTitle
 * @license GPL-2.0-or-later
 */
class NamespacePageTitleTest extends TestCase {

	public function testFormatsPageInNamespace(): void {
		$this->assertSame(
			'Beloved in Source',
			NamespacePageTitle::format( 'Beloved', 'Source', 'in' )
		);
	}

	public function testKeepsTheSubpageRemainder(): void {
		$this->assertSame(
			'Beloved/fr in Source',
			NamespacePageTitle::format( 'Beloved/fr', 'Source', 'in' )
		);
	}

	public function testUsesTheLocalizedConnectorAndNamespaceName(): void {
		$this->assertSame(
			'Beloved dans Source',
			NamespacePageTitle::format( 'Beloved', 'Source', 'dans' )
		);
	}

	public function testTrimsParts(): void {
		$this->assertSame(
			'Beloved in Source',
			NamespacePageTitle::format( '  Beloved  ', '  Source ', ' in ' )
		);
	}

	public function testBlankPartsAreRejected(): void {
		$this->assertNull( NamespacePageTitle::format( '', 'Source', 'in' ) );
		$this->assertNull( NamespacePageTitle::format( 'Beloved', '', 'in' ) );
		$this->assertNull( NamespacePageTitle::format( 'Beloved', 'Source', '' ) );
	}

}
