<?php

declare( strict_types = 1 );

namespace WikibaseCitation\ParserFunctions;

/**
 * Parser-function argument parsing for {{#cite:…}} and {{#citations:…}}
 * (issue #24 v1, issue #25 v2).
 *
 * MW 1.46 passes parser-function arguments to the callback as literal
 * strings in call order; named arguments arrive as `"key=value"` strings
 * (verified in `Parser::callParserFunction`, non-SFH_OBJECT_ARGS path).
 *
 * Positional arguments matching the entity-id pattern (`Q\d+` or `P\d+`,
 * case-insensitive) are collected as **entity ids** (v2 multi-entity
 * `{{#cite:Q42|Q7}}` / explicit `{{#citations:Q42|Q7}}`); positional
 * arguments that are NOT entity ids keep the v1 meaning (style, then
 * output) — so `{{#cite:Q42|vancouver}}` still works.
 *
 * Pure logic, no MediaWiki dependencies: unit-tested standalone.
 *
 * @license GPL-2.0-or-later
 */
final class CitationArgs {

	/** Named argument keys the functions understand. */
	private const KNOWN_KEYS = [ 'style', 'output' ];

	/**
	 * @param string[] $args The raw string arguments.
	 * @return array{entities:string[],style:string,output:string}
	 *  `entities` = positional entity ids in call order ('' filtered);
	 *  '' in style/output means "not given" (the caller applies defaults).
	 *  Unknown named keys are ignored (forward compatibility).
	 */
	public static function parse( array $args ): array {
		$entities = [];
		$named = [ 'style' => '', 'output' => '' ];
		$positional = [];

		foreach ( $args as $arg ) {
			if ( !is_string( $arg ) ) {
				continue;
			}
			$arg = trim( $arg );
			if ( $arg === '' ) {
				continue;
			}
			if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*)\s*=\s*(.*)$/', $arg, $m ) === 1 ) {
				$key = strtolower( $m[1] );
				if ( in_array( $key, self::KNOWN_KEYS, true ) ) {
					$named[$key] = trim( $m[2] );
				}
			} elseif ( self::isEntityId( $arg ) ) {
				$entities[] = $arg;
			} else {
				$positional[] = $arg;
			}
		}

		if ( $named['style'] === '' && isset( $positional[0] ) ) {
			$named['style'] = $positional[0];
		}
		if ( $named['output'] === '' && isset( $positional[1] ) ) {
			$named['output'] = $positional[1];
		}
		return [ 'entities' => $entities, 'style' => $named['style'], 'output' => $named['output'] ];
	}

	/**
	 * True when the argument looks like a Wikibase entity id (`Q42`, `P7`,
	 * lowercase allowed). Entity ids are never styles, so the v2 multi-entity
	 * syntax is unambiguous against the v1 positional style argument.
	 */
	public static function isEntityId( string $arg ): bool {
		return preg_match( '/^[QqPp]\d+$/', $arg ) === 1;
	}
}
