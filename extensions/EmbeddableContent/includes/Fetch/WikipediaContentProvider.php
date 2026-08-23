<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * English Wikipedia content provider for the Source:/Person: page-content
 * fetch: the lead intro (REST summary extract) and a named section from the
 * article wikitext (e.g. "Plot" for films, "Lyrics" for songs).
 *
 * SSRF-safe by construction: the host is FIXED (en.wikipedia.org) and only
 * the article title travels in the path/query — the caller must hand this
 * provider an HttpClientInterface whose allowlist covers en.wikipedia.org
 * (e.g. new CurlHttpClient( [ 'en.wikipedia.org' ] )).
 *
 * Best-effort contract: any failure yields null, never an exception to the
 * caller.
 *
 * @license GPL-2.0-or-later
 */
final class WikipediaContentProvider {

	private const API = 'https://en.wikipedia.org/w/api.php';
	private const SUMMARY = 'https://en.wikipedia.org/api/rest_v1/page/summary/';

	private HttpClientInterface $http;
	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 6.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	/**
	 * Lead-section intro of an article (REST summary `extract`), or null.
	 */
	public function intro( string $title ): ?string {
		try {
			$data = $this->http->getJson(
				self::SUMMARY . rawurlencode( str_replace( ' ', '_', $title ) ),
				[],
				$this->timeout,
				524288
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		$extract = $data['extract'] ?? null;
		if ( !is_string( $extract ) ) {
			return null;
		}
		$extract = trim( $extract );
		return $extract !== '' ? $extract : null;
	}

	/**
	 * The first level-2 section whose heading is one of $headings (case-
	 * insensitive, e.g. [ 'Plot', 'Synopsis' ]), including its subsections;
	 * null when no such section exists. Templates/refs/comments are
	 * stripped; the result is wikitext safe to embed on a page.
	 *
	 * @param string[] $headings
	 */
	public function section( string $title, array $headings ): ?string {
		try {
			$data = $this->http->getJson( self::API, [
				'action' => 'query',
				'prop' => 'revisions',
				'rvprop' => 'content',
				'rvslots' => 'main',
				'formatversion' => 2,
				'format' => 'json',
				'titles' => $title,
			], $this->timeout, 3145728 );
		} catch ( \Throwable $e ) {
			return null;
		}
		$content = $data['query']['pages'][0]['revisions'][0]['slots']['main']['content'] ?? null;
		if ( !is_string( $content ) || $content === '' ) {
			return null;
		}
		$pattern = implode( '|', array_map(
			static fn ( string $h ): string => preg_quote( $h, '/' ),
			$headings
		) );
		if ( preg_match( '/^==\s*(?:' . $pattern . ')\s*==\s*\n?(.*?)(?=\n==[^=]|\z)/msi', $content, $m ) !== 1 ) {
			return null;
		}
		return $this->cleanSection( $m[1] );
	}

	/** Strips comments, refs and templates; returns null when nothing is left. */
	private function cleanSection( string $wikitext ): ?string {
		$wikitext = (string)preg_replace( '/<!--.*?-->/s', '', $wikitext );
		$wikitext = (string)preg_replace( '/<ref\b[^>]*\/>/i', '', $wikitext );
		$wikitext = (string)preg_replace( '/<ref\b[^>]*>.*?<\/ref>/is', '', $wikitext );
		// Templates can nest one level deep — remove innermost first.
		$prev = '';
		while ( $prev !== $wikitext ) {
			$prev = $wikitext;
			$wikitext = (string)preg_replace( '/\{\{[^{}]*\}\}/s', '', $wikitext );
		}
		$wikitext = trim( (string)preg_replace( '/\s+/u', ' ', $wikitext ) );
		return $wikitext !== '' ? $wikitext : null;
	}
}
