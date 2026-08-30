# Decision: OpenStreetMap place fields on AddPerson/UpdatePerson

- **Status**: Accepted (Aug 30 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddPerson`/`Special:UpdatePerson`
  place-of-birth/death fields, the person vocabulary, `Template:Person`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The person forms asked for place of birth/death as **local-item** entity
comboboxes (the P19/P20-aligned `wikibase-item` properties P51/P53): a
harvested place label was matched against local items, and a missing item
meant "create the place item first". That model does not scale — every
village, city and institution on Earth would need a local item, and the
contributors' first instinct ("I just want to record where they were born")
hits a dead end. The request: outsource places to **OpenStreetMap** — the
fields become search comboboxes over Nominatim, and the **OSM id** is stored
in the record. (No place items, no manual place creation.)

## Decision

### 1. New external-id properties (the item-typed ones stay, unused)

Wikibase property **datatypes are immutable**, so the item-typed P51/P53
cannot carry an OSM id. Two new `external-id` properties join the person
vocabulary:

- `place of birth (OSM)` / `place of death (OSM)`, datatype `external-id`,
  formatter URL `https://www.openstreetmap.org/$1`, unaligned (OSM has no
  property system to mirror). The seed's D1 importer + re-emission create
  them and set the formatter URL.
- The legacy P51/P53 stay in the vocabulary (aligned to P19/P20) but the
  forms **no longer write them** — the place fact lives in OpenStreetMap.
  Production has zero P51/P53 statements today (verified 2026-08-30), so no
  migration is needed.

### 2. Value form: `node|way|relation/<id>`

Nominatim returns `osm_type` ∈ {node, way, relation} + `osm_id`; the stored
value is the canonical `node/261512419` form (the same format Wikidata's
OSM tags use). `https://www.openstreetmap.org/$1` dereferences it directly.
Server-side gate: `OsmPlace::isValidId` (`(node|way|relation)/[1-9]\d*`) —
a raw place name is a **form error** on submit, never a silent drop.

### 3. Form UX: Nominatim search combobox

- The fields become OOUI comboboxes with the `wb-osm-combobox` cssclass,
  wired by the new `osmsuggest.js` module to
  `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=8`
  (**browser-first**: Nominatim's CORS is open and its usage policy
  explicitly covers client-side search; the server never proxies
  search-as-you-type, keeping rate limits and the SSRF surface minimal).
  Picking a suggestion fills the field with `osm_type/osm_id`.

### 4. Harvest-time auto-match (fetch-match-confirm, the portrait-license pattern)

When the Wikidata person record supplies a place **label** (e.g.
"Cambridge"), `harvestContent` (harvest-on-pick) runs **one** server-side
Nominatim lookup — `NominatimProvider`, a fixed-host SSRF-allowlisted
provider behind the shared rate limiter at Nominatim's 1 req/s usage
minimum:

- **Top match** → the field is prefilled with the `node|way|relation/<id>`
  value AND the standard fetch-match-confirm banner renders:
  *"Place of birth fetched from source: Cambridge, we think this
  corresponds to Cambridge, Cambridgeshire, England (relation/295355)."*
  [Yes, that's right] / [No, let me correct] (`entityConfirmHtml` +
  `entityconfirm.js` — the exact portrait-license / harvested-publisher
  pattern). "No" clears the field and focuses the combobox.
- **No match / Nominatim unreachable** → the field stays empty with the
  *"External record: Cambridge — search OpenStreetMap to confirm"* hint.
- The match is stored in the session record at harvest time (one lookup
  per harvested label, never per review render); a **stored OSM id** (the
  Update flow's `recordFromItem`) prefills the field unchanged, no banner.
- The deceased toggle keeps hiding the death fields, exactly as before.

### 5. Rendering

`Template:Person`'s place rows switch to
`{{#statements:place of birth (OSM)}}` / `{{#statements:place of death (OSM)}}`
— the external-id value renders as a link to openstreetmap.org via the
formatter URL, matching the existing VIAF/ORCID/ISNI row pattern.

## Consequences

- Place facts are stored against an authoritative, dereferenceable external
  id — no local place items, no place-creation backlog.
- The classic person page shows a clickable OSM link per place (the id
  itself is the label; the reader follows it to the map/name — the same
  UX as the other external-id rows).
- A harvested place is auto-matched on Nominatim and the contributor
  confirms the top hit with the [Yes/No] banner (one click when the match
  is right); a miss keeps the search-as-you-type combobox. The manual
  flow and the no-match case require picking from the suggestions.
- The Wikidata-hub harvest still delivers place *labels*; resolving a
  harvested place's OSM id via the Wikidata place item's P402 (instead of
  a Nominatim label search) is a possible future refinement, out of scope
  here.
