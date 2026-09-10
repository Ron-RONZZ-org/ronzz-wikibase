<?php
/**
 * Magic-word aliases for the LanguageBar parser function.
 *
 * `{{#languagebar:}}` is registered via `Parser::setFunctionHook`, which
 * resolves the function name through the language's magic-word list —
 * without a synonym here MediaWiki throws "invalid magic word 'languagebar'"
 * at registration time. The magic-word id is the internal key, the synonym
 * is the wikitext spelling.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

$magicWords = [];

/** English (English) */
$magicWords['en'] = [
	'languagebar' => [ 0, 'languagebar', 'language-bar' ],
];

/** French (français) */
$magicWords['fr'] = [
	'languagebar' => [ 0, 'languagebar', 'language-bar' ],
];

/** Esperanto (Esperanto) */
$magicWords['eo'] = [
	'languagebar' => [ 0, 'languagebar', 'language-bar' ],
];
