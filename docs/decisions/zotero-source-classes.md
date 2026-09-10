# Decision: Zotero/CSL-aligned `Special:AddSource` classes + class-scoped entity comboboxes

- **Status**: Accepted (Sep 2026)
- **Scope**: `wikibase.ronzz.org` — the source-import flow (`Special:AddSource` /
  `Special:UpdateSource`), the shared entity comboboxes, and their vocabulary
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Two problems surfaced together:

1. **Too few source types.** `Special:AddSource` offered ten classes (book,
   scholarly article, website, song, film, video, YouTube channel/video,
   webpage, book excerpt). Editors citing newspapers, legal texts, official
   documents, theses, patents, etc. had to mis-classify them as a scholarly
   article or a book. Citation managers (Zotero) and CSL define a much wider
   set of item types the citation engine can already render.
2. **The entity comboboxes searched the whole instance.** Every
   `wb-entity-combobox` (license, author, developer, OS, UI, programming
   language, publisher, journal, parent…) called `action=entitysearch`, which
   runs a CONTAINS match over the entire term store — typing in the license
   field suggested arbitrary `Q…` items. The license field was also
   re-implemented at four call sites, and `instance-of` filtering was copied
   near-verbatim in five places.

## Decision

### 1. Class-scoped entity search

`action=entitysearch` gains an optional **`classes=Q302|Q5|…`** parameter.
The term match runs first (it cannot join `instance of` — the claim value is a
serialized blob, not a queryable column), then candidates are **over-fetched
and filtered in PHP** by `instance of` (`EntityClassFilter::hasAnyClass`). The
over-fetch is bounded (`CLASS_FILTER_FACTOR = 5`, `MAX_SCAN = 200`): it is the
accepted trade-off for a class filter that needs no schema-level index, and it
is documented on the API. The combobox field carries the scope as
`data-wb-classes` (`Fields\OOUIComboboxField`), which `entitysuggest.js`
forwards.

### 2. One combobox builder, one class filter

- `Fields\EntityCombobox` is the single builder for every entity combobox
  (single/multi, scope, options, help). All twelve call sites route through it,
  and the four license implementations collapse into `licenseSpec()`
  (class-scoped to `software license`, options from the seed's license map).
- `Fields\OOUIComboboxField` (moved from `Upload/`) is the one HTMLForm field;
  it forces the OOUI widget in php-mode forms and emits the class scope.
- `EntityClassFilter` is the one `instance of` helper (`parseItemIds` +
  `hasAnyClass`/`hasClass`), replacing five copies; it is pure and unit-tested.
- The seed emits a **`domainClasses`** map (software license, operating system,
  user interface, programming language, publisher, scholarly journal) so the
  scope ids are instance-specific config, never hardcoded.

### 3. Zotero/CSL-aligned classes

Sixteen new source classes, each with an en/fr/eo label, a schema.org and a
Wikidata alignment:

| Class | CSL type | Authority search |
|-------|----------|------------------|
| newspaper article | `article-newspaper` | title/author |
| magazine article | `article-magazine` | title/author |
| conference paper | `paper-conference` | title/author/DOI |
| report | `report` | title/author |
| document | `document` | manual |
| thesis | `thesis` | title/author |
| manuscript | `manuscript` | manual |
| patent | `patent` | manual |
| legal case | `legal_case` | manual |
| legislation | `legislation` | manual |
| bill | `bill` | manual |
| treaty | `treaty` | manual |
| interview | `interview` | manual |
| map | `map` | manual |
| presentation | `speech` | manual |
| dataset | `dataset` | manual |

Plus two **domain classes** used only to scope comboboxes: **publisher**
(Q2085381) and **scholarly journal** (Q737498). The publisher combobox is
scoped to the publisher class **and** the agent classes (existing publisher
items are organizations); the journal combobox to the scholarly-journal class.

### 4. New properties

- **court** (item, P4884-aligned) — the court a legal case was heard in.
- **territorial jurisdiction (OSM)** (external-id, formatter
  `https://www.openstreetmap.org/$1`) + **territorial jurisdiction (label)**
  (string) — the legal texts' territorial jurisdiction, mirroring the OSM
  place-of-birth pattern (Nominatim combobox + hidden label,
  `{{#osm-place:jurisdiction}}` rendering).
- **case number**, **patent number** (P1246-aligned), **report number**,
  **legislation number** (strings; no clean Wikidata property exists for the
  numbers — they are unaligned, like the existing `chapters`).

### 5. Authors are optional for legal texts

The legal classes (`legalCase`, `legislation`, `bill`, `treaty`) expose **no
authors** — the court / jurisdiction carry the attribution. The author
requirement now follows the field contract (`SourceFieldMap::acceptsField`),
so it cannot drift between the form, the flow service and the API.

### 6. Citation mappings

The new classes join `WikibaseCitation/manifests/citation-source-type-map.json`
with their CSL types, so `{{#cite}}` / `action=citation` render them natively.

## Consequences

- **Re-seed + redeploy required**: the new classes/properties and the
  `domainClasses` map only reach the instance after a full seed re-emission
  (never `--only=config`) and a deploy. The extension degrades gracefully
  before that (absent config keys → unscoped search, unknown classes simply
  absent from the picker).
- **On-wiki templates**: each new class needs a `Template:<Class>` page for
  its `Source:` skeleton to render a styled infobox (content-creation task,
  not code). Until created, the skeleton transcludes a redlink.
- **Class-scoped search costs extra entity loads** when a scope is set
  (bounded over-fetch). Acceptable at this instance's size; a schema-level
  index (or CirrusSearch) is the documented upgrade path if it ever bites.
- **Publisher/journal scoping is best-effort**: a publisher item that is
  neither a `publisher` nor an agent-class item will not be suggested (a
  typed item id is still accepted — the fields are lenient, matching the
  existing publisher/journal contract).
