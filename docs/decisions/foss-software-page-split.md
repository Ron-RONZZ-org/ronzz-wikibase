# Decision: FOSS:/Software: page split on `Special:AddSoftware`, collective-author citations, fulltext combobox search

- **Status**: Accepted (Aug 31 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSoftware` / `Special:UpdateSoftware` /
  `action=addsemanticentity` (classic-page namespace), the `{{#cite:}}` author rendering,
  the entity comboboxes on the `Special:Add*` / `Special:Upload` pages
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Three follow-up bugs/requests after the FOSS software docs launch (issue #30):

1. **Citation-gen bug** — citing a content item whose author is a *collective* rendered the
   collective like a personal name: `Foundation, W.` instead of `Wikimedia Foundation`.
   `StatementToCslConverter::authorName()` had no concept of a non-person author — an author
   item without `given name`/`family name` statements fell into the deterministic label split
   (last word = family).
2. **`Special:AddSoftware` page kind** — the flow *always* created a `FOSS:<Name>` page, even
   for software whose license is not free/open-source (e.g. CC BY-NC/ND). A non-FOSS program
   documented on a `FOSS:` page is semantically wrong; editors wanted a `Software:<Name>` page
   for it.
3. **Combobox search is prefix-only** — the entity comboboxes (Add* pages, Special:Upload,
   the sitelink tab) queried `wbsearchentities`, which on this instance (no CirrusSearch)
   matches labels/aliases exact-then-prefix. Searching `AGPL` never found `GNU AGPL-3.0`
   and `Einstein` never found `Albert Einstein`.

## Decision

### 1. Collective authors render as family-only CSL names

`StatementToCslConverter` gains a **person-class** config (`WikibaseCitationPersonClass`,
falling back to `EmbeddableContentConfig['agentClasses']['person']`). An item-typed author
whose `instance of` classes do **not** include the person class (and which has *any* class —
an unclassified item keeps the legacy split, never inventing a collective) renders as a
FAMILY-ONLY CSL name — `{"family": "Wikimedia Foundation"}` — which APA/Vancouver render
verbatim. ⚠️ The CSL `literal` name field would be the spec-correct form, but the in-process
citeproc-php processor (v2.7.1, and upstream master) **drops literal names entirely**
(verified: an APA citation with a `literal` author renders no author), so the family-only
representation is the one that actually renders — in all five styles (JSON/BibTeX/RIS native
serializers handle family-only too). String-valued authors keep the legacy split (no class
information exists for them); the single-word fallback also uses the family form (a `literal`
would have rendered an empty author).

### 2. FOSS:/Software: page split on software creation

- **New class** `free software license` (classes.csv; aligned, no number-mirroring). The
  preseed license rows gain a **`foss` flag** (`preseed.csv`): the FSF/OSI line — everything
  except the non-commercial / no-derivatives CC variants (CC BY-NC*, CC BY-ND*) is flagged.
- **Seed** classifies every `foss`-flagged license `instance of` BOTH `software license` (the
  existing class that keeps it in the license combobox) and `free software license`, and
  **re-classifies existing items** on re-run (idempotent `add_claims`). The seed emits a
  `fossLicenseClasses` config map; `EmbeddableContentConfig::fossLicenseClasses()` reads it.
- **`Special:AddSoftware` asks per create**: a **Page kind radio** on the review/manual form —
  `FOSS:` page or `Software:` page — defaulting from the license (ANY chosen license
  classified as a free software license → `FOSS:`; no license keeps the historical `FOSS:`
  default). The base class's page creation reads record-aware `pageNamespaceForRecord()` /
  `pageTemplateForRecord()` (defaulting to the old `pageNamespace()` / `pageTemplate()`
  contract), so create, update-heal and update-rename all honor the per-record kind.
- **New `Software:` namespace** (NS 2016/2017) + `Template:Software` (+ `/Infobox`, on-wiki),
  mirroring the FOSS block in dev config and production LocalSettings.
- **`Special:UpdateSoftware`** recomputes the kind from the updated license; a FOSS↔Software
  flip **moves** the page across namespaces (`renameClassicPage` now derives the old title
  from the sitelink and the new title from the record's namespace).
- **`action=addsemanticentity` kind=software** accepts an optional `pageKind` (foss|software),
  defaulting from the license via the shared `SoftwarePageKind` helper (also used by the form
  — one rule, two surfaces).
- **`tools/backfill_classic_pages.py`** supports `{"software": true}` ns-map entries resolved
  per item from the license statements (`--license-property` + `--software-license-ids`).
- The item **class** stays `free and open-source software` regardless of the page kind (the
  AddSoftware class picker contract). A follow-up "software" class for non-FOSS items is
  flagged as future work — the page split itself is the requested scope.

### 3. Fulltext combobox search via `action=entitysearch`

A read-only extension API module (`action=entitysearch`) runs a **contains** match
(`LIKE %term%`, the same `wbt_*` term tables Wikibase's `DatabaseMatchingTermsLookup` reads)
over labels + aliases, querying the raw + title-cased + uppercase variants of the typed text
(the instance's term store is case-sensitive, VARBINARY `wbx_text` — T242644), merging
deduped hits, and resolving display labels/descriptions with the configured language
fallback. Result shape mirrors `wbsearchentities` (`search[].id/label/description`), so the
combobox consumers switch with a one-line API swap: `entitysuggest.js` (Add* + Special:Upload)
and the sitelink-tab dialog.

The direct `wbt_*` SQL is a documented deviation from "stable Wikibase API only": it is
read-only, in-process, mirrors Wikibase's own query builder, and the schema is stable across
1.4x (term-type ids hardcoded per upstream `TermTypeIds` — ADR 0027 dropped the `wbt_type`
table). The alternative — CirrusSearch + WikibaseCirrusSearch (the Wikidata-grade fulltext,
`wbsearchentities` itself becomes fulltext) — requires running **Elasticsearch** (~1–1.5 GB
RAM, a second JVM service beside WDQS, index lifecycle, dev/CI changes) and was rejected as
disproportionate for an instance of this size; it stays the documented upgrade path if the
instance grows.

## Consequences

- Citations of collective authors render correctly in every style (family-only names), and the
  classic `Person:`/`Source:`/`Collective:` author treatment is unchanged for classified
  persons.
- Non-FOSS software gets its own page namespace; the license drives the default, the editor
  has the final say via the radio. Existing FOSS pages are untouched (no migration); the
  update flow moves pages when the license flips.
- Combobox suggestions now find mid-word and acronym matches ("AGPL", "Einstein",
  "ovelace" → "Ada Lovelace") across every entity-typed field.

## Deployment notes (wiki + runbook)

- Production `LocalSettings.php` gains the `Software:` namespace block (NS 2016/2017, mirror
  the FOSS block) — see `RonzzIT:Deployment/Wikibase`.
- `Template:Software` + `Template:Software/Infobox` are on-wiki deploy items.
- Re-run the seed's `preseed` phase (with `classes` first) to classify the FOSS licenses and
  re-classify existing license items (idempotent).
- The `integration` CI stack seeds the same vocabulary, so the E2E flows cover the split.
