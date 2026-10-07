# Decision: classic content-page action toolbar ("Copy internal mention")

- **Status**: Accepted (Oct 1 2026; renamed + normalized Oct 7 2026)
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
styling). Its first action is **"Copy internal mention"** (renamed from the
misleading "Copy internal reference" — it copies an internal *link*), which
copies the page's `[[…]]` snippet to the clipboard.

- `Hooks::onBeforePageDisplay` adds a branch for existing content pages that
  are NOT entity pages (`Item:`/`Property:`), `Special:`, `File:` or the
  per-kind classic namespaces (`Source:`/`FOSS:`/`Person:`/`Collective:`/
  `Software:`, which already carry the item action toolbar). It resolves the
  snippet through `Spec\MentionSnippet::fromPrefixedTitle()` and sets
  `wbReferenceSnippet`, then loads
  `ext.embeddableContent.contentpagetoolbar`.
- `Spec\MentionSnippet` (pure, unit-tested) builds `[[Page name]]`: in the
  **Main namespace** the first letter is lowercased (sentence case) UNLESS
  the title is a proper name — every word starting with an uppercase letter
  ("Albert Einstein", "Main Page", a single capitalised word). Titles in
  every other namespace are left exactly as the page title reads
  (`Help:Contributing`). MediaWiki's capital-links rule makes the Main
  first letter case-insensitive, so the lowercased form resolves identically.
- `resources/contentpagetoolbar.js` renders the button with the same
  copy/fallback primitives as `filepage.js` and reuses the
  `embeddablecontent-gadget-copied` notification. The module is deliberately
  a **content-page toolbar** (not a copy-only module): future page-level
  actions join the same inline row.
- i18n keys `embeddablecontent-contentpage-copymention` / `-hint` (en/fr/eo).

## Consequences

- Every ordinary content page now makes a tiny extra ResourceLoader request;
  the module is desktop+mobile and needs no API roundtrip (the snippet
  rides a JS config var).
- Entity pages and the per-kind classic pages keep their own toolbars (no
  duplicate button); `File:` keeps its two copy buttons.
- No DB / vocabulary / config-map change.
- Regression coverage: `run_wiki_ux_e2e.mjs` asserts the button is inline
  in the title and copies the normalized snippet byte-for-byte
  (`[[Main Page]]` — proper name; `[[classical mechanics]]` — sentence case),
  and `MentionSnippetTest` covers the normalization rule.
