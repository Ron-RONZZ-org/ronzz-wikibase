# Decision: Content-page "Add more" + label prefill, Add* markup-label page fix, Update* access collapse

- **Status**: Accepted (Aug 30 2026)
- **Scope**: `wikibase.ronzz.org` — the v1 content pages
  (`Special:AddQuotation`/`AddCodeSnippet`/`AddMath`), the Add*/Update*
  classic-page machinery (`SpecialAddExternalEntity`,
  `UpdateExternalEntityFlow`), `Special:UpdateSource`, and the on-wiki
  contribution help
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Four user-requested improvements, one of them a bug with production impact:

1. **No rapid multi-item entry on the content pages.** Adding several
   quotations/code snippets/math expressions from the SAME source or author
   meant re-typing the whole provenance block (attributed-to, source, source
   URL, date, language) for every item.
2. **The content-page label field starts empty.** The AddSource label
   convention appends a parenthetical class disambiguation ("The Hobbit
   (Book)"); the content pages had no equivalent scaffold, so labels came
   out as bare quote/code/math text with no class marker.
3. **A harvested markup title silently skips the classic page (Q1232).**
   OpenAlex work titles wrap taxonomic terms in `<i>…</i>` HTML. The markup
   was stored verbatim in the item label AND made the classic-page title
   invalid (MediaWiki's `Title::isValid()` rejects `<`/`>`), so
   `afterCreate()` fell back to the item redirect **silently** — item
   `Q1232` ("Planck 2018 results (Scholarly article)") has statements but no
   `Source:` page and no sitelink.
4. **Update* pages show image/file fields the editor rarely touches.** The
   portrait/logo sections on `UpdatePerson`/`UpdateCollective`/
   `UpdateSoftware` were already collapsed behind "I will upload a NEW
   portrait/logo image (replacing existing)" include toggles, but
   `Special:UpdateSource`'s access section (mode radio + access URL /
   download / file / license) was always expanded.

## Decision

### 1. Content-page label prefill + "Add more" (`SpecialAddContentItem`)

- The `label` field default is the parenthetical class marker, the
  AddSource convention: `(quotation)` / `(code snippet)` / `(math snippet)`
  (per-kind i18n keys; identical across UI languages because labels are `en`
  terms). The contributor types the content text in front of it.
- A second submit button **"Add more"** (`submitbutton` field, `wpaddmore`)
  next to the main "Save item": the submit creates the item exactly like the
  main button, then redirects back to the same page with `?addmore=1` and
  the submitted provenance fields as query params — **label and payload are
  deliberately excluded** (label resets to the default prefill, payload to
  empty). The reopened form prefills `attributedTo` / `source` / `sourceUrl`
  / `date` / `language` / `lexer` / `describes` / `implementationOf` from
  the request, so the next item only needs its content + label.
- The content-page **submit is now login-gated** (the existing
  `embeddablecontent-add-error-anon` message): creating items (and the
  Add-more re-submit) is a write surface, matching the other Add* pages.
  Page loads stay open.

### 2. Label sanitization for page titles (the Q1232 fix)

- New pure helper `includes/Spec/LabelSanitizer.php` (`stripMarkup`):
  decode HTML entities → strip tags → collapse whitespace (OpenAlex
  `"<i>Planck</i>  2018 results"` → `"Planck 2018 results"`). Unit-tested.
- `SpecialAddSource::disambiguatedTitle()` sanitizes before the class-suffix
  append, so the harvested title is clean in the review default, the stored
  item label and the page title.
- Defense-in-depth in `SpecialAddExternalEntity::pageTitleForRecord()`:
  sanitize before `Title::makeTitle`; and `afterCreate()` no longer fails
  **silently** — when the kind declares a page namespace but the label is
  unusable as a title, the flow renders a warning + a link to the created
  item instead of a bare redirect.
- The Add* `afterCreate()` sitelink/page-creation block is refactored into
  `linkPageToItem()` / `createClassicPage()` (no behavior change).

### 3. Update* heal: create the missing classic page (`UpdateExternalEntityFlow`)

- After a successful update, when the kind declares a page namespace, the
  item has **no** wikibase sitelink, and the (updated) label is a usable
  title, the page is created with the same sitelink-first + marked-page
  pattern as `afterCreate` and the flow routes through the `complete/<id>`
  finalize round-trip (routing added to the Update `execute()`). This heals
  Q1232-class damage through the normal update flow.
- No-clobber unchanged: an untouched access section (see 4) or blank fields
  never rewrite existing statements.

### 4. UpdateSource access collapse (`SpecialUpdateSource`)

- The access section is hidden behind an **"I will update the resource
  access instructions"** checkbox (`accessInclude`, default unchecked) —
  the `ImageUploadHelper::includeField` hide-if pattern. The Add* flow
  keeps the section always visible (a new source must declare its access
  fact).
- An unchecked submit **neutralizes the access record keys** in
  `beforeCreate`, so `statementSpecs` writes nothing and `applyUpdate`
  removes nothing — the item's access statements survive byte-identical
  (no GUID churn, no re-upload). A checked submit behaves exactly as before.

### 5. Backfill tool (`tools/backfill_classic_pages.py`)

- stdlib-only, `--dry-run` default (`--apply` to write): for each given
  item, skip when sitelinked, sanitize the label, look the instance-of class
  up in a `--ns-map` (class id → page namespace + template), `wbsetsitelink`
  first, then create the page (`{{Template}}` + `== Overview ==` lead) in a
  separate request so the `wikibase_item` page property is set immediately.
- Used to heal `Q1232` after deploy; reusable for any future orphan.

## Consequences

- Content-page labels carry the parenthetical class marker — existing items
  are untouched (the prefill only affects new form loads).
- Harvested markup can no longer reach labels or page titles; items with
  an unusable title now surface a warning instead of silently missing their
  page.
- `Special:UpdateSource` editors see a clean form by default; access facts
  change only when explicitly requested.
- The `accessInclude` collapse applies to the access section only — the
  portrait/logo sections of the other Update* pages already had include
  toggles.
