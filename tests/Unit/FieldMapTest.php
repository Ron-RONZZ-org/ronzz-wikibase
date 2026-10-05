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
		// 2026-08-30 was a drifted field table. The legal texts expose no
		// authors — the court / jurisdiction carry the attribution; every
		// other class exposes them. Authors are OPTIONAL on create: title is
		// the only universally required field (a text of unknown authorship
		// is legitimate).
		$legal = [ 'legal-case', 'legislation', 'bill', 'treaty', 'law' ];
		foreach ( SourceFieldMap::CLASS_KEYS as $classKey ) {
			$exposes = SourceFieldMap::acceptsField( $classKey, 'authors' );
			$this->assertSame(
				!in_array( $classKey, $legal, true ),
				$exposes,
				"class $classKey authors exposure drifted"
			);
			$this->assertNotContains(
				'authors',
				SourceFieldMap::requiredOnCreate( $classKey ),
				"class $classKey must not require authors"
			);
		}
	}

	public function testSourceEveryClassExposesLanguage(): void {
		// Every source class carries the optional `language` field (writing a
		// language statement AND choosing the item label/description term
		// language) EXCEPT `law`, whose language is INHERITED from the parent
		// legislation (never a statement, never a form field).
		foreach ( SourceFieldMap::CLASS_KEYS as $classKey ) {
			if ( $classKey === 'law' ) {
				$this->assertFalse(
					SourceFieldMap::acceptsField( $classKey, 'language' ),
					'law must NOT expose the language field (it is inherited)'
				);
				continue;
			}
			$this->assertTrue(
				SourceFieldMap::acceptsField( $classKey, 'language' ),
				"class $classKey must expose the language field"
			);
		}
		$this->assertContains( 'language', SourceFieldMap::ALL_FIELDS );
	}

	public function testSourceRequiredOnCreateIsTitlePlusParentOnly(): void {
		foreach ( SourceFieldMap::CLASS_KEYS as $classKey ) {
			if ( $classKey === 'law' ) {
				// A legal provision has no title: reference code + content +
				// parent are required instead.
				$this->assertSame(
					[ 'referenceCode', 'content', 'parent' ],
					SourceFieldMap::requiredOnCreate( $classKey )
				);
				continue;
			}
			$required = SourceFieldMap::requiredOnCreate( $classKey );
			$this->assertContains( 'title', $required, "class $classKey must require a title" );
			$this->assertSame(
				SourceFieldMap::isChildClass( $classKey ),
				in_array( 'parent', $required, true ),
				"class $classKey parent requirement drifted"
			);
		}
	}

	public function testSourceLawClassContract(): void {
		// The law class (a legal provision, child of legislation): a
		// monolingual clause payload + reference code + added translations,
		// no title / authors / language.
		$this->assertContains( 'law', SourceFieldMap::CLASS_KEYS );
		$fields = SourceFieldMap::fieldsForClass( 'law' );
		foreach ( [ 'referenceCode', 'content', 'translations', 'parent', 'description' ] as $field ) {
			$this->assertContains( $field, $fields, "law must expose $field" );
		}
		foreach ( [ 'title', 'authors', 'language' ] as $field ) {
			$this->assertNotContains( $field, $fields, "law must NOT expose $field" );
		}
		$this->assertSame( 'legislation', SourceFieldMap::PARENT_CLASS['law'] );
		foreach ( [ 'referenceCode', 'content', 'translations' ] as $field ) {
			$this->assertContains( $field, SourceFieldMap::ALL_FIELDS );
		}
	}

	public function testSourceTextCatchAllClass(): void {
		$this->assertContains( 'text', SourceFieldMap::CLASS_KEYS );
		$fields = SourceFieldMap::fieldsForClass( 'text' );
		$this->assertContains( 'title', $fields );
		$this->assertContains( 'authors', $fields );
		$this->assertNotContains( 'publisher', $fields );
		$this->assertSame( [ 'title' ], SourceFieldMap::requiredOnCreate( 'text' ) );
	}

	public function testSourceInternationalFieldIsLegalOnly(): void {
		foreach ( [ 'legal-case', 'legislation', 'bill', 'treaty' ] as $classKey ) {
			$this->assertTrue(
				SourceFieldMap::acceptsField( $classKey, 'international' ),
				"class $classKey must expose the international marker"
			);
		}
		foreach ( [ 'book', 'text', 'website', 'dataset' ] as $classKey ) {
			$this->assertFalse(
				SourceFieldMap::acceptsField( $classKey, 'international' ),
				"class $classKey must not expose the international marker"
			);
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

	public function testSpecialContentNoteIsMathOnly(): void {
		$this->assertTrue( SpecialContentFieldMap::acceptsField( 'math', 'note' ) );
		$this->assertFalse( SpecialContentFieldMap::acceptsField( 'quotation', 'note' ) );
		$this->assertFalse( SpecialContentFieldMap::acceptsField( 'code-snippet', 'note' ) );
		$this->assertNotContains( 'note', SpecialContentFieldMap::requiredOnCreate( 'math' ) );
	}

	public function testSpecialContentTranslationsIsQuotationOnly(): void {
		$this->assertContains( 'translations', SpecialContentFieldMap::ALL_FIELDS );
		$this->assertTrue( SpecialContentFieldMap::acceptsField( 'quotation', 'translations' ) );
		$this->assertFalse( SpecialContentFieldMap::acceptsField( 'math', 'translations' ) );
		$this->assertFalse( SpecialContentFieldMap::acceptsField( 'code-snippet', 'translations' ) );
		$this->assertNotContains( 'translations', SpecialContentFieldMap::requiredOnCreate( 'quotation' ) );
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

	public function testSemanticEntityFictionalCharacterAliasIsKindScoped(): void {
		$this->assertTrue( SemanticEntityFieldMap::acceptsField( 'fictional-character', 'alias' ) );
		$this->assertFalse( SemanticEntityFieldMap::isEntityTyped( 'alias' ) );
		$this->assertNotContains( 'alias', SemanticEntityFieldMap::requiredOnCreate( 'fictional-character' ) );
		foreach ( [ 'person', 'software', 'collective', 'other' ] as $kind ) {
			$this->assertFalse(
				SemanticEntityFieldMap::acceptsField( $kind, 'alias' ),
				"kind $kind must not accept the alias field"
			);
		}
	}
}
