<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

/**
 * Argument parsing for the `{{#content:}}` parser function.
 *
 * The function's first argument is the optional item id; the remaining
 * arguments are flags (`noNote`) or, for quotations, translation language
 * codes. `{{#content:Q42|noNote}}` renders the math content WITHOUT its
 * accompanying note; `{{#content:Q42|fr|en}}` renders the quotation's
 * original text followed by its French and English translations.
 *
 * @license GPL-2.0-or-later
 */
final class ContentArgs {

	/** The flag that suppresses a math item's accompanying note. */
	public const NO_NOTE = 'noNote';

	/**
	 * The language-code arguments after the optional item id — the
	 * translation languages to render below a quotation. Flags (noNote) and
	 * anything that is not a well-formed language code are skipped; order is
	 * preserved and duplicates collapse.
	 *
	 * @param mixed[] $args
	 * @return string[]
	 */
	public static function languages( array $args ): array {
		$languages = [];
		foreach ( array_slice( $args, 1 ) as $arg ) {
			$value = trim( (string)$arg );
			if ( $value === '' || strcasecmp( $value, self::NO_NOTE ) === 0 ) {
				continue;
			}
			if ( preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{2,8})*$/i', $value ) === 1 ) {
				$languages[] = strtolower( $value );
			}
		}
		return array_values( array_unique( $languages ) );
	}

	/**
	 * Whether the argument list (after the optional item id) carries the
	 * noNote flag. Case-insensitive; surrounding whitespace is ignored.
	 *
	 * @param mixed[] $args
	 */
	public static function noNote( array $args ): bool {
		foreach ( array_slice( $args, 1 ) as $arg ) {
			if ( strcasecmp( trim( (string)$arg ), self::NO_NOTE ) === 0 ) {
				return true;
			}
		}
		return false;
	}
}
