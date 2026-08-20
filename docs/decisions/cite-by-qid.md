# Decision: Cite-by-QID — citations as a derived view over items

- **Status**: Accepted (Aug 20 2026) — implementation tracked in issues #24 (v1), #25 (v2)
- **Scope**: `wikibase.ronzz.org` — on-wiki citations of Wikibase items
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Wikipedia cites by copying: `<ref>{{cite journal |author=… |title=… |doi=…}}</ref>`
embeds the bibliographic data in the page's wikitext. The same work cited on five
pages exists in five copies that drift apart, are invisible to queries, and carry
no link between a quote and its source. Wikidata's cite-by-QID proposal
(`{{Cite Q}}`) has never been deployed there.

ronzz-wikibase already has the inverted model:

- Works (book / scholarly article / website / song / film / video) are Wikibase
  items created once on the semantic side via **`Special:AddSource`** (issue #7),
  with harvested authority metadata (DOI, ISBN-13, OpenAlex, PubMed + published
  in, publisher, page(s), volume, issue); authors are person items via
  `Special:AddPerson`.
- Content items (quotation / code snippet / mathematical expression) reference
  their source via a `source` statement (`citation-property-map.json`:
  `container-title → source`).
- **D4 `api.php?action=citation`** (issue #6 §7) renders any item in 5 styles
  (JSON / APA / Vancouver / BibTeX / RIS) through in-process citeproc-php, with
  the entity-page "copy citation" gadget as the only surface.

What is missing is the on-wiki surface: citing **by QID** in page wikitext, and a
page-level reference list derived from the graph.

## Decision

1. **Citation is a derived view over items, never a copy.** Pages cite entities
   by QID; the rendered citation comes from the item graph, so editing a source
   item fixes every citing page — the property Wikipedia cannot have.
2. **`{{#cite:Q42|style=|output=}}` parser function** — the cite primitive,
   usable inside the stock Cite extension's `<ref>`; numbered footnotes, named
   refs and `{{reflist}}` are inherited unchanged (no custom footnote engine).
3. **`{{#citations}}` parser function** — aggregated bibliography of the
   distinct *source items* cited on the page (parse-time accumulation via
   parser-output extension data, deduped by source item) — the semantic "Sources"
   section.
4. **One rendering path**: a shared `CitationEngine` service — a refactor of
   `ApiCitation::execute()` — used by both the API and the parser functions,
   with the existing revId-keyed BagOStuff cache moved in verbatim.
5. **Self-cite fix (prerequisite)**: `StatementToCslConverter::toCslJson()`
   treats a source-class item as its own source, closing the gap where citing a
   source item directly omits publisher / page(s) / volume / issue / DOI / ISBN
   (`addFromSource(null, …)` currently returns early).
6. **Standalone extension** — WikibaseCitation stays independent of
   EmbeddableContent: a small allowlist HTML sanitizer lives in-extension; the
   source-class list is injected via config (default `[]`).
7. **v1 accepts ParserCache staleness** (a page re-renders its citations on next
   re-parse, same model as template transclusion); **v2 adds
   `ParserOutput::addCacheDependency()`** on cited entities' revision timestamps
   for automatic invalidation.

## Wikitext contract

```wikitext
Some quotation.<ref>{{#cite:Q42}}</ref>

Another quote, same source.<ref name="ada">{{#cite:Q42|style=vancouver}}</ref>

== References ==
{{reflist}}

== Sources ==
{{#citations}}
```

- Arg 1: entity id (`Q\d+`), required. Named args: `style` (`json|apa|vancouver|bibtex|ris`,
  default `apa`), `output` (`html|text`, default `html`).
- `{{#citations}}` renders one entry per distinct **source item** — multiple
  quotes from one book collapse to one bibliography entry (unlike footnotes,
  where each ref is its own footnote).
- v2: multi-entity `{{#cite:Q42|Q7}}`, explicit-args `{{#citations:Q42|Q7}}`.

## Architecture

```
page wikitext                       api.php?action=citation
   │  {{#cite:Q42|style=apa}}            │
   ▼                                    ▼
┌──────────────────────────────────────────────────────────┐
│ CitationEngine (NEW shared service — refactor of          │
│  ApiCitation::execute(): entity id → item → CSL-JSON →    │
│  formatted string, revId-keyed BagOStuff cache)           │
│   ├─ StatementToCslConverter (extended: self-cite)        │
│   ├─ CslTypeMapper                                        │
│   └─ CitationFormatter                                    │
└───────────────┬──────────────────────────────┬────────────┘
                ▼                              ▼
       CiteQ parser fn                 ApiCitation (thin —
       Citations parser fn             param validation + result shape only)
```

New files: `includes/CitationEngine.php`, `includes/ParserFunctions/CiteQ.php`,
`includes/ParserFunctions/Citations.php`. Modified: `StatementToCslConverter.php`
(self-cite one-liner), `Hooks.php` (`ParserFirstCallInit`),
`ServiceWiring.php` (`WikibaseCitation.CitationEngine`),
`extension.json` (config `WikibaseCitationSourceClasses`), `ApiCitation.php`
(slimmed). The CSL type path already handles source classes
(`CslTypeMapper::getTypeForContentAndSource`) — unchanged.

## Caching & invalidation

| Layer | Mechanism | Staleness |
|---|---|---|
| Per-request render | Engine revId-keyed BagOStuff (moved from `ApiCitation.php:78-91`), TTL 300, `json` uncached | none |
| ParserCache | page re-parse on next edit | **accepted in v1**, documented on-wiki |
| v2 | `ParserOutput::addCacheDependency()` on cited entities' latest revision timestamps | none (verify dependency shape on MW 1.46) |

## Security

- citeproc-php HTML output contains **user-entered statement values** (titles,
  publisher names). The `html` output path is sanitized by a small allowlist
  sanitizer in-extension (`<i>`, `<b>`, `<span class="…">`, `<em>`, `<strong>`;
  strip everything else) — the `FragmentSanitizer` precedent
  (`extensions/EmbeddableContent/includes/Content/FragmentSanitizer.php`) is
  honored without coupling the extensions. The `text` path is already
  `strip_tags`'d (`CitationFormatter::renderCslStyle`).
- The XSS discipline extends to parser-function output: statement values must
  never survive rendering unescaped; the page-flow E2E asserts injected values.

## Testing

- **Unit** (`tests/Unit/`, pure-PHP): `CitationEngineTest` — content-item cite,
  **source-item self-cite regression** (DOI/publisher/pages present), missing-field
  omission, invalid id / style, cache hit/miss (fake BagOStuff);
  parser-arg parsing logic (extracted, testable).
- **E2E** (`tests/e2e/run_pages_e2e.py`, self-cleaning): scratch page with
  `<ref>{{#cite:…}}</ref>` + `{{reflist}}` + `{{#citations}}` (IDs from
  `seed/generated/ids.json`); assert footnote non-empty, source DOI/ISBN present
  (guards the self-cite fix), `{{#citations}}` renders exactly one entry for two
  refs citing the same source; no unhandled exceptions / console errors; scratch
  page deleted. The `integration` CI job already runs this suite (`ci.yml:172`).

## Rejected approaches (with evidence)

| Approach | Why rejected |
|---|---|
| **Template-only** (`Template:Cite q` wrapping a server-side include) | The parser function is the platform primitive; templates are user-facing sugar. A parser function is unit-testable, i18n-clean, and avoids template-transclusion caching layers |
| **Custom `<ref>`-replacement tag** | The stock Cite extension already provides footnote numbering, named refs and `{{reflist}}`. Reimplementing it violates the "standalone, never reinvent" invariant |
| **Client-side only** (extend the copy-citation gadget to whole pages) | Footnotes/bibliography would not survive ParserCache or non-JS readers; citations belong in the served HTML. The gadget stays as a single-item copy convenience |
| **Hardcode citation text into page wikitext at edit time** | Reintroduces Wikipedia's duplication/drift — exactly what this decision rejects |

## Consequences

- Editors write `Some quote.<ref>{{#cite:Q42}}</ref>` — no bibliographic metadata
  ever typed by hand on wiki pages; `Special:AddSource` guarantees the semantic
  side exists before any citation is rendered.
- One edit on a source item propagates to every citing page (v1: on re-parse;
  v2: automatically via cache dependencies).
- `action=citation` gains a second consumer without forking its logic —
  `CitationEngine` is the single code path for API and parser functions.
- `{{#citations}}` gives a "Sources" section Wikipedia cannot have: grouped by
  source item, deduplicated, queryable via SPARQL.
- The self-cite fix also improves the existing API: citing a source item now
  returns its full harvested metadata.

## References

- Related decisions: `decisions/opaque-id.md`, `decisions/ontology-alignment.md`,
  `decisions/raw-rdf-in-blazegraph.md`
- Issues: #6 (D4 citation API), #7 (`Special:AddSource`/`AddPerson`/`AddCollective`),
  #24 (v1 implementation), #25 (v2 enhancements)
- Wikidata `{{Cite Q}}` proposal (never deployed): https://www.wikidata.org/wiki/Template:Cite_Q
- MediaWiki Cite extension: https://www.mediawiki.org/wiki/Extension:Cite
- citeproc-php: https://github.com/seboettg/citeproc-php