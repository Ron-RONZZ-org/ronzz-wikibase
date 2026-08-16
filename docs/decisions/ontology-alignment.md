# Decision: Ontology alignment — mirror properties + machine-readable equivalence

- **Status**: Accepted (Aug 15 2026)
- **Scope**: `wikibase.ronzz.org` — reusing well-known semantic properties (RDF/RDFS/OWL/SKOS)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

We want to "reuse" standard semantic properties (`rdf:type`, `rdfs:subClassOf`, `owl:equivalentProperty`,
`skos:exactMatch`, …) instead of reinventing the wheel. Research into how successful deployments
(Wikidata, FactGrid, Wikibase.cloud instances) and the literature actually do it settled the pattern.

## Decision

1. **Mirror**: define our own local properties (auto-assigned P-numbers) whose semantics mirror the
   standard vocabulary — e.g. *instance of*, *subclass of*, *exact match*, *equivalent property*.
2. **Align as data**: on each mirror property, add **`equivalent property` statements** whose values are
   the canonical URIs — **two targets per property**:
   - the **standard vocabulary URI** (`http://www.w3.org/1999/02/22-rdf-syntax-ns#type`,
     `http://www.w3.org/2000/01/rdf-schema#subClassOf`, `http://www.w3.org/2002/07/owl#equivalentProperty`,
     `http://www.w3.org/2004/02/skos/core#exactMatch`, …) — datatype `URL` (serializes as URI nodes);
   - the **Wikidata counterpart** (`wd:P31`, `wd:P279`, `wd:P2888`, `wd:P1628`, …) — via the same
     mechanism or an ExternalId property with formatter URL.
3. **Implement via the property importer** (variant A/B: standard-vocabulary bootstrap script, or
   generic rdflib-based importer) — API-driven, additive-only, idempotent (dedup by canonical URI via
   mapping property + local cache), every statement carries an import-provenance reference.

This is the precedent pattern: **Wikidata itself does exactly this** — its *instance of* (P31) carries
`equivalent property → http://www.w3.org/1999/02/22-rdf-syntax-ns#type`, and its WikiProject
Ontology/Mapping governs P1628/P1709/P2888 alignments to schema.org/OWL/SKOS. FactGrid mirrors with its
own P-numbers and cross-maps to Wikidata via property-ID properties. The literature (PLOS *Identifiers
for the 21st century*, Lesson 4: "labels should not be treated as identifiers"; O2WB, K-CAP 2023)
confirms opaque local IDs + declared equivalence as the sanctioned model.

## What this does NOT do (and why that's correct)

- It does **not** make `rdf:type` a usable predicate in SPARQL or in the RDF export — predicates stay
  our property URIs (`wdt:Pnnn`). Interop is achieved via the equivalence *data*, not predicate
  rewriting. Verified: no per-property predicate remapping exists in the installed Wikibase RDF layer.

## Rejected approaches (with evidence)

| Approach | Why rejected |
|---|---|
| **RDF predicate remapping** (make the dump emit `rdf:type`) | Not supported by Wikibase (verified in `RdfBuilder`/`RdfVocabulary` source); would require engine forks |
| **Full-ontology import O2WB-style** (IRIs as Items + 12 properties per datatype) | Research artifact (2★, frozen at paper time, pins `wikibase-integrator 0.12.2`); creates **13 entities per IRI** (entity bloat, uuid-suffixed property spam); **loses literal datatypes** (`String(str(o))`); `FORCE_APPEND` duplicates on re-run |
| **Direct storage-layer injection with string IDs** (`rdf:type` as a page title in NS 122) | **Breaks the numeric ID contract everywhere.** Evidence (installed source): `ItemId::PATTERN = /^Q[1-9]\d{0,9}\z/i`, `NumericPropertyId::PATTERN = /^P[1-9]\d{0,9}\z/i` — enforced in constructors (`InvalidArgumentException`); registered per entity type in `WikibaseLib.entitytypes.php`; Java WDQS updater `EntityId` = `[A-Z]\d+` + `Long.parseLong`. Injected rows exist but are **unusable via every supported path** (API, serialization, links, EntityData, updater, dumps) |
| **Number-mirroring Wikidata's P-numbers for "mental model"** | Convention-only correspondence (local `P31` ≠ Wikidata's `P31` — different URI namespaces). Practical only for very low numbers (pre-setting `wb_id_counters` to 30 → next property `P31`); impractical for `P279`/`P2888` (needs hundreds/thousands of properties created first); **partial mirroring misleads** (a local `P33` that isn't Wikidata's `P33` in the same numeric neighborhood). Real correspondence = mapping statements, not numbers. See `decisions/opaque-id.md` for the same principle at the identity level |

## Consequences

- Editor mental model comes from **labels** (identical to Wikidata's: "instance of", "subclass of", …) —
  which is what the UI shows anyway.
- Machine-readable alignment via equivalence triples; consumers can dereference/federate/reason.
- Imported properties are ordinary P-entities: `Special:ListProperties`, autocomplete, SPARQL `wdt:Pnnn`,
  RDF export — all stock.
- Follow-up: the property importer script (variant A: hardcoded standard-vocabulary table, ~½–1 day;
  variant B: generic rdflib importer with `rdfs:range`→datatype inference, ~1–2 days).

## References

- Wikidata WikiProject Ontology/Mapping (P1628/P1709/P2888): https://www.wikidata.org/wiki/Wikidata:WikiProject_Ontology/Mapping
- Wikidata:Item classification (P31/P279 semantics): https://www.wikidata.org/wiki/Wikidata:Item_classification
- PLOS "Identifiers for the 21st century": https://journals.plos.org/plosbiology/article?id=10.1371%2Fjournal.pbio.2001414
- O2WB (K-CAP 2023): https://dl.acm.org/doi/fullHtml/10.1145/3587259.3627568 · repo: https://github.com/semantisch/o2wb
- FactGrid (stock deployment): https://en.wikipedia.org/wiki/FactGrid
- Related: `decisions/opaque-id.md`, `decisions/raw-rdf-in-blazegraph.md`
