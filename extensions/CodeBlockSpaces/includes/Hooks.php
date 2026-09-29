<?php

declare( strict_types = 1 );

namespace CodeBlockSpaces;

use MediaWiki\Parser\Parser;

/**
 * CodeBlockSpaces entry-point hooks.
 *
 * @license GPL-2.0-or-later
 */
final class Hooks {

	/**
	 * `ParserAfterTidy` runs after core has applied its French-space armoring
	 * (Parser::internalParse() calls the tidy callback before the hook), so
	 * this is where the code-block spaces can be restored.
	 */
	public static function onParserAfterTidy( Parser $parser, string &$text ): void {
		$text = SpaceRestorer::restore( $text );
	}
}
