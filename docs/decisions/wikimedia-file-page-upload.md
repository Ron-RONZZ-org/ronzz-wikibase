# Decision: Wikimedia file DESCRIPTION pages resolve to the file for uploads

- **Status**: Accepted (Nov 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:Upload` URL mode and the Add\*/
  Update\* portrait/logo URL fields (`includes/Fetch/WikimediaFileUrl.php`,
  `includes/Fetch/UploadMetadataFetcher.php`,
  `includes/Fetch/CommonsMetadataParser.php`,
  `includes/Upload/ImageUploadHelper.php`, `resources/uploadmeta.js`)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A pasted Wikimedia **file description page** URL — e.g.
`https://en.wikipedia.org/wiki/File:Standard_Model_of_Elementary_Particles.svg`
— fetched its metadata but never uploaded the image.

The URL is an **HTML page**, not the file:

- The **Validate** step extracted the file title and queried
  `commons.wikimedia.org/w/api.php?prop=imageinfo`, so author/license/size
  filled in correctly, but `iiurlwidth` was never requested, so the response
  carried no `thumburl`. The preview fell back to `sourceUrl` (the HTML
  description page) and rendered a broken `<img>`.
- On submit, the browser blob fallback fetched the URL itself; the response
  is `text/html`, so the module's own HTML guard aborted with
  "browser fetch failed". With JS off (or on a non-browser client) the
  server-side `UploadFromUrl` fetched the same HTML and was rejected.
- The Commons-only API choice also missed **locally hosted** files (a
  non-free file on en.wikipedia lives under
  `upload.wikimedia.org/wikipedia/en/…`, which the Commons API cannot see).

Verified live (Nov 2026): the file's **own wiki** API returns
`imageinfo.url` (the direct `upload.wikimedia.org` bytes URL) and, with
`iiurlwidth=640`, `thumburl`; it answers for both Commons-hosted and locally
hosted files. Wikimedia API hosts and `upload.wikimedia.org` are CORS-open;
the `Special:FilePath` redirect chain is **not** (no
`Access-Control-Allow-Origin` on the hops), so redirect-following is not a
safe browser fix.

## Decision

### One URL-shape contract (`WikimediaFileUrl`)

- `isFilePage()` — `/wiki/File:X` or `/wiki/Special:FilePath/X` on a
  Wikimedia host.
- `fileApi()` / `fileQuery()` — a **file description page** queries **its own
  wiki's** `api.php` (Commons or the language wiki, which resolves a local
  file); a CDN URL (`upload.wikimedia.org`, `thumb.wikimedia.org`) still
  queries Commons, since the URL names no wiki. Replaces
  `commonsQuery()` (same shape, correct endpoint).
- `originalSvgUrl()` (unchanged) still resolves an SVG **thumbnail** URL to
  the vector original.

### Bytes URL resolution

- `ImageMetadata` gains `fileUrl` — the direct bytes URL (`imageinfo.url`),
  mapped from `CommonsMetadataParser::fromImageInfo()` and exposed on
  `action=uploadmeta`.
- `UploadMetadataFetcher::resolveDirectFileUrl( $url )` returns the direct
  URL for a file description page (one SSRF-guarded `imageinfo&iiprop=url`
  query) and **null** for a CDN URL or a non-Wikimedia URL — a no-op, no
  request.
- `fetch()` queries the file's own API and requests `iiurlwidth=640` (the
  preview thumbnail); when `imageinfo` yields nothing, the generic probe
  targets the resolved direct URL, not the HTML page.
- `ImageUploadHelper::uploadFromUrl()` (the Add\*/Update\* URL path, and the
  JS-off `Special:Upload` fallback) resolves the direct URL before
  `originalSvgUrl()` and `UploadFromUrl`, so the server never downloads HTML
  as an image.
- `resources/uploadmeta.js` mirrors all of it (the repo's one-way sync rule):
  `isFilePage`, `imageInfoTarget`, `resolveBytesUrl` — the same-wiki API,
  `iiurlwidth`, `fileUrl` in the meta, preview `thumbUrl || fileUrl ||
  sourceUrl`, and the submit-time blob fetch resolves first. The resolution
  is cached per URL field from a preceding Validate (one API call at most);
  a CDN URL and a Validate-cached URL trigger no lookup. The helpers are
  exposed on `mw.embeddableContent.uploadmeta` for the browser E2E.

The File-page **Source** line keeps the existing contract — "the URL the
bytes actually came from" — so it records the resolved direct URL (the
original SVG, or the CDN file), exactly like the SVG-thumbnail fix.

## Consequences

- `Special:Upload` and the Add\*/Update\* portrait/logo URL fields now upload
  the image from a Wikipedia/Wikimedia `File:` page; metadata still comes
  from the same page.
- A **locally hosted** (non-free) file now yields `url`/`mime`/size even
  though the API carries no `extmetadata` — the upload works, and author/
  license stay empty for the user to fill in.
- No vocabulary, config-map, LocalSettings or DB change. Deploy = extension
  rsync + php-fpm restart (no parser-cache purge; `onBeforePageDisplay` only).
  No new i18n keys.
- A file page whose file does not exist still falls back to probing the HTML
  page, which reports `text/html` — a genuine "not an image" case.
- **JS-off limitation**: core's `Special:Upload` URL mode calls
  `UploadFromUrl::fetchFile()` inside `processUpload()` BEFORE the
  `UploadForm:BeforeProcessing` hook, and core exposes no earlier URL-rewrite
  hook — so with JavaScript disabled a pasted file-page URL is still fetched
  as HTML. The browser blob path (the normal flow, and the path the 429 fix
  already required) is fixed; the Add\*/Update\* portrait/logo URL fields
  resolve server-side (`ImageUploadHelper::uploadFromUrl`).

## Tests

- `tests/Unit/Fetch/WikimediaFileUrlTest.php` — `isFilePage`, `fileQuery`
  per URL shape (own wiki vs Commons).
- `tests/Unit/Fetch/UploadMetadataFetcherTest.php` — a file page queries its
  own wiki API and returns `fileUrl`; `resolveDirectFileUrl` positive +
  no-op (no request) cases.
- `tests/e2e/run_wiki_ux_e2e.mjs` — the client module resolves a Wikipedia
  `File:` page to the `upload.wikimedia.org` URL with exactly one stubbed
  `imageinfo` request (cached + CDN paths resolve without a lookup).
