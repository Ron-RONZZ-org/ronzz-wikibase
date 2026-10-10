# Decision: canonical page-action-toolbar order

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — the block `.wb-embed-toolbar` (entity pages
  and the classic per-kind pages `Source:`/`Person:`/`Collective:`/`FOSS:`/
  `Software:`) and the inline `.wb-content-page-toolbar` (ordinary content
  pages)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The page action toolbars are assembled by independent JS modules, each
appending its control at load time: `updatebutton.js` **prepended** the update
button, `entityactions.js` (via `gadget.js`) appended the embed/citation
controls **asynchronously** after API probes, `sourcecite.js`/`mention.js`
appended the citation/mention buttons, `contentpagetoolbar.js` the page
mention/citation, and `print.js` — which loads **first** — appended "Print this
page" early. The rendered order therefore drifted from the intended reading
order and could vary with network timing.

## Decision

One shared primitive, **`ext.embeddableContent.toolbar`**
(`resources/toolbar.js`). Each module stamps its control with a canonical rank
(`data-wb-order`); `add()` appends the control and re-sorts the row, and
`sort()` re-orders in place. The canonical **left → right** order:

| Rank | Control |
|------|---------|
| 10 | Update basic information / Edit content |
| 20 | Copy internal citation |
| 30 | Copy internal mention |
| 40 | Copy embed code (flavour chooser **41**, language select **42** stay grouped right after it) |
| 50 | Copy citation |
| 60 | Print this page |

Because the sort re-orders the **DOM**, the keyboard tab order matches the
visual order. A sort that would not change the order is skipped, so a live
node (an open embed chooser, a focused control) is never detached. Controls
that do not apply to a surface simply do not exist, so the relative order of
the ones present is preserved (e.g. the content-page toolbar renders mention →
citation → print).

## Consequences

- `print.js` no longer relies on winning the module-load race: its button is
  last via rank 60.
- The Add* success popup row (`.wb-embed-toolbar.wb-addmore-actions`) uses the
  same ranks, so the popup and the page toolbars stay consistent.
- No server/vocabulary/config change; deploy = extension rsync + php-fpm
  restart.
- Regression coverage: `tests/e2e/run_wiki_ux_e2e.mjs` asserts the DOM order
  on a provision `Source:` page (all six buttons) and on a Main-namespace
  content page (mention → citation → print).

## References

- `extensions/EmbeddableContent/resources/toolbar.js`
- `resources/updatebutton.js`, `entityactions.js`, `mention.js`,
  `sourcecite.js`, `contentpagetoolbar.js`, `print.js`, `addmore.js`
- `extension.json` (the module + its dependencies)
- `docs/decisions/printable-version.md`,
  `docs/decisions/content-page-reference-toolbar.md`,
  `docs/decisions/classic-page-toolbar.md`
