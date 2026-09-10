# Decision: AddSource round 4 — optional fields, multi-value jurisdiction, international marker, `text` class

- **Status**: Accepted (Sep 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSource` / `Special:UpdateSource`,
  the `action=addsource` API contract, the `{{#osm-place:jurisdiction}}`
  renderer, and the source vocabulary
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Three usability gaps in the AddSource flow, plus a production 500:

1. **Title should be the only required field.** Every source class demanded at
   least one author, but authorship is genuinely unknown for many historical
   texts — the flow blocked legitimate records (a text of unknown authorship,
   an anonymous work).
2. **Territorial jurisdiction is multi-value.** A legal text can concern
   several jurisdictions (a treaty signed by many countries), and an
   international text has no single territorial jurisdiction at all.
3. **No catch-all source type.** Texts that are not a book, article, document,
   thesis, manuscript, … had to be mis-classified.
4. **Uncaught `TypeError` (HTTP 500)** on `Special:AddSource/treaty/manual`:
   `SpecialAddExternalEntity::createItemAndRedirect()` was declared `: bool`
   but returns an error string on two paths (the required-label error and the
   creation-error catch). PHP threw the `TypeError` before HTMLForm could
   display the message as a form error.

## Decision

### 1. Return-type fix

`createItemAndRedirect()` and its caller `onDuplicateCreateSubmit()` are
declared `bool|string` — HTMLForm submit callbacks accept a string as a
form-error message (the docblocks already said so). A regression E2E posts a
5-digit `wpissuedYear` (it passes the client-side `maxlength`, then is
rejected by the flow service's year validation) and asserts the form-error
page, never a 500.

### 2. Title is the only required field

`SourceFieldMap::requiredOnCreate()` returns `['title']` (plus `parent` for
the child classes, where the part-of relation is structural, not
bibliographic metadata). Authors are optional everywhere:

- the form's `authors` field `required => false`;
- `SpecialAddSource::validateAuthors()` and
  `SourceFlowService::validateAuthors()` validate only the ids actually
  supplied (an empty list is legitimate);
- the `action=addsource-fields` discovery endpoint reflects the new rule.

### 3. Multi-value territorial jurisdiction

- The `territorialJurisdiction` field is a multi OSM combobox
  (comma-separated `node|way|relation/<id>` values), wired by the extended
  `resources/osmsuggest.js`.
- The hidden label sibling stores a **JSON map** `{"relation/123":"France",…}`
  — pairing the display name to its id is drift-proof and a stale entry for a
  removed id is pruned on submit. The legacy plain-string label (items created
  before the rework) still renders, attached to the first id.
- `Spec/JurisdictionList` is the one pure helper (`segments` / `allValid` /
  `split` / `labels` / `encodeLabels` / `links`) shared by the form's
  `beforeCreate`, `SourceFlowService::statementSpecs`, the
  `{{#osm-place:jurisdiction}}` renderer and the Update prefill — the encoding
  can never drift.
- Storage: one `territorial jurisdiction (OSM)` statement per id, plus one
  JSON `territorial jurisdiction (label)` statement.

### 4. International marker

- New **boolean** property `international` (property manifest +
  `sourceProperties` config key; the property manifest now accepts the
  `boolean` datatype).
- The four legal classes (legalCase / legislation / bill / treaty) carry a
  per-class checkbox ("This is an international treaty", …). Checking it
  **replaces** the jurisdiction field: the jurisdiction is hidden (OOUI
  `hide-if`) and cleared server-side, and the boolean marker is written.
- The renderer (`{{#osm-place:jurisdiction}}`) shows the localized
  "International" label in the Jurisdiction cell when no OSM id exists and
  the marker is true.
- The checkbox is always managed by the form: unchecked writes `false` (an
  explicit, queryable marker), and switching to international on update
  emits the jurisdiction property keys with no values so stale jurisdiction
  statements are removed (the reverse switch writes `false` + the new ids).

### 5. `text` catch-all class

New source class **`text`** (Wikidata Q234460-aligned: "any text — a
historical text, an inscription, a document of uncertain nature"), manual-only
(no external authority), with the field set `title, description, authors,
year, url, accessUrl, wikidataId` and a `Source:` page (skeleton transcludes
`Template:Text` — an on-wiki content-creation task). Citation map: CSL
`document`.

## Consequences

- **Re-seed + redeploy required**: the `text` class, the `international`
  property and the `sourceClasses` / `sourceProperties` config map reach the
  instance only through a full seed re-emission (never `--only=config`).
  Before that, the extension degrades gracefully — absent config keys mean
  the class and the field are simply absent.
- **`boolean` datatype** is now accepted by the property manifest
  (`ManifestReader` + `seed/manifest_loader.py`).
- **On-wiki template**: `Template:Text` must be created (content-creation
  task) for the `Source:` skeleton to render a styled infobox.
- **API contract change** (documented by `action=addsource-fields`):
  `authors` is no longer required on create; `territorialJurisdiction` is a
  comma-separated id list with a JSON label map; `international` is a new
  boolean field.
- **Every legal item carries an explicit `international` true/false
  statement** (the form always manages the checkbox) — queryable and
  unambiguous.
- **Multi-jurisdiction labels are a JSON string** in the `… (label)` property;
  a hand-edit that replaces it with a plain string still renders (attached to
  the first id).
