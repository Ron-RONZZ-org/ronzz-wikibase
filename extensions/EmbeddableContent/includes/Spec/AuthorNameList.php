<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Parses a pasted author-name string into individual person-name rows.
 *
 * The AddSource author field accepts a single string so a contributor can
 * paste a bibliography's author list instead of creating each person by
 * hand. The accepted shapes (the "Author names" help text mirrors this):
 *
 *  - only commas:   "Christian Moro, Charlotte Phelps"
 *                   comma = author separator, each author = given… family
 *                   (last word = family);
 *  - commas + semicolons:
 *                   "Moro, Christian; Phelps, Charlotte"
 *                   semicolon = author separator, comma = family, given;
 *  - semicolons only: "Christian Moro; Charlotte Phelps"
 *                   semicolon = author separator, last word = family;
 *  - no comma/semicolon: split on " and " / " & " when present; otherwise
 *                   one author.
 *
 * Special cases: a single comma joining two single words ("Moro, Christian")
 * is one family-first author (the common bibliography shape); a single
 * word is a mononym (flagged); a trailing "et al."/"and Contributors" is
 * dropped and flagged; generational suffixes stay with the family name;
 * parenthetical alternates are dropped from the name and flagged; CJK
 * text is flagged for confirmation (the Western last-word rule may be
 * wrong).
 *
 * The parser is pure (no MediaWiki output) so it is unit-testable; warning
 * codes are mapped to i18n by the callers.
 *
 * @license GPL-2.0-or-later
 */
final class AuthorNameList {

	public const FORMAT_AUTO = 'auto';
	public const FORMAT_COMMA = 'comma';
	public const FORMAT_SEMICOLON = 'semicolon';
	public const FORMAT_SINGLE = 'single';

	/** Generational suffixes that belong to the family name. */
	private const SUFFIXES = [ 'jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v' ];

	/** Segments that are not person names. */
	private const NON_NAMES = [
		'et al', 'et al.', 'et alia', 'contributors', 'contributor',
		'and contributors', 'and contributor', '& contributors', '& contributor',
	];

	/**
	 * @param string $input raw author list
	 * @param string $format one of the FORMAT_* constants (auto = detect)
	 * @return array{
	 *   names: list<array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>}>,
	 *   warnings: list<array{code:string,message:string,name:string}>
	 * }
	 */
	public static function parse( string $input, string $format = self::FORMAT_AUTO ): array {
		$input = self::normalizeWhitespace( $input );
		$warnings = [];
		if ( $input === '' ) {
			return [ 'names' => [], 'warnings' => $warnings ];
		}

		// Collectives: "ORG:Wikimedia Foundation". The prefix marks the
		// segment as an organisation, not a person. The name runs to the
		// next comma/semicolon (so an organisation name containing a comma
		// must be in a semicolon-separated list). Parentheses are NOT part
		// of the syntax.
		[ $input, $organizations ] = self::extractOrganizations( $input, $warnings );

		// Remove parenthetical alternates BEFORE splitting: a CJK
		// transliteration like "Satoshi Watanabe (杉 森 伸 吉, 渡 辺 聡)"
		// contains commas that must not become author separators.
		$input = self::stripParentheticalGroups( $input, $warnings );

		$format = self::detectFormat( $input, $format );
		$rows = match ( $format ) {
			self::FORMAT_SEMICOLON => self::splitSemicolon( $input, $warnings ),
			self::FORMAT_COMMA => self::splitComma( $input, $warnings ),
			default => self::splitAndOrSingle( $input, $warnings ),
		};

		$names = [];
		$seen = [];
		foreach ( $rows as $row ) {
			$name = self::buildName(
				$row['text'],
				$row['familyFirst'],
				$warnings,
				$row['original'] ?? null,
				$organizations
			);
			if ( $name === null ) {
				continue;
			}
			$key = $name['kind'] === 'collective'
				? 'collective|' . mb_strtolower( $name['familyName'] )
				: 'person|' . mb_strtolower( $name['givenName'] . '|' . $name['familyName'] );
			if ( isset( $seen[$key] ) ) {
				$warnings[] = self::warning( 'duplicate', $name['original'], 'duplicate name ignored' );
				continue;
			}
			$seen[$key] = true;
			$names[] = $name;
		}

		return [ 'names' => $names, 'warnings' => $warnings ];
	}

