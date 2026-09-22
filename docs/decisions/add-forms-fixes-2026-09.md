# Decision: Add* form fixes — case-insensitive search, combobox scope, Update prefill, page-title normalization, Special:NewItem pages

- **Status**: Accepted (Sep 2026)
- **Scope**: `wikibase.ronzz.org` — the entity comboboxes (`action=entitysearch`
  + `resources/entitysuggest.js`), `Special:AddSoftware`/`UpdateSoftware`, the
  classic-page title machinery (`Spec/LabelSanitizer`,
  `Spec/SpecialAddExternalEntity`, `Flow/ClassicPageCreator`), and a new
  `Special:NewItem` hook
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Five user-reported defects in the Add*/Update* surface:

1. **Combobox search was not reliably case-insensitive.** `action=entitysearch`
   matched labels/aliases with `LIKE %term%` over the case-sensitive
   `VARBINARY` term column, faking case-insensitivity with raw / Title-case /
   UPPER variants. Uppercase and mixed-case queries returned nothing
   (`APACHE`, `aPaChE`, `liCENSE`, `ALBERT EINSTEIN`).
2. **`Special:UpdateSoftware` rendered every entity combobox empty.** The
   FOSS entity fields (developer/license/OS/UI/has-use) had no `default` and
   the programming language was read from the wrong config map
   (`fossProperties` has no `programmingLanguage` key).
3. **The combobox class scope never reached the client.** `OOUIComboboxField`
   emitted `data-wb-classes` as a DOM attribute, but the OOUI HTMLForm
   auto-infusion re-creates each widget from its `data-ooui` JSON and DROPS
   server-rendered attributes — so `entitysuggest.js` read an empty scope and
   the operating-system field suggested "The Linux Foundation" (a
   non-OS item). Reproduced live with a headless browser: the request carried
   `classes=`.
4. **A label with a title-forbidden character lost its classic page.** The
   Add* flows derive the page title from the item label; `LabelSanitizer`
   stripped markup but left `# < > [ ] { } |`, so `Title::isValid()` rejected
   the title and the flow showed the "label cannot be used as a page title"
   warning instead of creating the page.
5. **An item created through Wikibase's `Special:NewItem` had no page.** The
   Add* flows each create a per-kind page; the generic "Create a new item"
   form created a bare item.

## Decision

### 1. Case-insensitive, anywhere-in-term entity search

`ApiEntitySearch::containsRows()` runs one `LIKE %term%` over
`CONVERT(wbx_text USING utf8mb4) LIKE '<pattern>' ESCAPE '!'` — the same
idiom as `ApiFileSearch`'s page-title CONTAINS match. The three case variants
are gone; the typed term is escaped (`!%` / `!_` / `!!`). Raw SQL is used
because `IExpression::LIKE`/`LikeValue` escape a plain string as a literal and
cannot express the `CONVERT`; MySQL/MariaDB is assumed. Partial matching
(`%term%`, not just a prefix) is unchanged and now applies to every case.

### 2. UpdateSoftware prefill

- `SpecialAddSoftware::reviewFieldSpecs()` gives the FOSS entity comboboxes a
  `default` from the record when the value is already an item-id list (the
  Update flow's `recordFromItem` shape), and passes the class scope to
  `resolveEntityField()` so a harvested label resolves only within the right
  class.
- `SpecialUpdateSoftware::recordFromItem()` reads the programming-language
  property from `EmbeddableContentConfig::programmingLanguagePropertyId()`
  (the global config map) instead of `fossPropertyIds()`.
- The `pageKind` radio is prefilled from the item's stored class (FOSS vs
  software) so an untouched update keeps the existing page split; `'auto'`
  remains the create-time default.

### 3. Infusion-safe combobox class scope

`OOUIComboboxField::getInputOOUI()` now carries the scope in the OOUI widget
**data** (`setData(['wbClasses' => …])`) in addition to the `data-wb-classes`
attribute. `OOUI\Element::getConfig()` serializes `data` into `data-ooui`, so
the auto-infusion restores it; `resources/entitysuggest.js` infuses the
widget first and reads `combo.getData().wbClasses` (the DOM attribute stays
as a fallback for non-infused rendering). This fixes every scoped combobox —
Add*/Update*, the portrait/logo license fields and `Special:Upload`.

### 4. Page-title normalization

New pure `LabelSanitizer::normalizeForTitle()`: `stripMarkup` first, then
`# < > [ ] { } |` → `-`, whitespace and repeated dashes collapse, leading /
trailing dashes and spaces trim. `/` is left alone (MediaWiki accepts it as a
subpage). The item **label** is never changed — only the derived page title.
`Spec/SpecialAddExternalEntity::pageTitleForRecord()` and
`Flow/ClassicPageCreator::pageTitleFor()` both use it, so the browser Add*
flows and the API flows behave identically. A label that normalizes to empty
keeps the item-only fallback (the warning + item link).

### 5. `Special:NewItem` auto-creates a Main-namespace page

New `Flow/NewItemPageCreator` + a `PageSaveComplete` hook
(`Hooks::onPageSaveComplete`). When a NEW Item page is saved **from the
`Special:NewItem` request** (gated on `RequestContext`'s title), a
Main-namespace page titled with the item's label (normalized per #4) is
created and sitelinked via `ClassicPageCreator` (empty template, the
description as an `== Overview ==` lead). Scope is deliberately narrow:

- the Add* flows create their own per-kind pages (and fire the same hook) —
  excluded by the request-title gate;
- API/script item creation is out of scope;
- an item created with an explicit sitelink (the anonymous Sitelink-tab
  fallback passes `site=wikibase&page=…`) already has a page and is skipped;
- an existing Main page at the target title is never overwritten or stolen
  (the item keeps no page rather than clobbering a real one).

`ClassicPageCreator::pageSkeleton()` now renders no transclusion when the
template is empty (the Main page has no per-kind template). As with the API
flow, the `wikibase_item` page property is eventually consistent; the
Sitelink tab reads the sitelink table directly, so it is correct immediately.

## Consequences

- **No vocabulary / config-map / manifest change** — the fixes are code +
  JS + i18n-free; no D1 importers or seed re-emission are required for the
  deploy.
- The `entitysearch` module's result ORDER is still unindexed (a broad term
  can return a subset); this is unchanged and bounded, and the scoped search
  keeps the over-fetch cap.
- New regression coverage: `tests/Unit/Spec/LabelSanitizerTest.php`
  (`normalizeForTitle`) plus page-flow E2E checks —
  `flow_entitysearch_case_insensitive`, `flow_combobox_scope_data`,
  `flow_update_software_prefill`, `flow_newitem_main_page` (the last also
  exercises the title normalization with a `|` in the label).
- `Help:Contributing` (and the gated runbook pages) document the NewItem page
  behavior and the Update prefill.

## What this does NOT do

- No change to `wbsearchentities` (the Sitelink-tab popup and `findItemIdByLabel`
  keep their own contracts); only `action=entitysearch` is case-insensitive.
- No page-title length truncation — a label longer than MediaWiki's 255-byte
  title limit still yields the item-only fallback.
- No backfill of items created before this change (the update flow heals a
  missing page on the next edit, as before).
