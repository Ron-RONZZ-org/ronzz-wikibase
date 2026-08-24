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
- **Blob-size guard**: the browser-blob path is limited client-side to 50 MB
  (`MAX_BLOB_BYTES`, matched to the JS); larger Wikimedia images show a
  guided "save it to your device and upload it" message. `$wgMaxUploadSize`
  is left at 1 GB — the server URL path still accepts up to 1 GB for
  non-Wikimedia hosts.

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
  no LocalSettings change; on-wiki contributor guidance updated.

## Field-type audit (requested in the same batch)

Audited all Add\* review/manual fields against "if something can be an
entity, the type should be entity": **no mis-typed fields remain**. Place of
birth/death (entity comboboxes), authors/publisher/journal/parent/license/
appears-in (entity comboboxes), dates (time), external ids (strings), year
(text → year-precision date statement), duration (text → quantity) — all
correct. The places item in the todo was already implemented (2026-08-24,
PR #34); this ADR records the audit outcome.
