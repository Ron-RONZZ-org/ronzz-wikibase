<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

/**
 * Argument parsing for the `{{#content:}}` parser function.
 *
 * The function's first argument is the optional item id; the remaining
 * arguments are flags or, for quotations, translation language codes. For a
 * math item three flags suppress its accompanying notes:
 * `{{#content:Q42|noPreNote}}` hides the pre-content note (above the
 * expression) only, `{{#content:Q42|noPostNote}}` hides the post-content note
 * (below it) only, and `{{#content:Q42|noNote}}` hides both.
 * `{{#content:Q42|fr|en}}` renders the quotation's original text followed by
 * its French and English translations.
 *
 * @license GPL-2.0-or-later
 */
final class ContentArgs {

	/** The flag that suppresses BOTH math notes (pre-content and post-content). */
	public const NO_NOTE = 'noNote';

	/** The flag that suppresses the math PRE-content note only. */
	public const NO_PRE_NOTE = 'noPreNote';

	/** The flag that suppresses the math POST-content note only. */
	public const NO_POST_NOTE = 'noPostNote';

	/** The math-note flags (never language codes). */
	private const NOTE_FLAGS = [ self::NO_NOTE, self::NO_PRE_NOTE, self::NO_POST_NOTE ];

	/**
	 * The language-code arguments after the optional item id — the
	 * translation languages to render below a quotation. Flags (the
	 * note-suppression flags) and anything that is not a well-formed language
	 * code are skipped; order is preserved and duplicates collapse.
	 *
	 * @param mixed[] $args
	 * @return string[]
	 */
	public static function languages( array $args ): array {
		$languages = [];
		foreach ( array_slice( $args, 1 ) as $arg ) {
			$value = trim( (string)$arg );
			if ( $value === '' || self::isNoteFlag( $value ) ) {
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
	 * `noNote` flag (suppresses BOTH notes). Case-insensitive; surrounding
	 * whitespace is ignored.
	 *
	 * @param mixed[] $args
	 */
	public static function noNote( array $args ): bool {
		return self::hasFlag( $args, self::NO_NOTE );
	}

	/**
	 * Whether the argument list carries the `noPreNote` flag (suppresses the
	 * pre-content note only). Case-insensitive.
	 *
	 * @param mixed[] $args
	 */
	public static function noPreNote( array $args ): bool {
		return self::hasFlag( $args, self::NO_PRE_NOTE );
	}

	/**
	 * Whether the argument list carries the `noPostNote` flag (suppresses the
	 * post-content note only). Case-insensitive.
	 *
	 * @param mixed[] $args
	 */
	public static function noPostNote( array $args ): bool {
		return self::hasFlag( $args, self::NO_POST_NOTE );
	}

	/** @param mixed[] $args */
	private static function hasFlag( array $args, string $flag ): bool {
		foreach ( array_slice( $args, 1 ) as $arg ) {
			if ( strcasecmp( trim( (string)$arg ), $flag ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	private static function isNoteFlag( string $value ): bool {
		foreach ( self::NOTE_FLAGS as $flag ) {
			if ( strcasecmp( $value, $flag ) === 0 ) {
				return true;
			}
		}
		return false;
	}
}
