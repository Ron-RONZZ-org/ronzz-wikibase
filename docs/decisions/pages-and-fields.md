# Decision: Classic wiki pages for `Special:AddPerson` / `AddSource` / `AddCollective`, source-field fixes, person lifecycle fields, collective classes

- **Status**: Accepted (Aug 23 2026)
- **Scope**: `wikibase.ronzz.org` — the external-entity creation flows (`Special:AddPerson`,
  `Special:AddSource`, `Special:AddCollective`), their classic wiki pages, and their vocabulary
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

`Special:AddSoftware` (issue #26) auto-creates a site-linked classic wiki page (`FOSS:<Name>`,
transcluding `Template:FOSS`) whose infobox renders the item's statements at view time — the
"prose on the page, facts in the item" pattern. The issue-#7 pages (`AddPerson` / `AddSource` /
`AddCollective`) only created bare items. Editors asked for the same page experience there, plus
field-level fixes discovered while planning: the `AddSource` class picker wording, `bookExcerpt`
specifics (chapters/volume, description autogen, parent inference), person lifecycle facts
(birth/death), and common collective classes.

## Decision

1. **Classic pages for all three kinds** — the page machinery moved from `SpecialAddSoftware`
   into the base class `SpecialAddExternalEntity` (dedup): `afterCreate()` writes the page,
   sitelinks page↔item, and routes through a `complete/<id>` finalize step that strips the
   pending marker in a fresh request (the sitelink must be durably visible for the page's
   `wikibase_item` property to be set at parse time). Subclasses declare `pageNamespace()`,
   `pageTemplate()`, `pageSkeleton()`, `pageEditSummary()`, `pageSitelinkSummary()`.
   - `Person:` (NS 2010/2011) — `Template:Person`, Biography/Works sections.
   - `Collective:` (NS 2014/2015) — `Template:Collective`, Overview/History/Members sections.
   - `Source:` (NS 2012/2013) — **per-class templates** (`Template:Book`, `Template:ScholarlyArticle`,
     `Template:Website`, `Template:Song`, `Template:Film`, `Template:Video`,
     `Template:YouTubeChannel`, `Template:YouTubeVideo`, `Template:Webpage`), one shared
     skeleton shape with an Overview/Content/See also layout.
2. **`bookExcerpt` gets NO page** — it is part of a book, not a standalone work; `pageTitleForRecord()`
   returns null for it (the item-only redirect applies). All other source classes get pages,
   including the other child classes (`webpage`, `youtubeVideo` — they are standalone web pages).
3. **`Special:AddSource` prerequisite fixes** — the class picker says "Source type" (en; fr/eo
   already did) and its submit button is "Continue" (new message; the selection step keeps
   "Review and correct", which is correct there). `bookExcerpt` gains optional **chapters**
   (new string property, P2635-aligned — "number of parts of this work", the closest Wikidata
   match) and **volume** (the existing P478 property) fields.
4. **`bookExcerpt` description autogen + parent inference** — a blank description auto-generates
   as `Pages a-b (Volume c) of {book}` from the pages/volume fields + the parent book's label
   (help: "will be auto-generated if left blank"); blank year/authors copy the parent book's
   `date` (year) and `attributed to` statements (help: "Leave blank if same as the parent book").
   The authors field is not HTMLForm-required for bookExcerpt, and the inference runs *before*
   the author validation.
5. **Persistence fixes (prerequisite)** — the review/manual **description is persisted as the
   item's English term** (it was silently discarded) and the source **year is persisted as a
   `date` statement** (year precision; it was display-only, and the citation engine already reads
   `date` as `publicationDate`). The description field's maxlength dropped 500 → 250 to match
   Wikibase's term limit. The dogfood book gained a year (1843) + author (Ada Lovelace) so it
   doubles as an inference parent.
6. **`Special:AddPerson` lifecycle fields** — VIAF ID (P214) and ISNI (P213) search fields
   (Wikidata-hub-only lookups; VIAF can map to several items — LIMIT-1 contract, corrected at
   review). Review/manual fields: day-precision **date of birth / date of death** (time
   properties P569/P570, stored as day-precision TimeValues) and entity-combobox **place of
   birth / place of death** (wikibase-item properties P19/P20), with a **"This person is
   deceased"** toggle (default off) revealing the death fields via `hide-if`.
7. **Collective classes** — 11 new agent classes in the manifest (Wikidata-aligned): private
   company (Q1589009), public company (Q891723), non-profit organization (Q163740),
   governmental agency (Q327333), music band (Q215380), educational institution (Q2385804),
   research institute (Q31855), political party (Q7278), trade union (Q178790), religious
   organization (Q1530022), sports team (Q12973014). They appear in the `AddCollective` class
   picker (which excludes only `person`), feed the harvest class inference via
   `agentClassByWikidata`, and are valid author classes for `AddSource`.

## What this does NOT do (and why)

- **No automatic page for every existing item** — only items created *through* the three flows
  get pages (the sitelink-tab manual path is unchanged).
- **No page-title disambiguation** — `Person:<label>` uses the primary label verbatim; an
  existing page at that title is left alone and the sitelink is asserted anyway (same idempotent
  contract as `FOSS:`, and the per-kind namespaces make collisions unlikely).
- **No VIAF/ISNI cascade** — only the Wikidata hub resolves them (no other provider has the
  endpoint); ORCID keeps its cascade.
- **No place-item creation** — places reference existing local items (entity combobox); a place
  item must be created first (properties-first house rule).
- **No multi-match VIAF selection** — a VIAF that resolves to several Wikidata items returns the
  first SPARQL match (like ORCID today); the review step allows correcting the pick.

## Consequences

- **Vocabulary (data, not code)**: +5 properties — `date of birth` (P569), `place of birth`
  (P19), `date of death` (P570), `place of death` (P20), `chapters` (P2635-aligned); +11 classes
  (above). Config map: `personProperties` (new section), `sourceProperties.chapters`,
  `agentClasses` (+11 keys).
- **Namespaces**: `Person`/`Source`/`Collective` + talk in dev config and production
  LocalSettings (mirroring the FOSS block); on-wiki templates `Template:Person`, `Template:Collective`
  and the 9 source templates (per-class), each rendering the sitelinked item's statements.
- **Dedup**: `statementSpecs()`, `harvest()`/`canHarvest()`, the page machinery, `parseItemId()`
  and `dateToTimeValue()` now live once in the base class (AddSoftware lost ~230 lines of
  page logic); behavior preserved (same E2E flows).
- **Deployment**: seed re-run (new props/classes → config re-emission), namespace blocks +
  templates on the instance, `rebuildtextindex.php` after bulk authoring (custom namespaces are
  not searched by default). E2E page flows updated to resolve the created item from the classic
  page's `wikibase_item` property and to assert the `bookExcerpt` no-page caveat.
