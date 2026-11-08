<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\AdditionalStatements;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the Special:UpdateSource "Call to a member function
 * getDataTypeId() on array" fatal: PropertyInfoLookup::getPropertyInfo()
 * returns an info ARRAY in Wikibase 1.43+.
 *
 * @license GPL-2.0-or-later
 */
class AdditionalStatementsTest extends TestCase {

	public function testDatatypeFromArrayInfo(): void {
		$this->assertSame(
			'string',
			AdditionalStatements::datatypeFromInfo( [ 'type' => 'string' ] )
		);
	}

	public function testDatatypeFromArrayWithoutTypeIsNull(): void {
		$this->assertNull( AdditionalStatements::datatypeFromInfo( [] ) );
	}

	public function testDatatypeFromObjectInfo(): void {
		$info = new class {
			public function getDataTypeId(): string {
				return 'wikibase-item';
			}
		};
		$this->assertSame( 'wikibase-item', AdditionalStatements::datatypeFromInfo( $info ) );
	}

	public function testDatatypeFromNullIsNull(): void {
		$this->assertNull( AdditionalStatements::datatypeFromInfo( null ) );
	}

}
