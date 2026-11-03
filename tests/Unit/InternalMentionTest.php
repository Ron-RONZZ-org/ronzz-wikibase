<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\InternalMention;
use PHPUnit\Framework\TestCase;

/**
 * The smart internal mention namespace rules: the five classic per-kind
 * namespaces are stripped; Source: is the italicised one.
 *
 * @license GPL-2.0-or-later
 */
class InternalMentionTest extends TestCase {

	private const NAMESPACES = [
		'NS_FOSS' => 2008,
		'NS_PERSON' => 2010,
		'NS_SOURCE' => 2012,
		'NS_COLLECTIVE' => 2014,
		'NS_SOFTWARE' => 2016,
	];

	private const CORE_NAMESPACES = [
		'NS_MAIN' => 0,
		'NS_TALK' => 1,
		'NS_USER' => 2,
		'NS_FILE' => 6,
		'NS_CATEGORY' => 14,
	];

	protected function setUp(): void {
		foreach ( self::NAMESPACES + self::CORE_NAMESPACES as $constant => $id ) {
			if ( !defined( $constant ) ) {
				define( $constant, $id );
			}
		}
	}

	public function testEveryClassicNamespaceIsRecognised(): void {
		foreach ( self::NAMESPACES as $id ) {
			$this->assertTrue(
				InternalMention::isClassicNamespace( $id ),
				"namespace $id must be a classic per-kind namespace"
			);
		}
	}

	public function testMainFileCategoryAndTalkAreNotClassic(): void {
		foreach ( [ NS_MAIN, NS_FILE, NS_CATEGORY, NS_TALK, NS_USER, 2009, 2011, 2013, 2015, 2017 ] as $id ) {
			$this->assertFalse(
				InternalMention::isClassicNamespace( $id ),
				"namespace $id must not be stripped"
			);
		}
	}

	public function testOnlySourceIsItalicised(): void {
		$this->assertTrue( InternalMention::isSourceNamespace( NS_SOURCE ) );
		foreach ( [ NS_PERSON, NS_COLLECTIVE, NS_FOSS, NS_SOFTWARE, NS_MAIN ] as $id ) {
			$this->assertFalse( InternalMention::isSourceNamespace( $id ) );
		}
	}

}
