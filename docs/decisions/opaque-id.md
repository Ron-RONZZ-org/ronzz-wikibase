# Decision: Opaque entity IDs (Q/P numbers), not human-readable slugs

- **Status**: Accepted (Aug 15 2026)
- **Scope**: `wikibase.ronzz.org` — entity identity model
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

We considered replacing Wikibase's auto-assigned numeric entity IDs (`Q1`, `P2`, …)
with human-readable, editor-chosen string IDs (e.g. `HUNDO`, `KATO`) — the
well-loved key pattern of our prototype
[A-semantika](https://github.com/Ron-RONZZ-org/A-semantika), a CLI triple store where
node keys *are* the concept names (`nodo aldoni HUNDO -e "eo::Hundo"`).

Research into the Wikibase ecosystem, the persistent-identifier literature, and our
own instance's technical constraints led us to reject custom string IDs.

## Decision

**Keep the opaque, auto-assigned numeric entity IDs (Q/P) as the true identity of
entities.** Human-friendly naming is provided by Wikibase's first-class labels
(autocomplete, links, entity pages). As a follow-up, add an **alias layer** (a unique
"slug" property + MediaWiki redirect pages) if editors want machine-addressable
friendly keys — without making the slug the identity.

## Why (full justification)

### 1. Wikidata's serial IDs are a deliberate design, not inertia

- **Opaque identifiers principle**: identifiers should not encode meaning, because
  meaning rots. Wikidata's Q-numbers descend from Freebase's `/m/0xxxxx` machine IDs
  and WordNet-style numbering — chosen to make renames a *label* change instead of an
  *identity* change.
- **Zero-coordination uniqueness**: a per-type monotonic counter guarantees
  uniqueness without naming politics, slug squatting, or collision policies.
- **Ecosystem contract**: the ID shape `[A-Z]\d+` is hard-coded across the toolchain
  (WDQS updater `EntityId.class` — verified in our jar: pattern `[A-Z]\d+` +
  `Long.parseLong`; dumps; third-party tools).

### 2. The ecosystem: no public Wikibase uses custom IDs

- Successful public deployments (FactGrid, Resistance in Belgium, Lingua Libre,
  Structured Data on Commons, Wikibase.cloud instances, GND pilot) all run **stock
  software with opaque Q/P IDs**. FactGrid is explicitly documented as using Wikibase
  *"without major modifications"*.
- The Phabricator record touches ID *shape* only for internal reasons:
  - T114903 — internal `wb_terms` storage migrated from bare numbers to prefixed
    strings (storage detail, not user-facing IDs);
  - T284913 — "entity prefixes" for **federation disambiguation** (which repo an
    entity comes from), not editor-chosen slugs;
  - T272032 — Wikidata **rate-limits** the ID generator; the counter is fundamental,
    not replaceable.

### 3. Industry consensus: opaque identifiers

The canonical statement — McMurry et al., *[Identifiers for the 21st century](https://journals.plos.org/plosbiology/article?id=10.1371%2Fjournal.pbio.2001414)*
(PLOS Biology 2017):

> **Lesson 4. Avoid embedding meaning or relying on it for uniqueness.**
> …favor **opaque identifiers** and convey meaning in the entity's metadata.
> **Labels are for human readability only… labels should not be treated as
> identifiers, nor should they appear within http URIs.**

Supporting practice:
- W3C **"Cool URIs don't change"** — reference rot affects **1 in 5** academic
  references (same paper).
- Registry practice: **ORCID**, **DOI**, **ISNI**, **ARK** — all registry-assigned,
  deliberately non-meaningful.
- **MusicBrainz** migrated from name-based URLs to opaque **MBIDs (UUIDs)** because
  entity-name changes broke URLs — the canonical real-world example of the
  semantic-rot failure.
- Note: Wikidata's own `Q`/`P`/`L`/`M` comply with the consensus — a fixed,
  immutable *type* prefix + an opaque number. A slug like `HUNDO` violates Lesson 4
  directly (the meaning *is* the identifier).

### 4. The renaming risk is real and documented

- **Lesson 7 (same paper): "Do not reassign or delete identifiers."** A renamed slug
  is gone; if reused for a new concept it silently points at the wrong entity — the
  worst failure mode.
- In our stack, renaming `HUNDO` → `CANIS` means: rewriting every triple's subject
  URI in Blazegraph (graph surgery), breaking external references, and breaking
  federation mappings.
- The risk materializes exactly at our **federation/mapping plans** (linking items to
  Wikidata) — external consumers dereference our URIs, so stability matters.

### 5. The UX insight (why the prototype's charm doesn't transfer)

- In **A-semantika**, the node key *is* the label everywhere (CLI ergonomics) — the
  human-key model directly serves editor UX.
- In **Wikibase**, labels are already first-class: autocomplete, link rendering, and
  entity pages all show labels; the Q-number is barely visible (only the URL).
- Therefore custom slugs would buy **URL/API/SPARQL identity** — the risky part —
  without adding editor UX.

## Consequences

- **Gained**: zero WDQS risk (stock `[A-Z]\d+` contract preserved), zero extension /
  fork work, federation-safe stable URIs, full compatibility with the ecosystem.
- **Given up**: human-readable identity in URLs / API / SPARQL (mitigated by labels,
  and by the planned alias layer for friendly URLs).
- **Follow-up**: spec the alias layer — unique `slug` property (ExternalId/String),
  MediaWiki redirect pages (`HUNDO` → `Item:Q1`), and contribution-guide notes.

## Alternatives considered

| Option | Assessment |
|--------|-----------|
| **A.** PHP custom-ID extension + patched Java WDQS (`EntityId.class`) | Rejected — permanent fork maintenance; `EntityId` is a `(prefix, long)` data model, not just a regex; ecosystem outlier |
| **B.** PHP custom-ID extension + custom RDF bridge (SPARQL Update from `Special:EntityData`) | Technically viable (verified: EntityData .ttl works, Blazegraph accepts SPARQL Update, `wb_changes` is empty → bridge polls recentchanges) — rejected: identity fragility + we'd be the only public Wikibase with slug IDs |
| **C.** PHP extension + nightly reimport | Shares the Java `munge` constraint; raw loads lose WDQS conveniences |
| **D. Alias layer (slug property + redirects)** | **Chosen follow-up** — human-friendly naming, zero risk |
| **E.** Prefix letter only (`B1`) | Passes `[A-Z]\d+` but no slug benefit; still needs PHP ID-class work |

## Evidence gathered during this investigation (Aug 15 2026)

- WDQS updater jar `wikidata-query-tools-0.3.156`: `EntityId.class` pattern `[A-Z]\d+`
  (bytecode inspection) — custom slugs cannot sync via stock tooling.
- `update.php` applied the previously missing Wikibase schema; baseline item `Q1`
  created via API; updater synced it (`Got 1 changes… Q1@2@…`); SPARQL serves
  `wikibase.ronzz.org/entity/Q1` — full pipeline verified for numeric IDs.
- `Special:EntityData/Q1.ttl` returns WDQS-style RDF; Blazegraph `:9999/bigdata/
  namespace/wdq/sparql` accepts SPARQL Update (HTTP 200 probe).

## References

- Wikibase showcase (public deployments): https://wikiba.se/
- Wikibase.cloud: https://www.wikibase.cloud/
- FactGrid (stock, "without major modifications"): https://en.wikipedia.org/wiki/FactGrid
- Identifiers for the 21st century (PLOS, 2017): https://journals.plos.org/plosbiology/article?id=10.1371%2Fjournal.pbio.2001414
- W3C Cool URIs: https://www.w3.org/TR/cooluris/
- MusicBrainz URI rationale (MBIDs vs names): https://groups.google.com/g/music-ontology-specification-group/c/gBOK8y3PhG0
- Phabricator: T114903, T284913, T272032 (https://phabricator.wikimedia.org/)
- A-semantika prototype: https://github.com/Ron-RONZZ-org/A-semantika
