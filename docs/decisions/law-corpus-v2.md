# Law corpus v2 — constitutional bloc, Conseil constitutionnel, Cour de cassation, JADE

**Status:** implemented (FR); EU deferred. **Date:** 2026-11-23.

## Context

v1 imported only the DILA LEGI `code_en_vigueur` tree (77 codes, 252k article
rows). Four source families were therefore absent, and the citation agent
reported them as `law_not_found`:

| Source | Identifier | Where it lives |
|---|---|---|
| Constitution 1958 (art. 24/34/37) | `LEGITEXT000006071194` | LEGI `TNC_en_vigueur` (not the codes tree) |
| DDHC 1789 (art. 6) | `LEGITEXT000006071192` | LEGI `TNC_en_vigueur` |
| CC n° 85-197 DC | `CONSTEXT000017667855` | CONSTIT stock |
| Cour de cassation | `JURITEXT…` | CASS stock |
| Conseil d'État / JADE | `CETATEXT…` | JADE stock |

## Finding: v1 served abrogated article text

The LEGI tree carries every version of an article (`VIGUEUR`, `MODIFIE`,
`ABROGE`, `MODIFIE_MORT_NE`); the store keeps one row per
`(law_key, reference_code)`. v1 kept whichever version the directory walk
iterated last. Measured on production: 252,595 rows, only 118,955 `VIGUEUR`;
`Code civil` article 9 was the 1927 abrogated text instead of the 1970
privacy article. The ressource re-import with `LawVersionPolicy` fixes this
(`ETAT=VIGUEUR`, latest `DATE_DEBUT`) and `wb_law_articles` halves.

## Design

### Sources and parsers

`LawSourceParser` is the source boundary; `LawImporter` owns the store writes,
the run bookkeeping and the version policy.

| Source | Parser | Unit |
|---|---|---|
| LEGI codes + constitutional TNC | `LegiParser` (existing) | one article XML |
| CONSTIT | `ConstitParser` | one decision; one row per numbered considérant (`1`…`N`) + `visas` + `dispositif` |
| CASS | `CassParser` | one decision; one row (`arrêt`/`avis`/`ordonnance`/`décision`) |
| JADE | `JadeParser` | one decision; one row (`décision`) |

`LawImporter --source=legi|constit|cass|jade`. Bulk item creation is limited
to LEGI (`--create-items`); jurisprudence items are created on first citation
(`law-cite`) — 145k CASS + 7k CONSTIT + 61k JADE items would bloat the wiki.

### Schema 1.1

`upgradeLawSchema.php` adds, idempotently:

- `wb_law_laws.number` — the official number (NOR/case number/CELEX), searched
  by `law-search` so `85-197` finds the decision;
- `wb_law_laws.court` — the jurisdiction label, for the item's `court`
  statement;
- `wb_law_articles.source_url` — the official page of each provision
  (`codes/article_lc/…`, `loda/article_lc/…`, `juri/id/…`);
- the `number` index; and rewrites the legacy `source='dila'` rows to
  `source='legi'`.

`wb_law_laws.source` now names the corpus (`legi`, `constit`, `cass`, `jade`,
`eurlex`), so a refresh can target one source.

### Item model

- **Case law is cited as the decision item**, not as a child "provision of a
  law": `law-cite` returns the `legal case` item for a CONSTIT/CASS/JADE law
  key. `article` is optional in `action=lawcite` for those sources.
- New property **`legal identifier`** (string) carries the corpus key
  (LEGITEXT/JURITEXT/CONSTEXT/CELEX/ECLI) on every legal item;
  `LegalItemLink` reads it first and falls back to `legislation number` for
  legacy legislation items.
- New class **`court`** (Wikidata Q41487) and the court items created by
  `createLawCourtItems.php` (Conseil constitutionnel, Cour de cassation,
  Tribunal des conflits, Conseil d'État). The writer resolves the `court`
  statement by exact label and skips it when the item is absent (e.g. a CAA).
- `LegalItemLink` accepts `legal case` (and `treaty`) alongside `legislation`.

### Citations

`citation-source-property-map.json` gains `authority` → `court`,
`jurisdiction` → `territorial jurisdiction (label)` and `number` → `case
number` (the legislation fallback to `legislation number` stays);
`CitationMapReader` accepts the two new CSL fields. `issued` now carries
`[year, month, day]` when the statement has day precision, and
`CitationFormatter::formatLegalCase` renders the French case citation. A
corpus label that already names the court (our decisions) is rendered as-is;
a hand-created case composes `authority, n° <number>, du <date>, <title>`.

## Operations

1. `upgradeLawSchema.php` (DB columns + `source='legi'`).
2. Re-import the LEGI baseline with the in-force policy + `--prune`
   (repairs v1; ~119k rows).
3. Extract the constitutional bloc from the same baseline
   (`JORFTEXT000000571356`, `JORFTEXT000000697056`, `LEGITEXT000006071193`,
   `LEGITEXT000006051372`) and import it with `--all-legi`.
4. Seed the new vocabulary (`properties,classes,languages,preseed,config`) →
   `legal identifier` + `court`; `createLawCourtItems.php`; re-import the
   citation maps (`importCitationMap.php --force`).
5. Import CONSTIT (13 MB stock), CASS (252 MB stock, 145k decisions) and JADE
   `publie` (569 MB XML, 61k decisions). JADE `inedit` (5.9 GB XML) is
   deferred: it does not fit the current 96 GB volume alongside CASS; run it
   after a second volume is attached.
6. Crons: LEGI daily (existing); CONSTIT/CASS/JADE monthly stock refresh
   (upsert; the stocks are cumulative); `--prune` only for the LEGI repair.

## Verification

- `law-search "Constitution"` → `LEGITEXT000006071194`.
- `law-get LEGITEXT000006071194 article=24|34|37`;
  `law-get LEGITEXT000006071192 article=6`.
- `law-get CONSTEXT000017667855` → 38 considérants; `law-cite` → the decision
  item.
- `law-get JURITEXT… article=arrêt`; `law-get CETATEXT… article=décision`.
- `Code civil art. 9` is the 1970 privacy text after the repair.

## Out of scope

- EU corpus: waits for the full EUR-Lex sector-3 dump (EU Login). The Cellar
  fallback is verified (`Accept: application/xml;type=fmx4`) but no curated
  set is imported, per the 2026-11-23 decision.
- JADE `inedit` (5.9 GB): deferred to a second volume.
