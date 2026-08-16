# Decision: Two-worlds architecture — Wikibase (curated) + raw RDF in Blazegraph (read-only)

- **Status**: Accepted (Aug 15 2026) — complementary to `decisions/ontology-alignment.md`
- **Scope**: `wikibase.ronzz.org` — native RDF semantics (`rdf:type`, `rdfs:subClassOf`, …) queryable in SPARQL
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Wikibase cannot expose native RDF predicates (no predicate remapping; the `wdt:Pnnn` contract). For data
that should be queryable *as-is* in native RDF — ontology axioms, A-semantika exports, imported instance
data — we load raw RDF **directly into Blazegraph** via SPARQL Update (verified working: HTTP 200 on the
`wdq` namespace endpoint), alongside the updater-fed entity data.

## Decision

Keep **two layers in the same Blazegraph store**:

| Layer | Data | Freshness | Edited via |
|---|---|---|---|
| **Entity-live** | Wikibase entities (`https://wikibase.ronzz.org/entity/…`) | live (WDQS updater) | Wikibase UI/API |
| **Raw-static** | native RDF (`rdf:type`, `rdfs:subClassOf`, …) under **its own URI namespace** (e.g. `https://a-semantika.ronzz.org/…`, ontology URIs) | on demand (loader) | loader script only |

**The one rule: raw data never uses the `/entity/` URI space.** The updater does full-replace per
entity (`DELETE { ?s ?p ?o } WHERE { ?s <schema:about> <conceptURI> }` + INSERT) — raw triples about an
entity URI are silently wiped on its next edit.

## Operating rules

1. **Source files versioned in git** (A-semantika exports, ontology files) — raw data is **not** in
   Wikibase dumps; any Blazegraph rebuild (upgrade, journal rebuild, munge path) requires re-loading.
2. **Prefix-scoped replace**: the loader deletes by subject URI prefix
   (`FILTER(strstarts(str(?s), '<raw-ns>'))`), then inserts — **named graphs are unavailable**
   (`quads=false` in `RWStore.properties`), so isolation is by namespace, not by graph.
3. **Write access stays server-side**: SPARQL Update on `127.0.0.1:9999` only; the public nginx
   `/sparql` endpoint remains read-only.
4. **No inference**: `axiomsClass=NoAxioms`, `truthMaintenance=false` — loaded `rdfs:subClassOf` is an
   inert triple; use recursive SPARQL (`?a rdfs:subClassOf+ ?b`) or (future) a Blazegraph-rules spike.
5. **Document the freshness contract** per namespace; run the loader on change (CI or cron).

## Side effects (accepted)

| # | Side effect | Mitigation |
|---|---|---|
| 1 | Two sources of truth; raw data lost on rebuild unless re-loaded | git-versioned sources + re-run loader |
| 2 | No named graphs → prefix-scoped isolation only | conservative loader, tested DELETE pattern |
| 3 | No inference on RDFS axioms | recursive SPARQL |
| 4 | No provenance (no references/ranks) on raw triples | namespace discipline; document |
| 5 | Freshness skew vs. entity layer | loader on change; documented staleness |
| 6 | Raw nodes lack entity semantics (`schema:about`, `wikibase:Item` typing, statement URIs) | fine for axioms; `wikibase:label` service still works |
| 7 | Raw URIs miss inline-URI storage optimization; `textIndex=false` (no free-text) | negligible at our scale |

## Bridge to the curated layer

Wikibase entities can reference raw nodes via **URL/ExternalId-datatype properties** (e.g. an
"external IRI" property): `Q1 —(wdt:P_extiri)→ https://a-semantika.ronzz.org/HUNDO`, enabling SPARQL
joins across both layers. Raw predicates never become editor-usable — that is inherent to this
architecture (for editor-usable predicates, use the property importer per `decisions/ontology-alignment.md`).

## Rejected alternatives

| Alternative | Why rejected |
|---|---|
| Load raw RDF into the entity URI space | Wiped by the updater's full-replace on next edit |
| Enable Blazegraph reasoning to infer RDFS semantics | Changes the store's reasoning config (currently `NoAxioms`); a rules spike is deferred |
| Push raw RDF through Wikibase (entities) | Loses native predicates — exactly what this layer is for; see `ontology-alignment.md` for the importer trade-off |
| O2WB full-ontology import | Entity bloat + literal-datatype loss; research artifact (see `ontology-alignment.md`) |

## References

- `decisions/ontology-alignment.md` (mirror+align, the importer)
- `decisions/opaque-id.md` (opaque numeric IDs — why `/entity/` is P/Q-based)
- Verified on instance (Aug 15 2026): SPARQL Update accepted on `:9999/bigdata/namespace/wdq/sparql`;
  `RWStore.properties` (`quads=false`, `NoAxioms`, `truthMaintenance=false`); updater replace pattern
  from `wdqs-updater` journal (`Got 1 changes… Q1@2@…`).
