<?php

declare( strict_types = 1 );

namespace Tests\Unit\Law;

use EmbeddableContent\Spec\LawSchema;
use EmbeddableContent\Spec\LawSqlSchema;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
class LawSchemaTest extends TestCase {

	public function testTablesCoverTheCorpus(): void {
		$tables = LawSchema::tables();
		foreach ( [ 'laws', 'sections', 'articles', 'runs' ] as $table ) {
			$this->assertArrayHasKey( $table, $tables );
		}
		$this->assertSame( 'law_key', $tables['laws']['primary'] );
		$this->assertSame( [ 'law_key', 'reference_code', 'language' ], $tables['articles']['primary'] );
	}

	public function testV11AddsTheLegalColumns(): void {
		$tables = LawSchema::tables();
		$this->assertSame( '1.1', LawSchema::VERSION );
		$this->assertArrayHasKey( 'number', $tables['laws']['columns'] );
		$this->assertArrayHasKey( 'court', $tables['laws']['columns'] );
		$this->assertArrayHasKey( 'source_url', $tables['articles']['columns'] );
	}

	public function testDdlUsesThePrefixAndTheParseKeys(): void {
		$sql = LawSqlSchema::sql( 'mw_' . LawSchema::TABLE_PREFIX );
		$this->assertStringContainsString( 'CREATE TABLE IF NOT EXISTS `mw_wb_law_laws`', $sql );
		$this->assertStringContainsString( 'CREATE TABLE IF NOT EXISTS `mw_wb_law_articles`', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY (`law_key`)', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY (`law_key`, `reference_code`, `language`)', $sql );
		$this->assertStringContainsString( 'FULLTEXT KEY `ft_search_text`', $sql );
		$this->assertStringContainsString( 'FOREIGN KEY (`law_key`) REFERENCES `mw_wb_law_laws` (`law_key`)', $sql );
	}
}
