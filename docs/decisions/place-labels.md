# Decision: Human-readable OSM place labels on Person pages

- **Status**: Accepted (Sep 2026)
- **Scope**: `wikibase.ronzz.org` — `Template:Person`, person vocabulary,
  `Special:AddPerson`/`UpdatePerson`, the person flows' statement layer
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The osm-places model stores ONLY the OSM external id
(`place of birth (OSM)` / `place of death (OSM)`, node|way|relation/<id>)
and `Template:Person` renders it with `{{#statements:…}}` — the raw
`relation/3169865` shows in the infobox. The request: display a
human-readable place label (e.g. "Paris, France") LINKED to the OSM map,
not the raw id.

The label is not derivable from the id server-side without an external
reverse-geocode on every render, and the id alone is a poor infobox value.
Decision (user-approved): **store the display label at creation/update
time and backfill the existing items**.

## Decision

### 1. New string properties (the OSM ids stay the canonical fact)

Two new `string` properties — `place of birth (label)` /
`place of death (label)` — are added to the person vocabulary
(manifests `properties.csv`, `personProperties` config-map keys
`placeOfBirthLabel`/`placeOfDeathLabel`; the config map getter is
additive, so instances seeded before this feature degrade gracefully to no
labels). They hold the human-readable display name captured from the
Nominatim suggestion / auto-match; the OSM external-id statement remains
the dereferenceable fact.

### 2. Capture at creation and update

- **Harvest auto-match** (`SpecialAddPerson::harvestContent`): the
  server-side Nominatim top match already returns `displayName` — it now
  rides along as `placeOfBirthOsmLabel`/`placeOfDeathOsmLabel` in the
  session record.
- **Manual/review combobox pick** (`osmsuggest.js`): a picked suggestion
  writes its display name into hidden label fields
  (`wpplaceOfBirthOsmLabel` / `wpplaceOfDeathOsmLabel`) rendered beside the
  OSM comboboxes. A change NOT produced by a menu pick (typed text, cleared
  input) clears the stored label — a stale display name must never ride
  along with a different (or missing) id.
- **Update flow** (`Special:UpdatePerson::recordFromItem`): prefills the
  hidden label fields from the stored statements, so untouched labels
  survive the no-clobber update contract. If the submitted OSM id CHANGED
  and no new label was submitted, `afterUpdate` removes the stored label
  (the pre-update id snapshot comes from the shared
  `UpdateExternalEntityFlow`); a blanked field keeps both id and label
  (no-clobber: blank is not removal).
- The flow layer (`SemanticEntityFlowService`) writes a label statement
  only when its OSM id statement is also being written — an orphan label
  is dropped, never silently swallowed.

### 3. Rendering — `{{#osm-place:}}`

New parser function (magic word spelling `osm-place`, args `birth`|`death`;
optional explicit `Qid` second arg for scratch/standalone use): reads the
sitelinked item's OSM id + label statements and renders the cell as a
wikitext external link — `[https://www.openstreetmap.org/<id> <label>]` —
falling back to the raw id as link text when no label is stored (older
items, hand-typed ids). The item page is registered as a parser-cache
dependency. `Template:Person`'s two place rows switch from
`{{#statements:…}}` to `{{#osm-place:birth}}` / `{{#osm-place:death}}`.

### 4. Backfill for existing items

`tools/backfill_osm_place_labels.py` finds every person item carrying an
OSM place statement WITHOUT its label statement (SPARQL, person-class
filtered), reverse-geocodes each id via the Nominatim REVERSE endpoint
(id → display name, paced at 1 req/s per Nominatim's usage policy) and adds
the label statement. Idempotent; `--verify` recounts the OSM statements
still missing labels; `--dry-run` plans without writes.

## Consequences

- The Person: infobox shows the place NAME linked to the OSM map; items
  without a stored label still show the raw id (linked), never break.
- Two more string statements per person at most (birth/death labels) —
  modest, human-readable, and captured rather than guessed.
- Existing persons (François Cheng Q1133, Alain Vaillant Q1696, …) get
  labels via the backfill tool after deploy.

## Tests

Unit: flow `statementSpecs` writes the label with its id and drops an
orphan label. Page-flow E2E: AddPerson manual creates a person with OSM ids
+ labels and asserts the label statements are stored and survive the Update
flow; a scratch page transcluding `{{#osm-place:birth|<qid>}}` renders the
label linked to the OSM id. Module-source regression: `osmsuggest.js` ships
the label-field resolution + clear-on-change logic.
