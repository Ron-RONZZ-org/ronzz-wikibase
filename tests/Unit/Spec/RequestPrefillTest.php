<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\RequestPrefill;
use PHPUnit\Framework\TestCase;

/**
 * RequestPrefill — URL query-param prefill for the Add* / Update* HTMLForms
 * (deep links): a field named in the query takes that value as its default,
 * while POST-driven and server-owned fields stay untouched.
 *
 * @license GPL-2.0-or-later
 */
final class RequestPrefillTest extends TestCase {

	public function testAppliesScalarQueryValueToMatchingField(): void {
		$fields = [
			'parent' => [ 'type' => 'combobox', 'default' => '' ],
			'referenceCode' => [ 'type' => 'text' ],
		];
		$result = RequestPrefill::apply( $fields, [
			'parent' => 'Q2048',
			'referenceCode' => 'Article 9',
			'addmore' => '1',
		] );
		$this->assertSame( 'Q2048', $result['parent']['default'] );
		$this->assertSame( 'Article 9', $result['referenceCode']['default'] );
		// A query key with no matching field is ignored.
		$this->assertArrayNotHasKey( 'addmore', $result );
	}

	public function testTrimsValueAndOverridesAnExistingDefault(): void {
		$fields = [ 'referenceCode' => [ 'type' => 'text', 'default' => 'From the record' ] ];
		$result = RequestPrefill::apply( $fields, [ 'referenceCode' => '  From the URL  ' ] );
		$this->assertSame( 'From the URL', $result['referenceCode']['default'] );
	}

	public function testIgnoresEmptyAndArrayValues(): void {
		$fields = [
			'referenceCode' => [ 'type' => 'text', 'default' => 'keep' ],
			'parent' => [ 'type' => 'combobox', 'default' => 'keep' ],
		];
		$result = RequestPrefill::apply( $fields, [
			'referenceCode' => '   ',
			'parent' => [ 'Q1', 'Q2' ],
		] );
		$this->assertSame( 'keep', $result['referenceCode']['default'] );
		$this->assertSame( 'keep', $result['parent']['default'] );
	}

	/** @return iterable<string,array{string}> */
	public static function skippedTypeProvider(): iterable {
		yield 'hidden' => [ 'hidden' ];
		yield 'submit' => [ 'submit' ];
		yield 'button' => [ 'button' ];
		yield 'info' => [ 'info' ];
		yield 'html' => [ 'html' ];
		yield 'cloner' => [ 'cloner' ];
	}

	/** @dataProvider skippedTypeProvider */
	public function testNeverPrefillsServerOwnedFieldTypes( string $type ): void {
		$fields = [ 'class' => [ 'type' => $type, 'default' => 'Q1' ] ];
		$result = RequestPrefill::apply( $fields, [ 'class' => 'Q999' ] );
		$this->assertSame( 'Q1', $result['class']['default'] );
	}

	public function testPreservesUnrelatedSpecKeys(): void {
		$fields = [
			'parent' => [
				'type' => 'combobox',
				'label-message' => 'embeddablecontent-source-field-parent',
				'required' => true,
			],
		];
		$result = RequestPrefill::apply( $fields, [ 'parent' => 'Q2048' ] );
		$this->assertSame( 'embeddablecontent-source-field-parent', $result['parent']['label-message'] );
		$this->assertTrue( $result['parent']['required'] );
		$this->assertSame( 'Q2048', $result['parent']['default'] );
	}

	public function testIgnoresMediaWikiReservedRoutingParams(): void {
		// A Special page canonicalises to index.php?title=Special:… — the
		// routing `title` must never prefill the AddSource `title` field
		// (the sitelink-corruption regression hit in CI).
		$fields = [ 'title' => [ 'type' => 'text', 'default' => '' ] ];
		$result = RequestPrefill::apply( $fields, [ 'title' => 'Special:UpdateSource/Q231' ] );
		$this->assertSame( '', $result['title']['default'] );

		$fields = [ 'action' => [ 'type' => 'text', 'default' => '' ] ];
		$result = RequestPrefill::apply( $fields, [ 'action' => 'edit' ] );
		$this->assertSame( '', $result['action']['default'] );
	}
}
