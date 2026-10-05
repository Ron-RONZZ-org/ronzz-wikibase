<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Content\PayloadCodec;

/**
 * The added-translations vocabulary — a list of `{language, content}` rows
 * (the "Add translation" HTMLForm cloner) — shared by the AddQuotation
 * content flow and the AddSource `law` flow so the validation can never
 * drift. Pure PHP, unit-testable.
 *
 * `normalize()` drops untouched rows (both parts blank), rejects a partially
 * filled row / an invalid language code / a duplicate language / a
 * translation in the original language as a user-facing error string, and
 * escapes the text at rest (`PayloadCodec`) exactly like every multi-line
 * payload. An empty-but-present list is valid and means "no translations"
 * (the update no-clobber contract: present-empty clears, absent preserves).
 *
 * @license GPL-2.0-or-later
 */
final class TranslationList {

	/**
	 * @param mixed $raw the submitted translations value
	 * @param string|null $baseLanguage the original language, or null when unknown
	 * @return array<int,array{language:string,content:string}>|string rows, or an error string
	 */
	public static function normalize( $raw, ?string $baseLanguage ) {
		if ( !is_array( $raw ) ) {
			return 'translations must be a list of {language, content} rows.';
		}
		$out = [];
		$seen = [];
		foreach ( $raw as $row ) {
			if ( !is_array( $row ) ) {
				return 'translations must be a list of {language, content} rows.';
			}
			$lang = trim( (string)( $row['language'] ?? '' ) );
			$text = trim( (string)( $row['content'] ?? '' ) );
			if ( $lang === '' && $text === '' ) {
				continue; // an untouched added row
			}
			if ( !preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{2,8})*$/i', $lang ) ) {
				return "translation language \"{$lang}\" is not a valid language code.";
			}
			if ( $text === '' ) {
				return "translation \"{$lang}\" has no text.";
			}
			if ( $baseLanguage !== null && strcasecmp( $lang, $baseLanguage ) === 0 ) {
				return "translation language \"{$lang}\" is the same as the original language.";
			}
			if ( isset( $seen[strtolower( $lang )] ) ) {
				return "duplicate translation language \"{$lang}\".";
			}
			$seen[strtolower( $lang )] = true;
			$out[] = [ 'language' => $lang, 'content' => PayloadCodec::escape( $text ) ];
		}
		return $out;
	}
}
