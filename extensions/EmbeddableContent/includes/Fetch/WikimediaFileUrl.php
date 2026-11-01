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
	 * Original CDN URL of a Wikimedia THUMBNAIL rendition whose original file
	 * is an SVG, or null when the URL is not such a rendition.
	 *
	 * Wikimedia serves a raster rendition of a vector file under
	 * `/thumb/…/<Name>.svg/<width>px-<Name>.svg.png`. Fetching that PNG and
	 * uploading it under the ".svg" destination name derived from the file
	 * title is a MIME mismatch (`filetype-mime-mismatch`), and the PNG also
	 * scales worse than the vector original. The original lives at the same
	 * path without `/thumb/` and the trailing rendition segment:
	 *
	 *   https://upload.wikimedia.org/wikipedia/commons/thumb/9/96/Foo.svg/960px-Foo.svg.png
	 *   → https://upload.wikimedia.org/wikipedia/commons/9/96/Foo.svg
	 *
	 * The canonical CDN host is returned (`thumb.wikimedia.org` redirects
	 * originals to `upload.wikimedia.org`). Raster thumbnails, already-original
	 * URLs and non-Wikimedia hosts return null — the caller keeps its URL.
	 *
	 * Mirrored client-side in resources/uploadmeta.js (`originalSvgUrl`).
	 */
	public static function originalSvgUrl( string $url ): ?string {
		if ( !self::isWikimediaHost( $url ) ) {
			return null;
		}
		$path = (string)( parse_url( $url, PHP_URL_PATH ) ?? '' );
		$segments = array_values( array_filter(
			explode( '/', $path ),
			static fn ( string $segment ): bool => $segment !== ''
		) );
		// The CDN shape is /wikipedia/<repo>/thumb/<a>/<ab>/<Name>.<ext>/<rendition>.
		$thumbIndex = array_search( 'thumb', $segments, true );
		if (
			$thumbIndex === false
			|| $thumbIndex < 2
			|| ( $segments[ $thumbIndex - 2 ] ?? '' ) !== 'wikipedia'
			|| count( $segments ) < $thumbIndex + 4
		) {
			return null;
		}
		$originalName = $segments[ $thumbIndex + 3 ];
		if ( strtolower( (string)pathinfo( $originalName, PATHINFO_EXTENSION ) ) !== 'svg' ) {
			return null;
		}
		$originalSegments = array_merge(
			array_slice( $segments, 0, $thumbIndex ),
			array_slice( $segments, $thumbIndex + 1, 3 )
		);
		if ( $originalSegments === [] ) {
			return null;
		}
		return 'https://upload.wikimedia.org/' . implode( '/', $originalSegments );
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
	 * True when the URL is a Wikimedia FILE DESCRIPTION PAGE — an HTML page
	 * (`…/wiki/File:X`, `…/wiki/Special:FilePath/X`) rather than the file's
	 * own bytes. Fetching such a URL returns text/html, so the upload path
	 * must resolve the direct file URL first (UploadMetadataFetcher::
	 * resolveDirectFileUrl / the browser `resolveBytesUrl` mirror).
	 */
	public static function isFilePage( string $url ): bool {
		if ( !self::isWikimediaHost( $url ) ) {
			return false;
		}
		$path = (string)( parse_url( $url, PHP_URL_PATH ) ?? '' );
		return preg_match( '#^/wiki/File:[^/]+$#i', $path ) === 1
			|| preg_match( '#^/wiki/Special:FilePath/[^/]+$#i', $path ) === 1;
	}

	/**
	 * The API endpoint that serves `imageinfo` for a Wikimedia URL, or null
	 * when no file title can be extracted:
	 *
	 *  - a file DESCRIPTION PAGE queries its OWN wiki's API — that resolves
	 *    a Commons-hosted file AND a locally hosted one (a non-free file on
	 *    en.wikipedia lives at `upload.wikimedia.org/wikipedia/en/…`, which
	 *    the Commons API cannot see);
	 *  - a CDN URL (`upload.wikimedia.org`, `thumb.wikimedia.org`) has no
	 *    wiki of its own in the URL — the shared repository's API (Commons)
	 *    answers for the file title.
	 */
	public static function fileApi( string $url ): ?string {
		if ( !self::isWikimediaHost( $url ) ) {
			return null;
		}
		$host = strtolower( (string)( parse_url( $url, PHP_URL_HOST ) ?? '' ) );
		if ( $host === 'upload.wikimedia.org' || str_ends_with( $host, '.upload.wikimedia.org' )
			|| $host === 'thumb.wikimedia.org' || str_ends_with( $host, '.thumb.wikimedia.org' )
		) {
			return 'https://commons.wikimedia.org/w/api.php';
		}
		if ( self::isFilePage( $url ) ) {
			return 'https://' . $host . '/w/api.php';
		}
		return null;
	}

	/**
	 * The `imageinfo` query target for a URL (the API endpoint + the decoded
	 * file title), or null when the URL is not a Wikimedia file reference.
	 *
	 * @return array{api: string, title: string}|null
	 */
	public static function fileQuery( string $url ): ?array {
		$api = self::fileApi( $url );
		$title = self::fileTitle( $url );
		if ( $api === null || $title === null ) {
			return null;
		}
		return [
			'api' => $api,
			'title' => $title,
		];
	}

	/** "File:foo_bar.jpg" → "File:foo bar.jpg" (underscores are spaces in titles). */
	private static function normalizeTitle( string $title ): string {
		$title = preg_replace( '/\s+/', ' ', str_replace( '_', ' ', trim( $title ) ) );
		return $title === '' || $title === null ? '' : $title;
	}
}
