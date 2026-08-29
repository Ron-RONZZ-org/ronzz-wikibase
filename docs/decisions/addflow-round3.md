# Decision: Add-flow round 3 — official website, webpage parent inference, label disambiguation, Item toolbar

- **Status**: Accepted (Aug 29 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddCollective`/`AddPerson`
  (`official website` field), `Special:AddSource` (webpage→website parent
  inference, label class-suffix), and the Item-page toolbar (single row,
  citation format chooser)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Four editor-facing gaps:

1. **No official-website field on persons/collectives.** `Special:AddSoftware`
   already collects the official website (a URL field writing the shared
   P856-aligned property), and `Template:Collective` already renders the
   `{{#statements:official website}}` infobox row — but `Special:AddPerson`
   and `Special:AddCollective` neither asked for the URL nor wrote the
   statement, and `Template:Person` had no row for it.
2. **Webpage parents are hand-picked.** The `webpage→website` child class
   requires an existing website item via an entity combobox; nothing derived
   the parent from the URL the contributor already typed.
3. **Source labels collide.** Two sources with the same title (a book and a
   webpage, two articles of the same name) created indistinguishable labels
   and page titles.
4. **The Item-page toolbar is two rows, and citations are APA-only.** The
   "Update basic information" button rendered in its own `<div>` above the
   embed/citation row, and "Copy citation" hardcoded `style=apa` with no
   format choice.

## Decision

### 1. Official website on AddPerson / AddCollective (shared property)

- The **same** P856-aligned `official website` URL property (datatype `url`,
  already in the FOSS vocabulary) is added to the `personProperties` and
  `collectiveProperties` config maps (one property, three entity kinds) and
  to the `personPropertyIds()`/`collectivePropertyIds()` accessors.
- Both review forms gain the shared `website` URL field (the same spec
  `Special:AddSoftware` uses — a plain URL input, not an entity field); the
  validated URL is written as a string statement. `Special:UpdatePerson` /
  `Special:UpdateCollective` prefill it from the item (`recordFromItem`) and
  no-clobber update semantics keep it when blank.
- On-wiki: `Template:Person` gains the `Official website` infobox row
  (`{{#statements:official website}}`); `Template:Collective` already had it.

### 2. Webpage → website parent inference from the root URL

- On the `Special:AddSource/webpage` URL-entry submit, the site root of the
  entered page URL (`SsrfGuard::siteRoot`, same collapse the website flow
  uses) is fetched for its site name and matched against existing
  **website-class** items — the exact→fuzzy `resolveEntityField`
  autofill-confirm flow used for publisher/journal/authors:
  - **Match** → the parent combobox is prefilled with the website Q-id and
    the standard [Yes, that's right] / [No, let me correct] banner renders;
    the `part of` statement + `validateParent` (instance-of website) run
    unchanged at submit.
  - **No match** → the field stays required with the hint *"No record found
    for {root} — the website exists, but we don't have a record of it yet.
    Add the website first, then pick it above."* (wording approved by the
    decider: the SITE is real, OUR RECORD isn't).
- The exact-label branch of `resolveEntityField` does not filter by class and
  can return a stale term-store hit for a deleted item — the inference
  re-checks the item's class and falls through to the class-filtered fuzzy
  matcher (which skips deleted items) before declaring "no match".

### 3. Source-label class disambiguation

- `Special:AddSource` appends the **English class label** in parentheses —
  `The Hobbit (Book)`, `Example Domain (Website)`, `Chapter 3 (Book
  excerpt)` — to the label:
  - the review/manual **title field default** carries it (visible, editable);
  - `primaryLabel()` appends it at **creation** (idempotent — a label already
    ending with the suffix is left alone), so a title typed from blank on the
    manual form is disambiguated too.
- The suffix is the English class label because labels are stored as the
  item's `en` term. Page titles and citation output follow automatically
  (they derive from the label).
- `Special:UpdateSource` overrides `applyLabelSuffix()` to **false**: an
  update shows the stored label as-is (a pre-convention item is not renamed
  by its first update; a suffix already in the stored label survives).

### 4. Item toolbar: one row, citation format chooser

- `updatebutton.js` (the "Update basic information" button) and `gadget.js`
  (copy embed / copy citation) share **one** `.wb-embed-toolbar` flex row via
  a create-or-reuse `getToolbar()` rendezvous (whichever module renders first
  creates the container; the update button prepends so the primary action
  stays first).
- Copy citation gains a **format selector**: APA (default) / Vancouver /
  BibTeX / RIS — the four text formats `api.php?action=citation` supports
  (`json` is a raw structure, not meant for copying). The text for the
  selected format is fetched lazily and cached per format; the APA probe that
  decides whether the button renders doubles as the first fetched text.

## Consequences

- New/manual person and collective items carry an official-website statement,
  rendered on their classic pages; the Update pages can correct it.
- Webpage creation is faster (no manual parent search) and safer (a wrong
  guess is recoverable at the confirmation banner); a missing website record
  is explicitly surfaced with the creation link instead of a silent gap.
- Source labels/page titles are unambiguous at creation; nothing is migrated
  retroactively (existing items keep their labels).
- The Item-page toolbar is a single row with a format choice for citations —
  no new CSS primitives (the existing `.wb-embed-toolbar-btn` /
  `.wb-embed-toolbar` styles are reused).
