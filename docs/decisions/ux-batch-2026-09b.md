# Decision: Sep-2026 UX batch B — file toolbar, picker sort, upload flow, footnote links

- **Status**: Accepted (Sep 27 2026)
- **Scope**: `wikibase.ronzz.org` — on-wiki UX for files, the Add\* pickers, `Special:Upload` and citations
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A batch of small, user-facing gaps reported against the instance:

1. **`/File:xxx` has no copy affordance** — an editor embedding a file has to
   hand-type `[[File:…]]` or dig the media URL out of the page source.
2. **`Special:AddSource`'s source-type picker and `Special:AddCollective`'s
   class select are in config/manifest order.** The AddCollective select also
   renders the raw camelCase config keys (`organization`, `groupOfHumans`, …)
   as its option labels — unlike AddSource, which uses localized labels.
3. **`Special:Upload`** lacks the house workflow conveniences: a second submit
   that keeps the license/author/license-info for the next image, a
   copy-the-embed-code option, and a nudge when Author/License are empty; and
   the file picker stays active even when the Url source is selected.
4. **`<references/>` footnotes do not link back to their source** — the
   citation is derived from the source item, but the reader cannot click
   through to the `Source:` page.
5. **A wikitext render regression**: prose `'''bold'''` after an *unbalanced*
   math delimiter (`$$1\ amu=…$` on the `Atom` page) rendered literally. The
   SimpleMathJax quote-guard (our local patch) paired the stray delimiter with
   a much later one, producing one ~1900-char "math span" that swallowed
   several paragraphs and protected their apostrophes.

## Decision

### 1. File: page copy toolbar
`Hooks::onBeforePageDisplay`'s `NS_FILE` branch resolves the file name + media
URL into JS config vars (`wbFileName`, `wbFileUrl`) and loads
`ext.embeddableContent.filepage`; the module renders two buttons **inline to
the right of the file-name title**, reusing the `.wb-embed-toolbar-btn`
primitive + `gadget.css`:

- *Copy internal embed code* → `[[File:xxx]]`
- *Copy direct link* → the media URL (`…/images/1/1b/xxx`)

The same module completes the `Special:Upload` hand-off (below).

### 2. Alphabetical pickers
New pure `Spec\LabelSorter::sortByLabel()` sorts `[label => value]` option maps
with ICU `Collator` (locale-aware — a byte sort misplaces French accented
labels; `ext-intl` is a MediaWiki requirement). Both `Special:AddSource`'s
picker and `Special:AddCollective`'s select use it. AddCollective gains
localized human labels (`embeddablecontent-agent-class-*`, en/fr/eo); the
class **item id remains the field value**, so the harvest inference, duplicate
guard and statement building are unchanged.

### 3. Special:Upload
- **"Submit and upload another image from same author"** — a second submit
  button via the descriptor (`type: submit`, `name: wpUpload` — core only
  processes an upload when `wpUpload` is checked — with the distinguishing
  value `another` and a `buttonlabel-message`). `uploadform.js` opens the File
  page in a **new tab** (`window.open`, which keeps the opener relationship)
  and the `BeforePageRedirect` hand-off carries `wbanother=1` + the
  license/author/license-info; the File page (filepage.js) sends the opener
  back to a fresh `Special:Upload` prefilled with those fields.
- **"Copy internal embed code"** checkbox in the *Upload options* section,
  default on. `BeforePageRedirect` appends `wbuploadcopy=1`; the destination
  File page copies `[[File:xxx]]` — with the **final** file name, which the
  submit-time form field may not know.
- **Empty Author/License warning** on submit (a cancellable `window.confirm`),
  attached to the submit **buttons' click** so a cancel also stops
  `uploadmeta.js`'s async Wikimedia-blob resubmit (a submit-event handler
  could be bypassed by its `form.submit()`).
- **Source gating** — the file picker is disabled unless the *Source filename*
  (File) radio is selected. Core's `mediawiki.special.upload` only sets the
  URL field's initial state; our module covers the file field (core keeps both
  in sync after a radio change).

### 4. Footnote → Source: page link
`SourcePageResolver` (Wikibase `SiteLinkLookup`, site id `wikibase`) resolves
an item's classic page URL; `CiteQ` wraps a **single-entity** citation in
`SourceLink::wrap()` when the source item has one. The wrap runs **after** the
engine's `CitationSanitizer`, so the cached and `action=citation` outputs stay
link-free, and the href follows the current sitelink (a page rename is never
served stale). Multi-entity refs and items without a classic page render
unchanged. `{{#citations:}}` (the aggregated bibliography) is deliberately
untouched — the request scoped the change to `<references/>` footnotes.

### 5. SimpleMathJax quote-guard paragraph stop
`SimpleMathJaxQuotes::findClose()` returns "unbalanced" at the first blank
line. MathJax scans each rendered **DOM text node** independently, so a
delimiter can never pair across a paragraph break — and neither may the
scanner. An unbalanced delimiter is now ignored (as MathJax ignores it)
instead of swallowing prose. See `SimpleMathJax/VENDORED.md`.

## Security / XSS

- The footnote link is added **after** the citation sanitizer and only wraps
  already-sanitized HTML in one anchor (no nested anchors — the allowlist drops
  `<a>`); the URL is attribute-escaped.
- The File page's copied snippet is built client-side from the server-provided
  file name; the clipboard content is wikitext/URL text, not HTML.
- The upload hand-off params are user-entered non-secret text; they ride the
  redirect query string only (no session state), and the destination page
  strips them from the URL after use.

## Testing

- **Unit (pure PHP)**: `LabelSorterTest` (ASCII + French collation + values),
  `SourceLinkTest` (wrap / no-url / escaping), `SimpleMathJaxQuotesTest`
  (unbalanced `$$…$` + prose bold, single-newline math still paired,
  blank-line pair rejected).
- **E2E (`run_pages_e2e.py`)**: AddSource picker + AddCollective select orders
  (OOUI `data-ooui` option order); the File page loads `filepage` with the
  config vars; `Special:Upload` renders the checkbox + the `wpUpload=another`
  button + the `uploadform` module; the `filepage` / `uploadform` module
  sources (clipboard + opener logic are JS-side); the cite-by-QID footnote
  links to the source item's `Source:` page.
- **E2E (`run_math_e2e.py`)**: the `Atom`-class regression — prose `'''bold'''`
  after an unbalanced `$$` renders bold.
- **Browser UX**: `tests/e2e/run_wiki_ux_e2e.mjs` (Playwright, manual like the
  math UX suite) exercises the copy buttons, the empty-field warning, the
  source gating and the upload-another new tab against a live instance.

## Consequences

- Editors copy file embed code / direct links without reading wikitext.
- The Add\* pickers are predictable in the reader's language; AddCollective's
  classes are readable.
- The upload workflow keeps license/author across a series of same-author
  images and can auto-copy the embed code.
- Footnote citations become navigable to their source pages.
- The quote guard no longer breaks prose bold after a malformed delimiter.

## References

- Related decisions: `docs/decisions/cite-by-qid.md`,
  `docs/decisions/upload-enhancements.md`, `docs/decisions/upload-ux-fixes.md`,
  `docs/decisions/inline-latex-math.md`,
  `docs/decisions/ux-batch-2026-09.md`
- SimpleMathJax local patch: `extensions/SimpleMathJax/VENDORED.md`
