# Decision: Upload enhancements — Special:Upload, Add\* portrait/logo fields, Wikimedia 429 fix

- **Status**: Accepted (Aug 24 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:Upload`, the Add\* portrait/logo
  sections, the fetch layer, and the image vocabulary
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Three editor-facing problems: (a) fetching an image URL from Wikipedia drew a
Wikimedia **429 rate-limit block** (`There was a problem during the HTTP
request: 429 Too many requests … fceb99d`) — the instance's shared
Oracle-Cloud IP is rate-limited by WMF after bursts; (b) users disliked
naming files and writing descriptions by hand; (c) the licensing options on
`Special:Upload` were too limited, and CC BY licenses need author + license
attribution that the form could not capture.

## Decision

### 1. Wikimedia requests: browser-side for metadata, politeness server-side

- **Metadata**: the "Validate" step fetches Wikimedia metadata **from the
  user's browser** — `commons.wikimedia.org/w/api.php?origin=*` (CORS-open,
  keyless). The request leaves from a residential IP, so the shared
  Oracle-Cloud IP never touches Wikimedia for metadata. Non-Wikimedia hosts
  (no CORS) use the login-gated `api.php?action=uploadmeta`
  (SSRF-guarded: SsrfGuard literals + `rejectLocalUrls` transport, capped
  probe). The browser path falls back to the server API on failure.
- **Image bytes**: a Wikimedia URL-mode upload is converted at submit time
  into a plain **file upload by the browser** (bytes fetched client-side,
  re-posted as a file) — the server-side `UploadFromUrl` download of a
  Wikimedia URL is eliminated entirely, making the 429 impossible for the
  host class that caused it. Non-Wikimedia URL uploads keep the server-side
  `UploadFromUrl` path (throttled).
- **Server politeness** (for the remaining server-side WMF calls): new
  `RateLimitedHttpClient` decorator enforces a per-host-group minimum
  interval (WMF hosts share one bucket — WMF rate-limits across its API
  family) and retries 429 with `Retry-After`/exponential backoff. The whole
  provider cascade + Wikipedia content fetches run through it.
- **No API key**: Wikimedia's APIs are keyless; a descriptive User-Agent
  (already sent) + the throttling/browser-side measures are the compliant
  fix. The "apply for an API key if needed" premise does not apply.
- **Blob-size guard**: the browser-blob path is limited client-side to 100 MB
  (`MAX_BLOB_BYTES`, matched to `$wgMaxUploadSize['url']`); larger Wikimedia
  images show a guided "save it to your device and upload it" message.
  `$wgMaxUploadSize` is per-key (MW 1.46 array form: `'*'` wildcard for the
  general default — without it `getMaxUploadSize()` returns null and siteinfo
  `maxuploadsize` is null): file uploads stay at 1 GiB, URL uploads are
  capped at 100 MB (the browser-blob fallback re-posts as a file upload, and
  `UploadFromUrl` honours the same URL cap).

### 2. Attribution storage: new string properties (the Add\* semantic model)

- New vocabulary: **`image author`** (string, P2093-aligned "author name
  string") and **`additional license information`** (string, unaligned — no
  Wikidata match for free-text license notes, `bookExcerpt` precedent), plus
  the **`image`** class for the item-per-upload.
- **Add\* pages**: the portrait/logo statements (`image`, `license`,
  `imageAuthor`, `imageLicenseInfo`) land on the person/software item —
  queryable via SPARQL, consistent with the existing `image`/`license`
  statements.
- **Special:Upload**: every form submission creates/reuses a **sitelinked
  image item** (class `image`) holding the same statements — the Add\*
  model for files ("facts in the item"). The File page carries the
  human-readable Attribution section (author / license info / source URL);
  the license renders as a `[[Q42|label]]` reference, never a `{{Q42}}`
  template call. Marker-gated: MsUpload drag-drop and API uploads are
  untouched (a bare file without attribution stays a bare file).

### 3. UX

- `Special:Upload`: the core `MediaWiki:Licenses` dropdown is replaced by
  the **semantic license combobox** (preseed license items + entity search);
  new **image author** + **additional license information** fields; the
  duplicated "Maximum file size: 1 GB (your chosen file from your device)"
  notes collapse to a **single** note (the URL field's note slot carries
  the validate-button wiring instead).
- Add\* portrait/logo sections: collapsed behind an **"I will upload a
  {portrait/logo image} for this {entity}"** toggle; free-text author +
  license-info fields; the **validate button** on the URL fields (preview
  thumbnail + pixel + byte size, best-effort autofill).
- AddSoftware's logo gains the previously-missing **license field**
  (mandatory when a logo is provided, same rule as the portrait).
- The logo/portrait machinery is **deduplicated** into the shared
  `ImageUploadHelper` (~560 lines of private methods deleted from
  AddPerson/AddSoftware).

## What this does NOT do (and why)

- **No MediaInfo**: File pages cannot hold item statements on this instance
  (no WikibaseMediaInfo); the item-per-upload mirrors the Add\* classic-page
  pattern instead.
- **No server-side Wikimedia metadata fetch as the primary path**: the
  browser-side path is the point of the fix; the server API remains as the
  fallback and for non-Wikimedia hosts.
- **No automatic item for MsUpload/API uploads**: those paths carry no
  license/attribution form values; itemizing them would mint bare items.
- **No new properties for Special:Upload's File page itself** — the item
  holds the queryable facts; the page holds the prose (the Add\* split).

## Consequences

- **Vocabulary (data, not code)**: +2 properties (`image author` P2093,
  `additional license information` unaligned), +1 class (`image`); config
  map: `personProperties`/`fossProperties` += `imageAuthor`/`imageLicenseInfo`,
  new `imageClasses`/`imageProperties` sections.
- **Fetch layer**: `ProviderException` carries status + `Retry-After`; the
  provider cascade and Wikipedia content fetches are rate-limited.
- **Browser CORS**: relies on Wikimedia's `origin=*` (API) and
  `Access-Control-Allow-Origin: *` (image CDN) — both verified live.
- **MsUpload coexistence**: production-only third-party extension (not in
  dev/CI); coexistence must be verified live at deploy (its own panel's size
  note and drag-drop bypass are untouched by this change).
- **Deployment**: D1 importers (2 properties + 1 class) + seed config
  re-emission (full vocabulary incl. `dogfood`; YouTube key preservation);
  LocalSettings `$wgMaxUploadSize` per-key (file 1 GiB, url 100 MB);
  on-wiki contributor guidance updated.

## Follow-up fixes (same batch, Aug 25 2026)

Two of the batch's fixes landed in follow-up because the first deploy left a
module-loading gap and a case-comparison bug:

- **Special:Upload module loading**: the `ext.embeddableContent.entitysuggest`
  and `ext.embeddableContent.uploadmeta` modules were only loaded on the Add\*
  pages (`SpecialAddExternalEntity::execute`); Special:Upload rendered the
  wiring span but never the JS that makes it functional — the validate button
  never appeared and the Wikimedia metadata/autofill never ran. Both modules
  are now loaded on Special:Upload via `BeforePageDisplay`
  (`Hooks::onBeforePageDisplay`, `$title->isSpecial('Upload')`).
- **Blob-fallback case comparison**: the submit-time URL-mode check compared
  the checked radio value against the literal `'url'`, but Special:Upload's
  core radios are `Url`/`File` (the Add\* pages use lowercase `file`/`url`) —
  so the Wikimedia URL→browser-file conversion never fired on Special:Upload
  and the server still downloaded the Wikimedia bytes (the `fceb99d` 429).
  The comparison is now case-normalised.
- **URL upload cap**: URL uploads are capped at 100 MB (per-key
  `$wgMaxUploadSize['url']` with the `'*'` wildcard kept at 1 GiB — MW 1.46's
  array form needs `'*'` for the general default; the browser-blob guard
  `MAX_BLOB_BYTES` matches; the URL field shows its own size note). The
  original batch shipped a 50 MB client-side guard with a 1 GB server cap —
  inconsistent with the 100 MB PHP/URL intent.
- **License combobox "native" formatting**: with `entitysuggest` loaded on
  Special:Upload the license combobox gains the same entity autocomplete
  (wbsearchentities, `Q42 — Label (description)` options) as the Add\*
  pages — the previously plain OOUI combobox.

## Field-type audit (requested in the same batch)

Audited all Add\* review/manual fields against "if something can be an
entity, the type should be entity": **no mis-typed fields remain**. Place of
birth/death (entity comboboxes), authors/publisher/journal/parent/license/
appears-in (entity comboboxes), dates (time), external ids (strings), year
(text → year-precision date statement), duration (text → quantity) — all
correct. The places item in the todo was already implemented (2026-08-24,
PR #34); this ADR records the audit outcome.

## Follow-up fixes round 2 (Aug 25 2026, PR #51)

Three reported bugs on `Special:Upload` + the Add\* file-page wording:

- **Fetched summary truncated at 250 chars**: `CommonsMetadataParser::TEXT_CAP`
  and the JS `cleanText` mirror both capped the fetched description at 250
  while the term limit was raised to 2000 (`string-limits` length,
  `wbt_text` VARBINARY(2000)) — the NGS example (986-char `ImageDescription`)
  was cut mid-sentence at exactly 250. The description now uses a separate
  `DESCRIPTION_CAP = 2000` (PHP + JS mirror) with **sentence-boundary-aware
  truncation** (a >2000-char summary never ends mid-sentence — cut at the
  last `. ` / `! ` / `? ` inside the cap). `ImageItemCreator`'s
  item-description cap raised 250 → 2000 so the item and the File page agree.
  Short text fields (author/credit/license) stay at 250 (form maxlengths).
- **Destination file name not normalized**: the fetched Commons `ObjectName`
  (`National Geographic Society Administration Building`) was written
  verbatim into `wpDestFile`. New `normalizeDestName()` (PHP + JS mirror)
  applies to the Special:Upload name autofill: lowercase, **any word
  separator** — spaces, underscores, camelCase/PascalCase boundaries,
  existing dashes, MediaWiki-illegal filename chars (`#<>[]|{}:`) — collapses
  to a single dash (unicode-aware, `\p{L}\p{N}`, so `École Nationale
  Supérieure` → `école-nationale-supérieure`), and preserves a trailing
  (lowercased) extension. MediaWiki core appends the extension from MIME at
  `verifyFilename` when the name has none, so the result lands as
  `national-geographic-society-administration-building.jpg`. (PR #52 extended
  the PR #51 normalization from spaces-only to every separator shape.)
- **Wikimedia 429 (fceb99d) still fired on submit — root cause found**: the
  submit handler passed the **full URL** to `isWikimediaHost()`, which
  expects a **hostname** (`host.endsWith('.wikimedia.org')`). A file URL
  never ends with `.wikimedia.org`, so the check was always false, the
  browser-blob fallback (Wikimedia URL → browser-supplied file) never fired,
  and the server-side `UploadFromUrl` downloaded the bytes from the
  rate-limited shared IP → the 429. The handler now parses
  `new URL(url).hostname` first. This fixes Special:Upload **and** the Add\*
  portrait/logo URL fields, which share the same handler.
- **Add\* file-page wording simplified**: `ImageUploadHelper::pageText()`
  drops the "uploaded via Special:AddPerson/AddSoftware" suffix —
  `Portrait of X.` / `Logo of X.` (the `viaPage` msg-key plumbing removed).
  The AddSource access-file message (`embeddablecontent-source-access-file-page`)
  updated the same way in en/fr/eo.

Regression coverage: PHPUnit 346 (new `CommonsMetadataParser` cases for the
2000 cap, the sentence-boundary cut, and `normalizeDestName`); the page-flow
E2E gains a served-module-source guard (`flow_uploadmeta_module_source`) that
asserts the uploaded `uploadmeta.js` contains the hostname parse, the 2000
cap and `normalizeDestName` — the JS-side fixes a curl E2E cannot execute.

## Follow-up fixes round 3 (Aug 25 2026, PR #54)

Three further bugs from live testing:

- **Blob-fallback resubmit dropped the submit button**: native `$form[0].submit()`
  does not include the submit button's name/value; Special:Upload's core gates
  processing on `getCheck('wpUpload')` (`UploadForm::setSubmitName('wpUpload')`),
  so the converted Wikimedia→file resubmit re-rendered the form ("page
  refreshes, nothing uploaded"). The fallback now replicates the form's
  submit button(s) as hidden fields before the native resubmit.
- **Raw `help` message key**: MW 1.46 treats `'help'` as raw HTML (deprecated
  since 1.43) — the license combobox passed a message KEY, rendering the bare
  key string. Switched to `'help-message'` in `UploadHooks` and
  `ImageUploadHelper::licenseField`.
- **All file types**: `UploadMetadataFetcher::probeGeneric` accepts non-image
  MIME (MIME + byte size for PDF/video/audio; pixel dims only for images); the
  validate preview is image-gated; the Special:Upload `Image author` label →
  `Author` (en/fr/eo).
