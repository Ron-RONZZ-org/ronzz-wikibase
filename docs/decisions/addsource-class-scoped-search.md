# Decision: class-scoped Wikidata search on Special:AddSource

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSource/<classKey>` Wikidata
  candidate search
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

`Special:AddSource/book` searched Wikidata through `wbsearchentities` and
showed every matching work — a book search could offer films, video games or
other works whose label merely matched the title. The class-first flow knows
the intended source class, but the Wikidata hub was not using it.

`wbsearchentities` has no class filter, and this instance has no
CirrusSearch (so `haswbstatement` is not available on the local wiki; the
Wikidata query service is the class-capable authority).

## Decision

### 1. Emit a class search scope from the seed

`config_builder.py` already emits `sourceClassByWikidata` (QID → local class
key) for harvest inference. It now also emits
**`sourceClassSearchClasses`** (form class key → QID list):

- the class's `align.wikidata` QID, always;
- family extras where the strict class is too narrow — `book` also accepts
  **`Q7725634` literary work**, so novels/textbooks/encyclopedias
  (`instance of novel` etc.) are not filtered out.

`Q47461344` **written work** is deliberately excluded: its Wikidata hierarchy
puts `video game` (`Q7889`) underneath it, which would leak games into a
book search (verified live with an `ASK` query).

`EmbeddableContentConfig::sourceClassSearchClasses()` validates the emitted
list (Q-id shape only; malformed entries dropped) and falls back to the
single aligned QID when the config predates the key.

### 2. Wikidata hub: class-scoped SPARQL

`WikidataWorkProvider` implements `ClassFilteredWorkProvider`. When the
caller passes a scope:

- **title search** runs a SPARQL query with the Wikibase MWAPI
  `EntitySearch` service, joined to
  `?item wdt:P31/wdt:P279* ?classScope` with `VALUES ?classScope { … }`;
- **author-name and author-entity searches** add the same
  `P31/P279*` constraint to the existing SPARQL query.

`ProviderClient` passes the scope only to providers implementing the
interface. OpenLibrary / OpenAlex / Crossref results are already
class-appropriate by provider and stay unfiltered, so a book search still
shows their books.

**Title search is strict; author search is class-preferred.** Wikidata often
omits `P50` on scholarly articles (or classifies a work under a sibling
class), so a class-scoped author search can legitimately return nothing even
when the author has works. When the scoped author result is empty, the hub
falls back to the author's works unfiltered — an author search is a narrowing
aid, and a dead-end selection page is worse than a broader candidate list.
The title search (the reported book case) never falls back.

`SpecialAddSource::searchByTitleAuthor()` reads the scope for the current
class and passes it through all three work-search paths.

## Consequences

- A `/book` title search offers books/literary works only; films, games and
  unrelated works no longer appear just because the label matched.
- The scope is data-driven (manifest `align.wikidata` + the explicit family
  extras), not a hardcoded Q-id per form field.
- No UI change: the candidate-selection step is unchanged.
- Deployment requires a **full seed re-emission** (never `--only=config`)
  so `sourceClassSearchClasses` reaches production; before that the
  extension falls back to the single aligned QID (still filtered, just
  without the literary-work extra).
- Unit tests cover the emitted map, the config fallback/validation, the
  SPARQL class filter, and the author-search filter.

## References

- `seed/config_builder.py` (`SOURCE_CLASS_SEARCH_EXTRA`,
  `sourceClassSearchClasses`)
- `seed/tests/test_seed.py`
- `extensions/EmbeddableContent/includes/Fetch/ClassFilteredWorkProvider.php`
- `extensions/EmbeddableContent/includes/Fetch/WikidataCore.php`
- `extensions/EmbeddableContent/includes/Fetch/WikidataWorkProvider.php`
- `extensions/EmbeddableContent/includes/Fetch/ProviderClient.php`
- `extensions/EmbeddableContent/includes/Spec/SpecialAddSource.php`
- `tests/Unit/EmbeddableContentConfigTest.php`,
  `tests/Unit/Fetch/WikidataWorkProviderTest.php`
