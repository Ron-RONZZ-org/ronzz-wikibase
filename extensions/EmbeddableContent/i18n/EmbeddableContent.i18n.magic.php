<?php
/**
 * Internationalisation file for the EmbeddableContent extension's parser
 * functions (ADR docs/decisions/source-access-rendering.md).
 *
 * `{{#source-access:}}` is registered via `Parser::setFunctionHook`, which
 * resolves the function name through the language's magic-word list —
 * without a synonym here, MediaWiki throws "Error: invalid magic word
 * 'sourceaccess'" at registration time. The magic-word id is the internal
 * key, the synonym is the wikitext spelling.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

$magicWords = [];

/** English (English) */
$magicWords['en'] = [
	'sourceaccess' => [ 0, 'source-access' ],
	'itemimage' => [ 0, 'item-image' ],
	'content' => [ 0, 'content' ],
];

/** French (français) */
$magicWords['fr'] = [
	'sourceaccess' => [ 0, 'source-access' ],
	'itemimage' => [ 0, 'item-image' ],
	'content' => [ 0, 'contenu' ],
];

/** Esperanto (Esperanto) */
$magicWords['eo'] = [
	'sourceaccess' => [ 0, 'source-access' ],
	'itemimage' => [ 0, 'item-image' ],
	'content' => [ 0, 'enhavo' ],
];
