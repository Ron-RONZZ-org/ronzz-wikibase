# Decision: classic content-page action toolbar ("Copy internal reference")

- **Status**: Accepted (Oct 1 2026)
- **Scope**: `wikibase.ronzz.org` — ordinary content pages (Main, Help,
  Cheatsheets, HowItWorks, …), `includes/Hooks.php` + a new
  `resources/contentpagetoolbar.js`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The File: page renders two copy buttons inline next to the file-name title
("Copy internal embed code" → `[[File:xxx]]`, "Copy direct link" → the media
URL) — `resources/filepage.js` + `Hooks::onBeforePageDisplay`'s `NS_FILE`
branch. Ordinary wiki pages had no equivalent: an editor wanting to link to
the page they were reading had to type `[[Page name]]` by hand (or copy the
URL and convert it).

## Decision

Ordinary content pages render a **classic content-page action toolbar**
inline to the right of the page title (the File: page pattern, `.wb-file-toolbar`
styling). Its first action is **"Copy internal reference"**, which copies
`[[<prefixed page name>]]` to the clipboard (e.g. `[[Main Page]]`,
`[[Help:Contributing]]`).

- `Hooks::onBeforePageDisplay` adds a branch for existing content pages that
  are NOT entity pages (`Item:`/`Property:`), `Special:`, `File:` or the
  per-kind classic namespaces (`Source:`/`FOSS:`/`Person:`/`Collective:`/
  `Software:`, which already carry the item action toolbar). It sets
  `wbReferencePageName` (the prefixed title) and loads
  `ext.embeddableContent.contentpagetoolbar`.
- `resources/contentpagetoolbar.js` renders the button with the same
  copy/fallback primitives as `filepage.js` and reuses the
  `embeddablecontent-gadget-copied` notification. The module is deliberately
  a **content-page toolbar** (not a copy-only module): future page-level
  actions join the same inline row.
- i18n keys `embeddablecontent-contentpage-copyref` / `-hint` (en/fr/eo).

## Consequences

- Every ordinary content page now makes a tiny extra ResourceLoader request;
  the module is desktop+mobile and needs no API roundtrip (the page name
  rides a JS config var).
- Entity pages and the per-kind classic pages keep their own toolbars (no
  duplicate button); `File:` keeps its two copy buttons.
- No DB / vocabulary / config-map change.
- Regression coverage: `run_wiki_ux_e2e.mjs` asserts the button is inline in
  the title and copies `[[Main Page]]` byte-for-byte.