	/**
	 * Replaces every `ORG:name` with a single safe token and returns the
	 * map token => organization. The token survives the normal comma/
	 * semicolon/and splitting, so the parsed order is preserved.
	 *
	 * @param array<int,array{code:string,message:string,name:string}> $warnings
	 * @return array{0:string,1:array<string,array{name:string,original:string}>}
	 */
	private static function extractOrganizations( string $input, array &$warnings ): array {
		$organizations = [];
		$out = '';
		$offset = 0;
		// In a semicolon-separated list a comma may belong to the
		// organization name ("ORG:Smith, Jones and Associates; Jane Doe").
		$semicolonFormat = str_contains( $input, ';' );
		while ( preg_match( '/\bORG\s*:/iu', $input, $match, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$start = (int)$match[0][1];
			$nameStart = $start + strlen( $match[0][0] );
			$end = self::organizationNameEnd( $input, $nameStart, $semicolonFormat );
			$name = trim( substr( $input, $nameStart, $end - $nameStart ) );
			$original = 'ORG:' . $name;
			if ( $name === '' ) {
				$warnings[] = self::warning( 'org_empty', $original, 'ORG: with no organization name ignored' );
				$out .= substr( $input, $offset, $start - $offset );
				$offset = $end;
				continue;
			}
			$token = 'WbOrgAuthor' . count( $organizations ) . 'Token';
			$organizations[$token] = [ 'name' => $name, 'original' => $original ];
			$out .= substr( $input, $offset, $start - $offset ) . ' ' . $token . ' ';
			$offset = $end;
		}
		$out .= substr( $input, $offset );
		return [ self::normalizeWhitespace( $out ), $organizations ];
	}

	/**
	 * The end of the organization name starting at $start: the next
	 * comma/semicolon at parenthesis depth 0, or the end of the string.
	 * "ORG:" names therefore cannot contain an unparenthesized comma in a
	 * comma-separated list — use semicolons when they do.
	 */
	private static function organizationNameEnd( string $input, int $start, bool $semicolonFormat = false ): int {
		$depth = 0;
		$length = strlen( $input );
		for ( $i = $start; $i < $length; $i++ ) {
			$char = $input[$i];
			if ( $char === '(' ) {
				$depth++;
			} elseif ( $char === ')' && $depth > 0 ) {
				$depth--;
			} elseif ( $depth === 0
				&& ( $char === ';' || ( !$semicolonFormat && $char === ',' ) )
			) {
				return $i;
			}
		}
		return $length;
	}

	private static function detectFormat( string $input, string $format ): string {
		if ( in_array( $format, [ self::FORMAT_COMMA, self::FORMAT_SEMICOLON, self::FORMAT_SINGLE ], true ) ) {
			return $format;
		}
		if ( str_contains( $input, ';' ) ) {
			return self::FORMAT_SEMICOLON;
		}
		if ( str_contains( $input, ',' ) ) {
			return self::FORMAT_COMMA;
		}
		return self::FORMAT_SINGLE;
	}

	/**
	 * @param array<int,array{code:string,message:string,name:string}> $warnings
	 * @return list<array{text:string,familyFirst:bool}>
	 */
	private static function splitSemicolon( string $input, array &$warnings ): array {
		$rows = [];
		foreach ( explode( ';', $input ) as $segment ) {
			$segment = trim( $segment );
			if ( $segment === '' ) {
				continue;
			}
			$segment = self::stripNonName( $segment, $warnings );
			if ( $segment === '' ) {
				continue;
			}
			if ( str_contains( $segment, ',' ) ) {
				$parts = array_map( 'trim', explode( ',', $segment ) );
				$family = array_shift( $parts ) ?? '';
				$given = implode( ', ', $parts );
				if ( $family === '' || $given === '' ) {
					$warnings[] = self::warning( 'invalid_name', $segment, 'could not split family and given name' );
					continue;
				}
				$rows[] = [
					'text' => $given . ' ' . $family,
					'familyFirst' => true,
					'original' => $segment,
				];
			} else {
				foreach ( self::splitAndWithin( $segment ) as $part ) {
					$rows[] = [ 'text' => $part, 'familyFirst' => false, 'original' => $part ];
				}
			}
		}
		return $rows;
	}

	/**
	 * @param array<int,array{code:string,message:string,name:string}> $warnings
	 * @return list<array{text:string,familyFirst:bool}>
	 */
	private static function splitComma( string $input, array &$warnings ): array {
		$segments = array_values( array_filter(
			array_map( 'trim', explode( ',', $input ) ),
			static fn ( string $s ): bool => $s !== ''
		) );
		if ( $segments === [] ) {
			return [];
		}
		// "Moro, Christian" — one comma between two single words is a
		// family-first author, not two mononyms.
		if ( count( $segments ) === 2
			&& self::tokenCount( $segments[0] ) === 1
			&& self::tokenCount( $segments[1] ) === 1
		) {
			$warnings[] = self::warning(
				'family_first_single_comma',
				$input,
				'interpreted as one family-first author; use semicolons to separate multiple authors'
			);
			return [ [
				'text' => $segments[1] . ' ' . $segments[0],
				'familyFirst' => true,
				'original' => $input,
			] ];
		}
		// More than two all-single-word segments: likely Family, Given
		// pairs. Pair them and flag for confirmation; the alternative (a
		// comma-separated list of mononyms) is rare and the warning lets the
		// user correct it.
		$allSingle = count( $segments ) > 2;
		foreach ( $segments as $segment ) {
			if ( self::tokenCount( $segment ) !== 1 ) {
				$allSingle = false;
				break;
			}
		}
		if ( $allSingle && count( $segments ) % 2 === 0 ) {
			$warnings[] = self::warning(
				'ambiguous_family_first',
				$input,
				'interpreted as Family, Given pairs; use semicolons between authors to be explicit'
			);
			$rows = [];
			for ( $i = 0; $i < count( $segments ); $i += 2 ) {
				$rows[] = [
					'text' => $segments[$i + 1] . ' ' . $segments[$i],
					'familyFirst' => true,
					'original' => $segments[$i] . ', ' . $segments[$i + 1],
				];
			}
			return $rows;
		}

		$rows = [];
		foreach ( $segments as $segment ) {
			$segment = self::stripNonName( $segment, $warnings );
			if ( $segment === '' ) {
				continue;
			}
			foreach ( self::splitAndWithin( $segment ) as $part ) {
				$rows[] = [ 'text' => $part, 'familyFirst' => false, 'original' => $part ];
			}
		}
		return $rows;
	}

	/**
	 * Splits a comma/semicolon segment on " and " / " & ". Used when a
	 * bibliography mixes commas with a final "&" ("A, B, C & D"). A
	 * family-first segment (one containing a comma) is left whole.
	 *
	 * @return string[]
	 */
	private static function splitAndWithin( string $segment ): array {
		// A conjunction can lead a comma/semicolon segment
		// ("A, B, and C" / "A, & B"): drop the leading word before any
		// further split.
		$segment = (string)preg_replace( '/^(?:and|&)\s+/iu', '', trim( $segment ) );
		if ( str_contains( $segment, ',' ) ) {
			return [ $segment ];
		}
		$parts = preg_split( '/\s+(?:and|&)\s+/iu', $segment );
		if ( $parts === false || count( $parts ) <= 1 ) {
			return [ $segment ];
		}
		return array_values( array_filter(
			array_map( 'trim', $parts ),
			static fn ( string $part ): bool => $part !== ''
		) );
	}

	/**
	 * @param array<int,array{code:string,message:string,name:string}> $warnings
	 * @return list<array{text:string,familyFirst:bool}>
	 */
	private static function splitAndOrSingle( string $input, array &$warnings ): array {
		$parts = preg_split( '/\s+(?:and|&)\s+/iu', $input );
		if ( $parts === false || count( $parts ) <= 1 ) {
			$input = self::stripNonName( $input, $warnings );
			return $input === ''
				? []
				: [ [ 'text' => $input, 'familyFirst' => false, 'original' => $input ] ];
		}
		$rows = [];
		foreach ( $parts as $part ) {
			$part = trim( $part );
			$part = self::stripNonName( $part, $warnings );
			if ( $part !== '' ) {
				$rows[] = [ 'text' => $part, 'familyFirst' => false, 'original' => $part ];
			}
		}
		return $rows;
	}

	/**
	 * Builds one person name from a segment. A family-first segment arrives
	 * as "given family" (the caller already reordered it), so the same
	 * last-word rule applies. Generational suffixes are kept with the
	 * family.
	 *
	 * @param array<int,array{code:string,message:string,name:string}> $warnings
	 * @return array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>}|null
	 */
	private static function buildName(
		string $text,
		bool $familyFirst,
		array &$warnings,
		?string $original = null,
		array $organizations = []
	): ?array {
		$original = trim( $original ?? $text );
		if ( $original === '' ) {
			return null;
		}
		$flags = [];
		if ( $familyFirst ) {
			$flags[] = 'family_first';
		}
		$text = trim( $text );
		$cleaned = self::stripParenthetical( $text, $flags, $warnings );
		if ( $cleaned === '' ) {
			$cleaned = $text;
		}
		if ( isset( $organizations[$cleaned] ) ) {
			$org = $organizations[$cleaned];
			return [
				'original' => $org['original'],
				'givenName' => '',
				'familyName' => $org['name'],
				'familyFirst' => false,
				'flags' => [],
				'kind' => 'collective',
			];
		}
		if ( self::isCjk( $cleaned ) ) {
			$flags[] = 'cjk';
			$warnings[] = self::warning(
				'cjk_name',
				$original,
				'CJK name detected; confirm the given/family split'
			);
		}

		$tokens = preg_split( '/\s+/u', $cleaned ) ?: [];
		$tokens = array_values( array_filter( $tokens, static fn ( string $t ): bool => $t !== '' ) );
		if ( $tokens === [] ) {
			return null;
		}
		if ( count( $tokens ) === 1 ) {
			$flags[] = 'mononym';
			$warnings[] = self::warning( 'mononym', $original, 'single-word name; confirm it is a person' );
			return [
				'original' => $original,
				'givenName' => '',
				'familyName' => $tokens[0],
				'familyFirst' => $familyFirst,
				'flags' => $flags,
				'kind' => 'person',
			];
		}

		$family = array_pop( $tokens );
		if ( self::isSuffix( $family ) && $tokens !== [] ) {
			$family = array_pop( $tokens ) . ' ' . $family;
			$flags[] = 'generational_suffix';
		}
		$given = implode( ' ', $tokens );
		return [
			'original' => $original,
			'givenName' => $given,
			'familyName' => $family,
			'familyFirst' => $familyFirst,
			'flags' => $flags,
			'kind' => 'person',
		];
	}

	/**
	 * Drops a trailing "et al." / "and Contributors" and flags it. The
	 * whole-segment non-name case returns ''.
	 *
	 * @param array<int,array{code:string,message:string,name:string}> $warnings
	 */
	private static function stripNonName( string $segment, array &$warnings ): string {
		$lower = mb_strtolower( trim( $segment ) );
		if ( in_array( $lower, self::NON_NAMES, true ) ) {
			$warnings[] = self::warning(
				'non_name',
				$segment,
				'ignored a non-person segment (et al. / contributors)'
			);
			return '';
		}
		$patterns = [
			'/\s+et\s+al\.?$/iu' => 'et_al',
			'/\s+and\s+contributors?$/iu' => 'contributors',
			'/\s*&\s*contributors?$/iu' => 'contributors',
		];
		foreach ( $patterns as $pattern => $code ) {
			if ( preg_match( $pattern, $segment ) === 1 ) {
				$warnings[] = self::warning( $code, $segment, 'trailing non-person text ignored' );
				return (string)preg_replace( $pattern, '', $segment );
			}
		}
		return $segment;
	}

	/**
	 * Removes ALL parenthetical groups from the raw input and flags each
	 * one. Run before the author separators are applied, because the
	 * alternate inside the parentheses may itself contain commas/semicolons.
	 *
	 * @param array<int,array{code:string,message:string,name:string}> $warnings
	 */
	private static function stripParentheticalGroups( string $input, array &$warnings ): string {
		if ( preg_match_all( '/\([^()]*\)/u', $input, $matches ) > 0 ) {
			foreach ( $matches[0] as $group ) {
				$warnings[] = self::warning(
					'parenthetical',
					$group,
					'parenthetical alternate removed; confirm the primary name'
				);
			}
			$input = (string)preg_replace( '/\([^()]*\)/u', ' ', $input );
			$input = self::normalizeWhitespace( $input );
		}
		return $input;
	}

	/**
	 * Removes a trailing parenthetical alternate ("Satoshi Watanabe (…)"):
	 * the primary name is kept, the alternate is flagged for review.
	 *
	 * @param string[] $flags
	 * @param array<int,array{code:string,message:string,name:string}> $warnings
	 */
	private static function stripParenthetical( string $text, array &$flags, array &$warnings ): string {
		if ( preg_match( '/^(.*?)\s*\([^()]*\)\s*$/u', $text, $m ) === 1 && trim( $m[1] ) !== '' ) {
			$flags[] = 'parenthetical';
			$warnings[] = self::warning(
				'parenthetical',
				$text,
				'parenthetical alternate removed; confirm the primary name'
			);
			return trim( $m[1] );
		}
		return $text;
	}

	private static function isSuffix( string $token ): bool {
		return in_array( mb_strtolower( rtrim( $token, '.' ) ), array_map(
			static fn ( string $s ): string => rtrim( $s, '.' ),
			self::SUFFIXES
		), true );
	}

	private static function tokenCount( string $text ): int {
		$tokens = preg_split( '/\s+/u', trim( $text ) ) ?: [];
		return count( array_filter( $tokens, static fn ( string $t ): bool => $t !== '' ) );
	}

	private static function isCjk( string $text ): bool {
		return preg_match( '/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text ) === 1;
	}

	private static function normalizeWhitespace( string $input ): string {
		$input = str_replace( [ "\u{00A0}", "\u{2007}", "\u{202F}" ], ' ', $input );
		$input = (string)preg_replace( '/\s+/u', ' ', trim( $input ) );
		return $input;
	}

	/** @return array{code:string,message:string,name:string} */
	private static function warning( string $code, string $name, string $message ): array {
		return [ 'code' => $code, 'message' => $message, 'name' => $name ];
	}

}
