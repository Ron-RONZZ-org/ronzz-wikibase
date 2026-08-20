<?php
/**
 * Internationalisation file for the WikibaseCitation extension's parser
 * functions (issue #24, cite-by-QID).
 *
 * `{{#cite:…}}` / `{{#citations}}` are registered via
 * `Parser::setFunctionHook`, which resolves the function name through the
 * language's magic-word list — without a synonym here, MediaWiki throws
 * "Error: invalid magic word 'cite'" at registration time. Synonyms are
 * declared for the instance languages (en/fr/eo); the magic-word id is the
 * internal key, the synonym is the wikitext spelling.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

$magicWords = [];

/** English (English) */
$magicWords['en'] = [
	'cite' => [ 0, 'cite' ],
	'citations' => [ 0, 'citations' ],
];

/** French (français) */
$magicWords['fr'] = [
	'cite' => [ 0, 'cite' ],
	'citations' => [ 0, 'citations' ],
];

/** Esperanto (Esperanto) */
$magicWords['eo'] = [
	'cite' => [ 0, 'cite' ],
	'citations' => [ 0, 'citations' ],
];
