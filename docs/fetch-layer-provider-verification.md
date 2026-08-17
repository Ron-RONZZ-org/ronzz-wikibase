# Fetch layer — provider verification notes

Status of the external providers used by the entity-creation fetch layer
(`extensions/EmbeddableContent/includes/Fetch/`). Verified live **Aug 16 2026**
against the public services; anything marked *assumed* must be re-checked
before the parent issue (#7) integration. Unit tests use canned fixtures only
(no live network).

## Per-provider status

| Provider | Endpoint | Verified | Gotchas |
|---|---|---|---|
| **Wikidata** | `https://www.wikidata.org/w/api.php` (wbsearchentities / wbgetentities) | ✅ live | Name search returns items of *any* kind — disambiguation happens at pick time (harvest reads P31). Duplicate labels common (e.g. "Douglas Adams" → Q28421831 + Q42). **Two API quirks (verified live):** (1) passing `languages=en\|fr\|eo` to wbgetentities makes `labels` come back EMPTY (descriptions/claims still work); (2) for some entities the API **withholds all Latin-script labels** from automated clients (Q42 serves 75 non-Latin labels, no en — consistent across wbgetentities, EntityData and the SPARQL service) — `wbsearchentities` DOES return the en label, so the core falls back to a search-by-QID call when en/fr/eo are absent. Always set a User-Agent. |
| **Wikidata SPARQL** | `https://query.wikidata.org/sparql` (identifier→QID) | ✅ live | POST form + `Accept: application/sparql-results+json`. Slow — can exceed 10 s (observed a live timeout in the smoke run; the cascade degrades to the next provider and surfaces a warning). Used only for direct identifier lookups (P496/P356/P212). |
| **dblp REST** | `https://dblp.org/search/author/api?q=&format=json` | ✅ live | Author URLs ARE the KG entity URIs (`https://dblp.org/pid/…`). |
| **dblp SPARQL** | `https://sparql.dblp.org/sparql` | ✅ live | **Free-text `CONTAINS` scans fail server-side** ("Waited for a result from another thread…"). The QLever text predicate (`qlever:all`) returns valid JSON but **empty** results. Only **bound-value** queries are reliable — enrichment uses `VALUES ?author { <url> }`. `dblp:wikidata` links are **sparse** (many authors lack one). Beta service — be polite, expect flakiness. |
| **OpenAlex** | `https://api.openalex.org` | ✅ live | `ids.wikidata` is **frequently null** (e.g. Jason Priem → no Wikidata link) — dedupe falls back to ORCID/label. ORCID returned with dashes. No ISBN index, no verified Wikidata-Q filter — those paths return null. |
| **Crossref** | `https://api.crossref.org/works` | ✅ live | Response shape matches the mapper exactly (title[0], container-title[0], publisher, volume, issue, page, DOI, author given/family, issued.date-parts). `filter=isbn:` works for the ISBN fallback. |
| **Open Library** | `https://openlibrary.org` | ✅ shape / ⚠️ availability | **User-Agent required** — without one, `/isbn/…json` 302-redirects. `/isbn/{isbn}.json` 302-redirects to `/books/{olid}.json` (**same host** — handled by the allowlist-following client). **Intermittently unreachable** (observed connect timeouts in the smoke run from a datacenter IP while other providers responded) — ISBN lookups have Crossref `filter=isbn` and Wikidata SPARQL (P212) fallbacks, so a timeout degrades to a warning, not a failure. `search.json` docs often lack `publisher`/`isbn_13` — the mapper's isset-guards handle absent fields. |
| **ORCID** | `https://pub.orcid.org/v3.0` | ✅ live | Requires `Accept: application/json`. Public API is rate-limited (~1 rps) — the per-user cooldown in the parent issue matters here most. `given-names`/`family-name`/`orcid-id` shapes confirmed. |

## Response-shape assumptions baked into the mappers (fixtures in `tests/Unit/Fetch/`)

- Wikidata claims: `entity.claims.Pnnn[].mainsnak.datavalue.value`; item-typed values have `.id`, time values `.time` (`+YYYY-…`), strings are scalars. Nested label resolution via a **second** wbgetentities call (`props=labels`).
- SPARQL JSON: `results.bindings[].{var}.value`.
- OpenAlex: `results[]` with `id/display_name/orcid/ids{openalex,wikidata,pmid}/doi/title/publication_date/publisher/primary_location.source.display_name/biblio{volume,issue,first_page,last_page}`.
- Crossref: `message{title[],container-title[],publisher,volume,issue,page,DOI,ISBN[],author[{given,family}],issued.date-parts}`; search via `message.items[]`.
- Open Library: `search.json → docs[]` (`title,publisher[],isbn_13[],first_publish_year,key`) and `/isbn/{isbn}.json →` book record (`title,publishers[],publish_date,key`).
- ORCID: `/record → person.name.{given-names,family-name}.value` + `orcid-identifier.path`; `expanded-search → expanded-result[]`.

## Rate limits & etiquette (design contract)

- Always send the identifiable UA: `ronzz-wikibase/0.1 (+https://github.com/Ron-RONZZ-org/ronzz-wikibase)` (CurlHttpClient default).
- dblp: explicitly asks for restraint; keep it tertiary and sparse (max 5 enrichments per search).
- ORCID public: ~1 rps — the per-user fetch cooldown (~3 s) in #7 protects it.
- Wikidata / OpenAlex / Crossref: generous, but never fetch on page load — only on explicit user action.

## Deferred / unverified (Phase 1)

- dblp ORCID predicate — no verified public predicate; `byOrcid()` returns null.
- OpenAlex `ids.wikidata`-based author filter — unverified; QID lookups go through the Wikidata hub only.
- Full `wbgetentities` claim parsing for exotic datatypes (quantities, coordinates) — not needed by the #7 field contract.
