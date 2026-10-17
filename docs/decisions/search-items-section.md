# Decision: surface Wikibase items in Special:Search

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — the site search box / `Special:Search`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Searching `Ring` in the site search box did not show **Q2204** (`2.2: Rings
(Webpage)`), even though its label contains the term.

Two independent limits caused this:

1. **Core SearchMySQL indexes page text only.** `Item:` pages carry no page
   text, so item labels never enter the normal text index. The Item namespace
   is not in the default search set, and adding it would not help — there is
   nothing to index.
2. **`wbsearchentities` is prefix-only.** The term store has the label, but
   the API matches exact/prefix. `search=Rings` returns nothing because the
   label starts with `2.2: `; `search=Ring` finds the exact-label `Q2163`
   but not `Q2204`.

The instance already has a CONTAINS search over the Wikibase term store
(`action=entitysearch`) used by the entity comboboxes. Verified live:
`action=entitysearch&search=Ring` returns `Q2204`.

## Decision

Append an **Items** section to `Special:Search`, backed by the same
CONTAINS search:

- Extract the term-store matching from `ApiEntitySearch` into the shared
  `Search\EntityTermSearch` service (read-only; same `wbt_*` SQL, case-
  insensitive `CONVERT(wbx_text USING utf8mb4)`, label + alias term types,
  configured language fallback). `action=entitysearch` delegates to it — no
  behaviour change for the comboboxes.
- `Hooks::onSpecialSearchResultsAppend` runs when:
  - the term is non-empty and this is not a prefix search;
  - the profile is `default`/`all`/empty, or the resolved namespace set
    includes Item, or it is the normal `advanced` landing page with no
    explicit `ns*` selection. The site search (a `fulltext=1` request with no
    profile) lands on `advanced`; showing the section there does not require
    the user to have ticked a namespace checkbox.
  A search restricted to an explicit namespace set without Item keeps core's
  results only.
- Render up to 10 items with label + description + a link to
  `Special:EntityPage/Q…`, reusing the core `.mw-search-result-heading` /
  `.searchresult` classes; a tiny `search.css` module adds the section
  spacing and list reset.
- No CirrusSearch/Elasticsearch: the term-store CONTAINS search is
  proportionate for this instance's size and is already the documented
  combobox path. CirrusSearch + WikibaseCirrusSearch remains the upgrade
  path if the instance grows.

## Consequences

- Searching a label fragment now finds the item even when the fragment is
  not a prefix (the reported `Ring` → Q2204 case).
- The Items section is additive: page-text results are unchanged, and a
  namespace-scoped search is not polluted with items.
- `action=entitysearch` and `Special:Search` share one matching
  implementation, so a future change cannot make them diverge.
- Tests: `tests/e2e/run_search_e2e.py` creates a scratch item whose label
  does not start with the search term, searches a mid-label fragment, and
  asserts the item link + `wb-search-items` markup; self-cleaning.

## References

- `extensions/EmbeddableContent/includes/Search/EntityTermSearch.php`
- `extensions/EmbeddableContent/includes/Api/ApiEntitySearch.php`
- `extensions/EmbeddableContent/includes/Hooks.php`
  (`onSpecialSearchResultsAppend`)
- `extensions/EmbeddableContent/resources/search.css`
- `tests/e2e/run_search_e2e.py`
- `docs/decisions/foss-software-page-split.md` (the `action=entitysearch`
  rationale)
