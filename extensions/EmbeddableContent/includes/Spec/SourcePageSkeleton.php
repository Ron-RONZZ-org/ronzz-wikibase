<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * The classic Source: page body, shared by the browser AddSource form and the
 * API/MCP `action=addsource` create path (ClassicPageCreator).
 *
 * The page carries the prose (abstract, summary, keywords, lyrics, plot,
 * intro, law clause) while the item carries the queryable facts. Sections are
 * rendered only when the record carries their content — no blank scaffolds.
 * An optional attribution line ("from wikipedia") is written above a fetched
 * block when `contentSources[key]` is present; API/MCP callers that supply
 * prose without a provenance source simply get the text.
 *
 * Pure: the caller supplies the template and a message lookup, so the same
 * body is unit-testable without MediaWiki.
 *
 * @license GPL-2.0-or-later
 */
final class SourcePageSkeleton {

	/** Content key => section heading, per form class key. */
	private const SECTION_HEADINGS = [
		'scholarlyArticle' => [ 'abstract' => 'Abstract', 'keywords' => 'Key words' ],
		'book' => [ 'summary' => 'Summary', 'keywords' => 'Key words' ],
		'song' => [ 'intro' => 'Overview', 'lyrics' => 'Lyrics' ],
		'film' => [ 'intro' => 'Overview', 'plot' => 'Plot' ],
		'video' => [ 'keywords' => 'Key words' ],
		'youtubeVideo' => [ 'keywords' => 'Key words' ],
		'webpage' => [ 'summary' => 'Summary', 'keywords' => 'Key words' ],
	];

	/**
	 * @param string $classKey form class key (scholarlyArticle, book, …)
	 * @param array<string,mixed> $record
	 * @param string $template the classic page template (without braces)
	 * @param callable(string):string $message message lookup (e.g. wfMessage()->text())
	 * @param string $pendingComment optional pending-marker comment text
	 */
	public static function render(
		string $classKey,
		array $record,
		string $template,
		callable $message,
		string $pendingComment = ''
	): string {
		$marker = $pendingComment !== ''
			? "\n<!-- " . $pendingComment . " -->\n"
			: '';
		if ( $classKey === 'law' ) {
			return '{{' . ( $template !== '' ? $template : 'Law' ) . "}}\n\n"
				. "== Text ==\n\n{{#content:}}\n\n" . $marker;
		}
		if ( $template === '' ) {
			return $marker;
		}
		$body = '{{' . $template . "}}\n\n";
		if ( in_array( $classKey, [ 'website', 'youtubeChannel' ], true ) ) {
			$intro = trim( (string)( $record['intro'] ?? '' ) );
			if ( $intro !== '' ) {
				$body .= self::attributed( $record, 'intro', $intro, $message ) . "\n\n";
			} else {
				$overview = trim( (string)( $record['description'] ?? '' ) );
				if ( $overview !== '' ) {
					$body .= $overview . "\n\n";
				}
			}
			return $body . $marker;
		}
		$rendered = false;
		foreach ( self::SECTION_HEADINGS[ $classKey ] ?? [] as $key => $heading ) {
			$content = trim( (string)( $record[$key] ?? '' ) );
			if ( $content === '' ) {
				continue;
			}
			$body .= "== {$heading} ==\n\n"
				. self::attributed( $record, $key, $content, $message ) . "\n\n";
			$rendered = true;
		}
		if ( !$rendered ) {
			$overview = trim( (string)( $record['description'] ?? '' ) );
			if ( $overview !== '' ) {
				$body .= "== Overview ==\n\n{$overview}\n\n";
			}
		}
		return $body . $marker;
	}

	/**
	 * @param array<string,mixed> $record
	 * @param callable(string):string $message
	 */
	private static function attributed( array $record, string $key, string $content, callable $message ): string {
		$source = $record['contentSources'][$key] ?? null;
		if ( $source === null ) {
			return $content;
		}
		return "''" . $message( 'embeddablecontent-content-from-' . $source ) . "''\n\n" . $content;
	}

}
