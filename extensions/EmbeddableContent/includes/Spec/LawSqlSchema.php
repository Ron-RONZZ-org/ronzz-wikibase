<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * MySQL DDL for the law-corpus store, built from LawSchema::tables().
 *
 * Kept in its own file so LawSchema stays a readable contract. The installer
 * passes the MediaWiki table prefix, so the physical names are
 * `{prefix}wb_law_*`.
 *
 * @license GPL-2.0-or-later
 */
final class LawSqlSchema {

	public static function sql( string $prefix = LawSchema::TABLE_PREFIX ): string {
		$out = "-- AUTO-GENERATED — do not edit by hand.\n"
			. "-- Source: extensions/EmbeddableContent/includes/Spec/LawSchema.php\n\n";
		foreach ( LawSchema::tables() as $name => $table ) {
			$lines = [];
			foreach ( $table['columns'] as $column => $definition ) {
				$lines[] = "  `$column` $definition";
			}
			$primary = (array)$table['primary'];
			$lines[] = '  PRIMARY KEY (`' . implode( '`, `', $primary ) . '`)';
			foreach ( $table['unique'] ?? [] as $cols ) {
				$lines[] = '  UNIQUE KEY `u_' . implode( '_', $cols ) . '` (`' . implode( '`, `', $cols ) . '`)';
			}
			foreach ( $table['indexes'] ?? [] as $cols ) {
				$lines[] = '  KEY `i_' . implode( '_', $cols ) . '` (`' . implode( '`, `', $cols ) . '`)';
			}
			foreach ( $table['fulltext'] ?? [] as $col ) {
				$lines[] = "  FULLTEXT KEY `ft_$col` (`$col`)";
			}
			foreach ( $table['foreign'] ?? [] as $fk ) {
				$lines[] = "  CONSTRAINT `fk_{$name}_{$fk[0]}` FOREIGN KEY (`{$fk[0]}`) REFERENCES `"
					. $prefix . "{$fk[1]}` (`{$fk[2]}`) ON DELETE CASCADE";
			}
			$out .= 'CREATE TABLE IF NOT EXISTS `' . $prefix . "$name` (\n"
				. implode( ",\n", $lines ) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
		}
		return $out;
	}
}
