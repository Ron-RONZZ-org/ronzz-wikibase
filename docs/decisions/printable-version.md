# Decision: Printable-version enhancements

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — the core sidebar "Printable version" link
  and a new "Print this page" toolbar button
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

MediaWiki's "Printable version" is a bare `javascript:print();` on the current
DOM. On this instance that prints the **namespace-prefixed title**
(`User:Rongzhou/Construction site visit report`,
`Source:L111-1 of Code de l'éducation (Legislation)`), the **subpage
breadcrumb** (`< User:Rongzhou`), and the injected toolbars — and it never
names the page's authors.

## Decision

A new `ext.embeddableContent.print` module (JS + print-only CSS + i18n),
loaded on every existing article page — Special:, Item:/Property: and File:
are excluded (`Hooks::onBeforePageDisplay` → `wirePrintModule`). It sets
`wbPrintTitle = Title::getSubpageText()` — the namespace **and** the subpage
parent are dropped (`getSubpageText()` returns the part after the last `/` in
a subpage-enabled namespace, else the namespace-stripped text).

Two entry points share one flow:

1. **"Print this page"** — a button in the page-title toolbar
   (`.wb-print-toolbar`, the File:-page/content-page button pattern).
2. **The core sidebar link** — `a[href="javascript:print();"]` is intercepted
   (delegated, skin-agnostic).

Both open one OOUI `PopupWidget` (the `citationpopup.js` pattern) offering an
**"Add a cover page"** checkbox + a **Print** button. On print:

- the page's registered, non-bot contributors are fetched
  (`action=query&prop=contributors&pcexcludegroup=bot`; anonymous/IP edits are
  skipped), ordered by **revision count** (most active first, ties
  alphabetical — counted from the page's revision list, capped at 5000
  revisions), and rendered as a centered `by A, B, and C` line (Oxford comma,
  localized separators);
- a print-only header is injected (or, with the cover option, a centered serif
  cover page with `page-break-after: always` so the content starts on page 2);
- `body.wb-printing` hides `#firstHeading`, the breadcrumb, and the injected
  toolbars; `window.print()` runs; `afterprint` cleans up.

**Ctrl-P (without either entry point) keeps the browser default** — a print
output cannot be retro-fitted after the fact, and the `wb-printing` class is
what gates the title swap (an untouched print never loses its title).

## Consequences

- No server config/vocabulary change; a purely client-side feature plus one
  JS-config var. Deploy = extension rsync + php-fpm restart.
- The contributor list is a live API call at print time (no per-page-load
  query); it degrades to "no author line" on failure and never blocks the
  print.

## References

- `extensions/EmbeddableContent/resources/print.js`, `print.css`
- `extensions/EmbeddableContent/includes/Hooks.php` (`wirePrintModule`)
- `extensions/EmbeddableContent/resources/citationpopup.js` — the popup pattern
- `tests/e2e/run_wiki_ux_e2e.mjs` — button/popup/cover; `run_pages_e2e.py` —
  module load + `wbPrintTitle`
