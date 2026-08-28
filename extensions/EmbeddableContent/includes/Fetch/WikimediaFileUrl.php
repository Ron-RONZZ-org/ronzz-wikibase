<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Pure URL helpers for Wikimedia-hosted files (the "special handling logic"
 * for the upload validate flow).
 *
 * Wikimedia's API (`*.wikipedia.org` / `*.wikimedia.org` `api.php?origin=*`)
 * and its image CDN (`upload.wikimedia.org`) are CORS-open, so the BROWSER
 * can fetch the metadata and the image bytes directly — the instance's
 * shared Oracle-Cloud IP never touches Wikimedia, which sidesteps the
 * Wikimedia 429 rate-limit blocks (fceb99d) that a server-side fetch draws.
 *
 * This class only detects hosts and extracts file titles — pure, no I/O —
 * so the same logic is unit-testable and mirrored client-side in
 * resources/uploadmeta.js.
 *
 * @license GPL-2.0-or-later
 */
final class WikimediaFileUrl {

	/** Hosts whose API and image CDN are CORS-open and WMF rate-limited. */
	private const WIKIMEDIA_HOST_SUFFIXES = [ 'wikipedia.org', 'wikimedia.org', 'wikidata.org' ];

	/**
	 * True when the URL points at a Wikimedia host (wikipedia.org /
	 * wikimedia.org family).
	 */
	public static function isWikimediaHost( string $url ): bool {
		$host = strtolower( (string)parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' ) {
			return false;
		}
		foreach ( self::WIKIMEDIA_HOST_SUFFIXES as $suffix ) {
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Best-effort Commons file title ("File:Example.jpg") for a Wikimedia
	 * URL, or null when the shape is not a file reference:
	 *
	 *  - /wiki/File:X            (commons.wikimedia.org, any *wikipedia.org)
	 *  - /wiki/Special:FilePath/X.jpg
	 *  - upload.wikimedia.org/wikipedia/commons/…/X.jpg          (original)
	 *  - upload.wikimedia.org/wikipedia/commons/thumb/…/X.jpg/220px-X.jpg
	 *
	 * The `upload.wikimedia.org/wikipedia/<lang>/…` paths (locally-hosted
	 * non-free files) are NOT resolved here — the Commons API query would
	 * miss them; the caller falls back to the generic probe.
	 */
	public static function fileTitle( string $url ): ?string {
		$parts = parse_url( $url );
		$path = $parts['path'] ?? '';
		if ( $path === '' ) {
			return null;
		}

		// /wiki/File:X and /wiki/Special:FilePath/X.jpg (the FilePath variant
		// carries no "File:" prefix — its title is the bare file name).
		if ( preg_match( '#^/wiki/File:[^/]+$#i', $path ) === 1 ) {
			return self::normalizeTitle( self::decodeSegment( substr( $path, 6 ) ) );
		}
		if ( preg_match( '#^/wiki/Special:FilePath/([^/]+)$#i', $path, $m ) === 1 ) {
			return self::normalizeTitle( 'File:' . self::decodeSegment( $m[1] ) );
		}

		$host = strtolower( (string)( $parts['host'] ?? '' ) );
		if ( $host === 'upload.wikimedia.org' || str_ends_with( $host, '.upload.wikimedia.org' ) ) {
			// The image CDN: …/wikipedia/commons/…/X.jpg — the LAST path
			// segment is the file name (with .jpg extension); a /thumb/ URL
			// carries the real name in the SECOND-TO-LAST segment
			// (…/X.jpg/220px-X.jpg).
			if ( strpos( $path, '/thumb/' ) !== false ) {
				$segments = array_values( array_filter( explode( '/', $path ) ) );
				$name = count( $segments ) >= 2 ? $segments[count( $segments ) - 2] : null;
			} else {
				$name = basename( $path );
			}
			if ( $name === null || $name === '' ) {
				return null;
			}
			return self::normalizeTitle( 'File:' . self::decodeSegment( $name ) );
		}

		return null;
	}

	/**
	 * Percent-decodes one URL path segment. `rawurldecode` is deliberate:
	 * path segments encode spaces as %20, NOT `+` (a literal plus in a file
	 * name must survive), and malformed sequences are left untouched rather
	 * than throwing — a broken URL still yields a best-effort title.
	 *
	 * Without this, an encoded file name ("Magnus-manske-2024_%28cropped
	 * %29.jpg") was sent to the Commons API with the literal "%28"/"%29",
	 * which the API then re-decoded into a title that does not exist — the
	 * metadata fetch fell back to the generic probe and drew Wikimedia's
	 * server-side 429/403 ("fetch failed: HTTP http-bad-status").
	 */
	private static function decodeSegment( string $segment ): string {
		return rawurldecode( $segment );
	}

	/**
	 * The Commons API endpoint for a URL's file title, or null when no title
	 * can be extracted. Metadata for Wikimedia files is always read from
	 * COMMONS (upload.wikimedia.org serves the shared repository; the
	 * language wikis' File: pages for free images redirect there).
	 *
	 * @return array{api: string, title: string}|null
	 */
	public static function commonsQuery( string $url ): ?array {
		$title = self::fileTitle( $url );
		if ( $title === null ) {
			return null;
		}
		return [
			'api' => 'https://commons.wikimedia.org/w/api.php',
			'title' => $title,
		];
	}

	/** "File:foo_bar.jpg" → "File:foo bar.jpg" (underscores are spaces in titles). */
	private static function normalizeTitle( string $title ): string {
		$title = preg_replace( '/\s+/', ' ', str_replace( '_', ' ', trim( $title ) ) );
		return $title === '' || $title === null ? '' : $title;
	}
}
