# Decision: Source quotation listing — Special:QuotationsOf + Source-page auto-link

- **Status**: Accepted (Sep 3 2026)
- **Scope**: `wikibase.ronzz.org` — the `Source:` classic pages and the
  quotation content items (`Special:AddQuotation`)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Quotations created via `Special:AddQuotation` carry an optional `source`
item (a work: book, article, website, …). Readers of a `Source:` page had no
way to see the quotations drawn from that source, and editors could not
survey them. Requested UX: "show all the quotations … with an auto-link in
`Source:xxx`".

Two candidate shapes were considered and the **Special page** shape chosen
(plan approval 2026-09-03): real `Source:xxx/quotation` subpages were
rejected — a stored page can go stale the moment a quotation is added, needs
creation/refresh lifecycle at every Add* and delete, and competes with the
Translate-extension `/xx` translation subpages for the page's subpage
namespace.

## Decision

### 1. `Special:QuotationsOf/<Qid>` — an always-live listing page

A new Special page (no parser cache) queries **WDQS** on every load for the
quotations of the given item — the class filter (`instance of` quotation)
and the `source` statement — and renders each quotation's **decoded**
payload (`PayloadCodec`, so multi-line text shows as real newlines) with a
link to the quotation item. `QuotationFinder` (pure: query building + row
parsing, injected SPARQL runner — the `DuplicateFinder` shape) is
unit-tested; `QuotationLookup` is the MediaWiki-bound facade (the
`DuplicateChecker` shape: POST to the `sparqlUrl` config endpoint,
`Accept: application/sparql-results+json`, entity prefixes from
`$wgServer`). Exception-safe by contract: a WDQS failure renders an explicit
"unavailable" notice on the Special page, never a 500, and hides the
auto-link row on the Source pages. WDQS is eventually consistent — a
quotation created moments ago may join the listing after the updater polls
it (bounded; the auto-link count is repaired by the invalidation below).

### 2. `{{#quotations-of:}}` — the auto-link row on the Source: pages

A parser function (magic word spelling `quotations-of`) resolves the current
page's sitelinked item (the `{{#source-access:}}` pattern; an explicit
`{{#quotations-of:Q42}}` also works) and renders a **complete wikitext table
row** — `| Quotations || [[Special:QuotationsOf/Q42|N]]` — when at least one
quotation exists, and **nothing** otherwise. ParserFunctions (`#if`) is not
installed on the instance, so the empty-row hiding lives in the function
itself. The per-class Source templates (`Template:Book`,
`Template:ScholarlyArticle`, …) gain one line. The source item is registered
as a parser-cache dependency (`ParserOutput::addTemplate`, the
`{{#source-access:}}` pattern).

### 3. Freshness: invalidate the source page when a quotation changes

The parser-cache dependency only re-renders a Source page when the SOURCE
item is edited — adding a quotation does not touch it. The content-creation
paths therefore invalidate the source's classic page explicitly (best-effort
`Title::invalidateCache`, exception-safe): `SpecialAddContentItem` (the
browser Add* pages) and `ApiAddSpecialContent` (the machine path) when their
record carries a `source`.

## Consequences

- Source pages self-link to a live, always-current quotation list; no
  subpage lifecycle, no stale snapshots.
- No new vocabulary, no manifests, no config-map keys — the property ids
  come from the existing `EmbeddableContentConfig` (classes /
  payloadProperties / provenanceProperties / instanceOf / sparqlUrl).
- A WDQS outage hides the auto-link (the row disappears) and shows an
  explicit notice on the Special page — the wiki stays fully usable.
