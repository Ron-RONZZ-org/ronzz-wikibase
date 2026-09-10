<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Flow\SemanticEntityFieldMap;
use EmbeddableContent\Flow\SourceFieldMap;
use EmbeddableContent\Flow\SpecialContentFieldMap;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the entity-mode field maps (the API-module contracts).
 *
 * @license GPL-2.0-or-later
 */
class FieldMapTest extends TestCase {

	// ------------------------------------------------------------- source

	public function testSourceEveryClassHasAFieldSet(): void {
		foreach ( SourceFieldMap::CLASS_KEYS as $classKey ) {
			$this->assertNotEmpty(
				SourceFieldMap::fieldsForClass( $classKey ),
				"class $classKey has no fields"
			);
		}
	}

	public function testSourceAuthorsExposureMatchesTheContract(): void {
		// Regression: the "webpage rejects authors yet demands one" bug of
		// 2026-08-30 was a drifted field table. Every class that exposes
		// authors requires one on create (except book-excerpt, parent-filled);
		// the legal texts expose no authors — the court / jurisdiction carry
		// the attribution.
		$legal = [ 'legal-case', 'legislation', 'bill', 'treaty' ];
		foreach ( SourceFieldMap::CLASS_KEYS as $classKey ) {
			$exposes = SourceFieldMap::acceptsField( $classKey, 'authors' );
			$this->assertSame(
				!in_array( $classKey, $legal, true ),
				$exposes,
				"class $classKey authors exposure drifted"
			);
			if ( $exposes && $classKey !== 'book-excerpt' ) {
				$this->assertContains( 'authors', SourceFieldMap::requiredOnCreate( $classKey ) );
			}
		}
	}

	public function testSourceClassFieldsAreFromTheVocabulary(): void {
		foreach ( SourceFieldMap::CLASS_KEYS as $classKey ) {
			foreach ( SourceFieldMap::fieldsForClass( $classKey ) as $field ) {
				$this->assertContains(
					$field,
					SourceFieldMap::ALL_FIELDS,
					"class $classKey lists unknown field $field"
				);
			}
		}
	}

	public function testSourceRequiredOnCreateIsSubsetOfExposedFields(): void {
		foreach ( SourceFieldMap::CLASS_KEYS as $classKey ) {
			foreach ( SourceFieldMap::requiredOnCreate( $classKey ) as $field ) {
				$this->assertTrue(
					SourceFieldMap::acceptsField( $classKey, $field ),
					"class $classKey requires $field but does not expose it"
				);
			}
		}
	}

	public function testSourceChildClassesRequireParent(): void {
		foreach ( SourceFieldMap::PARENT_CLASS as $child => $parent ) {
			$this->assertContains( 'parent', SourceFieldMap::requiredOnCreate( $child ) );
			$this->assertContains( $parent, SourceFieldMap::CLASS_KEYS );
		}
	}

	public function testSourceBookExcerptDoesNotRequireAuthors(): void {
		$this->assertNotContains( 'authors', SourceFieldMap::requiredOnCreate( 'book-excerpt' ) );
	}

	public function testSourceEntityTypedFieldsAreInTheVocabulary(): void {
		foreach ( [ 'authors', 'publisher', 'journal', 'parent', 'court' ] as $field ) {
			$this->assertContains( $field, SourceFieldMap::ALL_FIELDS );
			$this->assertTrue( SourceFieldMap::isEntityTyped( $field ) );
		}
	}

	// ----------------------------------------------------- special content

	public function testSpecialContentKindsAndFields(): void {
		foreach ( SpecialContentFieldMap::KINDS as $kind ) {
			$this->assertNotEmpty( SpecialContentFieldMap::fieldsForKind( $kind ) );
			foreach ( SpecialContentFieldMap::fieldsForKind( $kind ) as $field ) {
				$this->assertContains( $field, SpecialContentFieldMap::ALL_FIELDS );
			}
			foreach ( SpecialContentFieldMap::requiredOnCreate( $kind ) as $field ) {
				$this->assertTrue( SpecialContentFieldMap::acceptsField( $kind, $field ) );
			}
		}
		$this->assertContains( 'attributedTo', SpecialContentFieldMap::requiredOnCreate( 'quotation' ) );
	}

	// ----------------------------------------------------- semantic entity

	public function testSemanticEntityKindsAndFields(): void {
		foreach ( SemanticEntityFieldMap::KINDS as $kind ) {
			$this->assertNotEmpty( SemanticEntityFieldMap::fieldsForKind( $kind ) );
			foreach ( SemanticEntityFieldMap::fieldsForKind( $kind ) as $field ) {
				$this->assertContains( $field, SemanticEntityFieldMap::ALL_FIELDS );
			}
			foreach ( SemanticEntityFieldMap::requiredOnCreate( $kind ) as $field ) {
				$this->assertTrue( SemanticEntityFieldMap::acceptsField( $kind, $field ) );
			}
		}
		$this->assertContains( 'givenName', SemanticEntityFieldMap::requiredOnCreate( 'person' ) );
		$this->assertContains( 'instanceOf', SemanticEntityFieldMap::requiredOnCreate( 'other' ) );
		$this->assertContains( 'developer', SemanticEntityFieldMap::fieldsForKind( 'software' ) );
	}
}
