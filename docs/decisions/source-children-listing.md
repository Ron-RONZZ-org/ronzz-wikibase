# Decision: Source child-items listing — Special:ChildItemsOf + Source-page auto-link

- **Status**: Accepted (Sep 2026)
- **Scope**: `wikibase.ronzz.org` — Source pages of child-capable classes,
  the WDQS listing machinery, on-wiki Source templates
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The Source pages already auto-link their **quotations** via
`Special:QuotationsOf` + `{{#quotations-of:}}`. Source classes with CHILD
source classes (book → book excerpts, YouTube channel → YouTube videos —
the config's `sourceParents` map) had no equivalent: a book's excerpts and
a channel's videos were only discoverable by browsing WDQS. The request:
child-capable `Source:` pages get a quotations-style aggregation of links
to their child items.

## Decision

### 1. `Special:ChildItemsOf/<Qid>` — an always-live listing page

Mirrors `Special:QuotationsOf` exactly:

- queries **WDQS** on every load (Special pages are never parser-cached)
  for items that `part of` the parent AND are classified under one of the
  parent class's child classes (book → bookExcerpt; youtubeChannel →
  youtubeVideo; the mechanism is config-driven over `sourceParents()`, so
  future child relations work without code);
- groups rows by child class under the child class's plural label message
  (`embeddablecontent-childitems-label-<key>`);
- each child links to its OWN page: the sitelinked classic Source: page
  when it has one (a YouTube video does), else its Item page (a book
  excerpt creates no classic page);
- a WDQS failure renders an explicit "unavailable" notice, never a 500;
  a parent with no child classes / no children renders the "no child
  items" notice.

`ChildItemFinder` is pure (query building + row parsing, injected SPARQL
runner — the `QuotationFinder` shape, unit-tested, including the
no-literal-backslash regression); `ChildItemLookup` is the MW facade
(`sparqlUrl` + entity prefixes from `$wgServer`).

### 2. `{{#child-items-of:}}` — the auto-link rows on the Source: pages

A parser function (magic word spelling `child-items-of`) resolves the
current page's sitelinked item (explicit `{{#child-items-of:Q42}}` also
works) and renders one complete wikitext table row per child class with
children — `| Book excerpts || [[Special:ChildItemsOf/Q42|N]]` — and
nothing when there are none (no `#if` on the instance, so the hiding lives
in the function). The parent item is registered as a parser-cache
dependency. The per-class Source templates (`Template:Book`,
`Template:YouTubeChannel`) gain the line, like `{{#quotations-of:}}`.

### 3. Freshness: invalidate the parent page when a child changes

Creating or re-parenting a child item never touches the parent item's
revision, so the parser-cache dependency cannot fire. The source-creation
paths (form `SpecialAddSource`, `ApiAddSource` create+update) and the
`Special:UpdateSource` update therefore invalidate the affected parent
pages' classic pages (old + new parent on re-parent; best-effort
`Title::invalidateCache`, exception-safe) via
`ChildItemLookup::invalidateParentPages`.

## Consequences

- Book and YouTube-channel Source pages self-link to a live, always-current
  list of their child items; no subpage lifecycle, no stale snapshots.
- No new vocabulary / manifests / config-map keys — child classes come from
  the existing `sourceParents()` map (class keys → parent class keys),
  property ids from `sourceProperties` (`partOf`) + `instanceOf`.
- Scope: rows added to `Template:Book` + `Template:YouTubeChannel` only
  (per the user's request); the machinery itself is generic and future
  child relations (e.g. webpage→website) can opt in per template.

## Tests

`ChildItemFinderTest` (pure: query build, row parse, invalid input,
runner-failure null, no-backslash); page-flow E2E creates a bookExcerpt
child under the seeded book and retries `Special:ChildItemsOf/<book>` until
WDQS shows the child linked to `Item:<qid>` (the established 60 s WDQS
consistency retry); SpecialPages registration check in `run_e2e.py`.
