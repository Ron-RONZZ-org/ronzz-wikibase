# Copy internal mention + `{{#content:}}` quotation attribution

## Context

The classic per-kind pages (`Source:` / `Person:` / `Collective:` / `FOSS:` /
`Software:`) render a shared action toolbar (`Hooks::wireItemToolbar`). Only
source-class pages carried a copy action for wiki-internal reuse: the "Copy
internal citation" button (`<ref>{{#cite:Q42}}</ref>`, `resources/sourcecite.js`).
Editors also need a lightweight way to *mention* an entity — a piped internal
link to its classic page — from a source-independent page, i.e. from every one
of the five classic pages.

Separately, `{{#content:Q}}` expands a **quotation** item to its payload
wikitext (`ContentWikitext::quotation`, since the upload-image-tools batch),
but rendered no attribution, so a quoted passage showed without its author or
source.

## Decision

### Copy internal mention

- `Hooks::wireMentionButton` runs in the classic-namespace branch of
  `onBeforePageDisplay` (after `wireItemToolbar`) and sets three JS config
  vars: `wbMentionLink` (the page's prefixed title), `wbMentionLabel` (the
  item's English label, falling back to the page text) and `wbMentionItalic`
  (`true` on `Source:`). It loads `ext.embeddableContent.mention`. The
  `wbMentionLabel` value drops a single trailing parenthetical group
  (`LabelSanitizer::stripParentheticalSuffix()`) — the AddSource
  class-disambiguation suffix — so "Méditations poétiques (Book)" displays as
  `[[Source:Méditations poétiques (Book)|Méditations poétiques]]`; the link
  target keeps the full page title. A label that is only a parenthetical is
  kept unchanged.
- `resources/mention.js` copies `[[<page>|<label>]]`, wrapped in `''…''` on
  Source pages. It renders into the shared `.wb-embed-toolbar` row and, when
  the "Copy internal citation" button is present, inserts itself **to its
  right**; otherwise it appends to the row.
- The button is scoped to the five **classic** namespaces (not `Item:` pages,
  where the link target would be `Item:Q…`, not a content page).

### `{{#content:}}` quotation attribution

- `ContentWikitext::quotation($wikitext, $attribution)` appends the attribution
  on its own paragraph; `ContentWikitext::quotationAttribution($authors,
  $sources)` is the pure `-author, ''source''` assembly (missing parts omitted,
  empty when both are absent).
- `ContentPayload` resolves the quotation's `attributed to` (plain English
  label) and `source` (italic link to the source's classic page via its
  `wikibase` sitelink: `''[[Source:Beloved (Book)|Beloved]]''`; plain italic
  label when the item has no page) from the config's
  `provenancePropertyIds()`. The author and source item pages are registered as
  parser-cache dependencies, so editing them re-renders every consumer page.
- Scope is `{{#content:}}` only; the embed surfaces (`Special:Embed`,
  `action=embed`, `Special:QuotationsOf`) keep their framed chrome.

## Consequences

- No vocabulary or seed change: both features read existing properties
  (`attributed to`, `source`) and the mention button is pure presentation.
  Deployment is an extension rsync + php-fpm restart + a parser-cache purge
  (`{{#content:}}` output changed) + a message-cache purge (new i18n keys).
- The mention snippet is derived server-side from the current sitelink and
  label, so a page rename or label edit is never stale.
- `Special:QuotationsOf` and the embed surfaces are unchanged; a follow-up
  could reuse the same attribution assembly there if desired.
  **Updated (Oct 7 2026, `classic-page-citation-and-preview.md`)**: the
  attribution resolution moved to `Content/ProvenanceWikitext` (shared by
  `ContentPayload` and the embed renderer), and the `action=embed&preview=1`
  quotation preview now reuses the exact attribution line.

## Tests

- `tests/Unit/ContentWikitextTest.php` — the quotation/attribution assembly.
- `tests/e2e/run_pages_e2e.py` — `flow_classic_page_toolbar` asserts the
  mention wiring (`wbMentionLink` = the page title, `wbMentionItalic` true on
  Source / false otherwise, the module loaded, and `wbMentionLabel` free of
  the trailing parenthetical class suffix) on every created classic page.
