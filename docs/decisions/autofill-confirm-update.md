# Decision: Autofill confirm + Special:Update* pages

- **Status**: Accepted (Aug 26 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:Upload` + the Add\* entity
  fields (autofill confirmation), the Add\* portrait/logo upload-metadata
  wiring fix, and the new `Special:Update*` pages
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Two editor-facing problems: (a) entity-typed form fields (license, publisher,
journal, author, …) were either **not** auto-filled from fetched source data
or filled **silently** on an exact-label match — a wrong guess went unnoticed
until the item was saved; (b) keeping an item's "basic information" current
(typo in a name, wrong publisher, stale description) required re-creating the
item or hand-editing raw statements — there was no form for editing an
existing item.

A third, latent bug surfaced during the audit: the Add\* portrait/logo
"Validate" button and the `Special:Upload` license autofill **never worked**
— `uploadmeta.js` targeted field ids (`wpportraitUrl`, `wpLicense`) that the
rendered forms do not carry: OOUI forms name the inner `<input>` after the
field (`input[name=…]`) with an auto-generated id, and
`OOUIComboboxField`'s explicit id lands on the widget **wrapper**, not the
input. The wiring span rendered, but `$('#wpportraitUrl')` resolved to
nothing, so the button was never injected and the license autofill silently
no-opped.

## Decision

### 1. Entity-field autofill with explicit confirmation

- **Matching**: a fetched STRING for an entity field (upload-metadata license
  on `Special:Upload`/Add\* validate; harvested publisher/journal on the
  AddSource review; harvested developer/license/programming-language facts on
  AddSoftware; a free-text author NAME in the AddSource search) is resolved
  to an existing item: **exact label match first** (the legacy behaviour),
  then a **fuzzy match** (`EntityLabelMatcher`, a pure-PHP scorer: exact →
  prefix → token-containment → Levenshtein, threshold 0.75, against the same
  `EntitySearchHelper` the combobox uses, with the case-variant queries the
  instance's case-sensitive term store needs).
- **Confirmation**: a match PRE-FILLS the combobox **and** renders a
  confirmation banner in the field row — *"{field} fetched from source:
  {value}, we think this corresponds to {label} (Q#)."* with
  **[Yes, that's right]** / **[No, let me correct]** (No clears the field and
  focuses the combobox). The banner is the guard against fuzzy false
  positives — matching is deliberately generous because a wrong guess is now
  recoverable at confirmation time.
- **No good match → keep the current flow**: the field stays empty and the
  plain hint renders (the pre-existing "create the item first" / harvested
  label hints).
- **Two render paths**:
  - **Server-rendered** (Add\* review/manual forms): the banner is generated
    in PHP (`entityConfirmHtml`) into the field's help slot; a small
    `entityconfirm.js` wires the buttons. Works for publisher/journal/
    authors/software-facts.
  - **Client-side** (`uploadmeta.js` validate): the fetched license label is
    scored against the `wbsearchentities` candidates (a JS port of the same
    scorer); a good match fills the license combobox and injects the same
    banner next to it. Licenses are the only client-side-fetched entity
    strings, so no new API endpoint is needed.
- **Consistency**: exact matches now also confirm (the copy says "we think")
  — a label collision is still a guess.

### 2. Upload-metadata wiring fix (`uploadmeta.js`)

- The field targets are the HTMLForm field **names**; the lookup now
  resolves id → inner input → `input[name=…]`, so the Add\* validate button
  renders, the license/author/license-info autofill lands on the real
  `<input>`, and the Wikimedia blob-fallback file fill works on both
  surfaces. OOUI's TextInputWidget binds `change`/`input` on its input, so
  the direct value set + native event re-syncs the widget.

### 3. Special:Update* pages + the Item-page button

- New pages — **UpdatePerson / UpdateSource / UpdateCollective /
  UpdateSoftware / UpdateFictionalCharacter** — each **extends its Add\*
  counterpart** (inheriting `reviewFieldSpecs`, `statementSpecs`,
  `beforeCreate`, …) and mixes in `UpdateExternalEntityFlow` (the shared
  update flow):
  - URL `Special:UpdatePerson/Q42` (the class is detected from the item's
    instance-of; the update form renders the class as a hidden field — no
    re-classification on "basic information").
  - The form shows the **exact same fields as the Add\* review step**,
    prefilled from the item's statements (`recordFromItem`, the reverse of
    `statementSpecs`, per kind).
  - Submit re-runs the Add\* validation, then **replaces the managed
    statements** (the form's property set ∪ the new specs) and updates the
    en label/description; **everything else is untouched** (sitelinks,
    non-managed statements, references on other statements).
  - **Uploads are opt-in, preserved by default**: the portrait/logo section
    toggle defaults unchecked, so an existing portrait/logo survives an
    update; a new upload replaces it (its property ids arrive via the new
    specs). The AddSource **access file** is kept when the mode stays
    file/download without a new file/URL (a relaxed access validation — the
    Add\* create-time upload requirement would otherwise block every update
    of an item that already has a file).
  - A **label change renames the classic page** (best-effort `MovePage` +
    sitelink update, subpages/talk included); a failed move keeps the old
    page — the item update is never rolled back.
- **Item-page button**: `Hooks::onBeforePageDisplay` (item namespace)
  detects the item's class via the config-derived class→Update map
  (source → UpdateSource, person → UpdatePerson, other agents →
  UpdateCollective, FOSS → UpdateSoftware, fictional → UpdateFictionalCharacter)
  and sets `wbUpdateBasicInfoUrl`; `updatebutton.js` renders **"Update basic
  information"** under the title line, styled like the existing embed/
  citation toolbar.

## Consequences

- **Positive**: entity fields are never silently guessed; every autofill from
  source data is confirmed or corrected; items are editable in place with the
  same vocabulary the creation flow uses; the Add\* validate button finally
  works.
- **Trade-offs**:
  - Fuzzy matching is server-side per review render (a handful of
    `EntitySearchHelper` calls + entity lookups) — fine at this instance's
    scale; the matcher is best-effort (a search/lookup failure falls back to
    the hint flow).
  - The AddSoftware **logo license shares the P275 `license` property** with
    the software's own licenses: an update replaces the license set with the
    form's values — a logo license is kept only via a new logo upload.
  - The access **download vs file** mode is not recoverable from statements
    alone (both store a file); the update form derives `file` when a file
    statement exists — the mode only matters when a new file/URL is provided.
- **No vocabulary/config-map changes** — the class→Update map is derived
  from existing config keys; deploy is a plain extension rsync (no importers,
  no seed re-emission).
