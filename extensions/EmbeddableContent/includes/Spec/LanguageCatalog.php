<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * The ISO 639 language catalog backing the AddSource `language` and
 * `additionalLanguages` comboboxes.
 *
 * The data file (`extensions/EmbeddableContent/data/iso639.csv`) is generated
 * from the ISO 639-2 registration authority (the Library of Congress) list —
 * the 639-1 (2-letter), 639-2/B and 639-2/T (3-letter) codes with their
 * English names — by `tools/generate_iso_language_fields.py`. Options are
 * labelled `"{code} — {English name}"` so a contributor partial-matches on
 * BOTH the code and the English name; the value stays the code.
 *
 * Pure PHP (a plain CSV read) so the map is unit-testable without a
 * MediaWiki runtime.
 *
 * @license GPL-2.0-or-later
 */
final class LanguageCatalog {

	/** @var string the committed catalog path */
	public const DEFAULT_PATH = __DIR__ . '/../../data/iso639.csv';

	/** @var string the separator between the code and the English name */
	public const LABEL_SEPARATOR = ' — ';

	/** @var array<string,string>|null lazily loaded code => English name */
	private static ?array $names = null;

	/**
	 * HTMLForm combobox options: label => value (MediaWiki's `options` are
	 * `label => value`, see Html::listDropdownOptionsOoui). The label carries
	 * the code AND the English name; the value is the code.
	 *
	 * @return array<string,string> label => code
	 */
	public static function options(): array {
		$options = [];
		foreach ( self::names() as $code => $name ) {
			$options[ $code . self::LABEL_SEPARATOR . $name ] = $code;
		}
		return $options;
	}

	/** Whether a code is in the catalog. */
	public static function isKnown( string $code ): bool {
		return isset( self::names()[ strtolower( trim( $code ) ) ] );
	}

	/**
	 * The catalog as code => English name, read once from the committed CSV.
	 *
	 * @return array<string,string>
	 */
	public static function names(): array {
		if ( self::$names === null ) {
			self::$names = self::load( self::DEFAULT_PATH );
		}
		return self::$names;
	}

	/**
	 * Parse a catalog CSV (`code,english_name,part`). Exposed for tests.
	 *
	 * @return array<string,string> code => English name
	 */
	public static function load( string $path ): array {
		$contents = @file_get_contents( $path );
		if ( $contents === false ) {
			return [];
		}
		// Strip a UTF-8 BOM.
		if ( str_starts_with( $contents, "\xEF\xBB\xBF" ) ) {
			$contents = substr( $contents, 3 );
		}
		$names = [];
		$lines = preg_split( '/\r?\n/', $contents ) ?: [];
		foreach ( $lines as $index => $line ) {
			if ( $index === 0 ) {
				// Header row.
				continue;
			}
			if ( trim( $line ) === '' ) {
				continue;
			}
			$fields = str_getcsv( $line );
			if ( count( $fields ) < 2 ) {
				continue;
			}
			$code = strtolower( trim( (string)$fields[0] ) );
			$name = trim( (string)$fields[1] );
			if ( $code === '' || $name === '' ) {
				continue;
			}
			$names[$code] = $name;
		}
		return $names;
	}

	/**
	 * A BCP-47-ish language code (2-8 letters, optional `-`-separated
	 * subtags) — the same shape the flow service validates.
	 */
	public static function isCode( string $value ): bool {
		return preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{2,8})*$/i', trim( $value ) ) === 1;
	}
}
