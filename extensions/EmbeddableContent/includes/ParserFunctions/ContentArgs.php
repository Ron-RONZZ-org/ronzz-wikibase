<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

/**
 * Argument parsing for the `{{#content:}}` parser function.
 *
 * The function's first argument is the optional item id; the remaining
 * arguments are flags. `{{#content:Q42|noNote}}` renders the math content
 * WITHOUT its accompanying note (the note renders below the math by default).
 *
 * @license GPL-2.0-or-later
 */
final class ContentArgs {

	/** The flag that suppresses a math item's accompanying note. */
	public const NO_NOTE = 'noNote';

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
