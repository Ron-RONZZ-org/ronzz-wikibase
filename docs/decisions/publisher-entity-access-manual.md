# Decision: Entity-only publisher, source access field with uploads, manual-addition entry points, auto full-name person labels

- **Status**: Accepted (Aug 23 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddPerson` / `Special:AddSource` (+ the shared base
  `SpecialAddExternalEntity`), their vocabulary, and the publisher data migration
- **Decider**: Rongzhou (`ron@ronzz.org`); issue [#35](https://github.com/Ron-RONZZ-org/ronzz-wikibase/issues/35)

## Context

Three editor-facing improvements to the issue-#7 creation flows:

1. **Publisher**: the `Special:AddSource/book` (and `scholarlyArticle`) publisher field was free
   text on a **string** property. Editors wanted the publisher as a **semantic entity** — and, on
   review, decided text mode should go away entirely ("entity only, no toggle"). A string property
   cannot hold an item, so a second, item-typed property is required, plus a one-off conversion of
   the existing string statements.
2. **Access**: source items carry no "how do I get this work" fact. Editors want an `access` field
   (non-direct access URL / direct download link / local file), with a license + copyright warning
   for the two file-bearing modes, uploaded files auto-named from the item label.
3. **Manual entry + person labels**: the "create manually" fallback was only reachable from the
   zero-hit search page (a plain link, no value carry-over), and `Special:AddPerson` asked for a
   free-text `label` although the label is the full name.

## Decision

### 1. Publisher is entity-only (no toggle)

- **New property `publisher (entity)`** (wikibase-item datatype, P123-aligned). The legacy string
  `publisher` property stays in the vocabulary (Wikibase properties are immutable — the datatype
  cannot change) but is **no longer written by the forms**.
- The review/manual publisher field is an **entity combobox** (same widget as the authors field).
  A harvested **string** publisher (Open Library etc.) is resolved to an existing local item by
  **exact label**; when no match exists, the string is shown as context with a
  "pick an existing item, or create it first (e.g. via Special:AddCollective)" hint — the
  instance's "properties first, then items" house rule (the AddSoftware harvested-fact pattern).
- **Statements**: the entity publisher statement is written by `Special:AddSource` itself; the
  base string citation-metadata writer gains a `citationMetadataFieldExclusions()` hook so it
  never also emits the string statement.
- **Citation engine**: `citation-source-property-map.json`'s `publisher` entry now resolves the
  entity property; `StatementToCslConverter::statementValue()` already falls back item → label
  (issue #7), so **no converter code change** — entity publishers render in citations as labels.
- **Seed dogfood**: the dogfood book's publisher is now an item (find-or-create by label,
  classified `instance of` organization) written as an entity claim — a future seed re-emission
  must not resurrect a string claim.
- **Data migration (one-off)**: `tools/migrate_string_publishers.py` converts the existing string
  statements (production: 3 items — Q95 "R. & J. E. Taylor", Q172 "Les Belles Lettres", Q356
  "OpenGeology"): find-or-create the publisher item (organization), add the entity statement,
  remove the string statement. Idempotent, `--dry-run`, `--verify` recounts (must be zero).
  Run once at deploy, after the D1 importers created the new property.

### 2. Access field on `Special:AddSource` (book, scholarlyArticle, song, film, bookExcerpt)

- **`accessMode` toggle** (radio): `url` (non-direct access URL) | `download` (direct download
  link) | `file` (local file). Classes that already carry a URL fact (website, webpage, video,
  YouTube*) are excluded — the `url` property already plays that role there.
- **`url` mode**: a URL field → statement on the new **`access URL`** property (url datatype,
  P953-aligned, "full work available at URL" — here a landing page, not necessarily the file).
- **`download` / `file` modes**: both expand a **`license`** field (entity combobox over the
  preseeded license items, **reusing the P275-aligned `license` property** — one property, no
  per-kind zoo) with the copyright warning: *you should not upload copyrighted content unless
  you have the right to distribute it publicly*. The license is required (validated in
  `beforeCreate`, not by HTMLForm `required` — required fields are validated even when
  `hide-if` hides them client-side).
- **Uploads** (the AddSoftware logo pattern, cloned for sources): `download` fetches the URL
  server-side via `UploadFromUrl` (the instance's SSRF guard `IsUploadAllowedFromUrl` applies;
  `upload_by_url` is already granted to logged-in users); `file` uploads from the browser via
  `UploadFromFile`. The file lands as **`File:<label>.<ext>`** — the extension comes from the
  real MIME restricted to `$wgFileExtensions`, the **original filename is ignored**; the File:
  page text is auto-generated from the item label/description. Statements: the File: page URL on
  a **new `file` property** (url datatype, P1325-aligned) + the license entity.
  **Implementation requirement (fix 2026-08-24)**: the URL-mode uploads must call
  `UploadFromUrl::fetchFile()` after `initialize()` — `initialize()` only creates an EMPTY temp
  file, so without the explicit download `verifyUpload()` rejects the upload as
  `EMPTY_FILE` (status 3, "The file could not be uploaded: verifyUpload rejected (3)").
- **Failure discipline**: a filled-in access field that cannot be honoured (unreachable URL,
  unsupported type, missing license, upload rejection) aborts the creation with a form error —
  never silent.
- **Upload allow-list**: `pdf`, `epub`, `djvu` join `$wgFileExtensions` (production
  `LocalSettings.php` + dev/CI `dev/config/Extensions.php`) — book files are PDF/EPUB/DjVu and
  were previously rejected.

### 3. Manual-addition entry points + search autofill

- The **"No matching record? Create the item manually instead"** link now appears on **all**
  result-presenting steps: the zero-hit search page (as before), the **candidate-selection step**
  ("Select the person/source/… to add"), and — for AddSource — a **"create manually instead"
  checkbox on the class picker** that routes straight to `/<classKey>/manual` (a checkbox in the
  same form; `HTMLForm` has no secondary-submit-button API).
- **Autofill**: the search inputs are stored in the session under the search token at
  search-submit (token generated even on zero hits); the manual forms read `?token=` and prefill
  (`manualAutofillRecord` / `autofillRecord` on the base class):
  - AddPerson: `name` → given = **every word except the last**, family = **last word** (pure
    `NameSplitter`, the citation engine's legacy split convention);
  - AddSource: `title`/`isbn`/`doi` pass through; `author` → `authors` **only in entity mode**
    (the manual authors field requires item ids — a free-text author name is a search filter,
    not a record fact);
  - AddSoftware / AddCollective: `name` → `label`.

### 4. `Special:AddPerson` — no label field, auto full-name label

- The editable `label` field is **removed** from the review/manual forms. `primaryLabel()`
  derives `givenName + familyName` whenever either part is present (an edited name set is always
  reflected in the label — Person: page title, item label and candidate display all follow);
  a harvested record WITHOUT name parts keeps its harvested label.

## What this does NOT do (and why)

- **No free-text publisher anywhere** — the toggle idea was deliberately dropped (string values
  cannot mix with items on one property; the instance is entity-first).
- **No datatype conversion of the legacy string `publisher` property** — Wikibase datatypes are
  fixed at creation; the property stays as legacy vocabulary (its statements migrated away).
- **No per-relation property zoo for access** — `license` reuses the P275 property; the uploaded
  file reuses one new `file` property; `access URL` is one new property.
- **No upload from arbitrary sources** — `download` mode still goes through the SSRF guard.
- **bookExcerpt** keeps creating no classic page (it is part of a book); the access field is
  available on it like the other classes.
- **No `Special:Upload` licensing dropdown** — the access license is an **entity** statement
  (item-typed), consistent with the instance's model; `MediaWiki:Licenses` remains for manual
  uploads.

## Consequences

- **Vocabulary**: +3 properties — `publisher (entity)` (wikibase-item, P123), `access URL`
  (url, P953), `file` (url, P1325). Config map: `citationMetadata.publisher` → the entity
  property; `sourceProperties` += `license` / `accessUrl` / `file`.
- **Migration**: the one-off string→entity conversion runs at deploy; `--verify` must report
  zero remaining string publisher statements.
- **Deploy sequence** (runbook): backups → rsync → D1 importers → full seed re-emission
  (incl. `dogfood`) → `$wgFileExtensions` + pdf/epub/djvu → php-fpm restart → cache purge →
  `rebuildtextindex.php` → run the migration → live verification (Special:AddSource/book
  publisher combobox, access toggle, autofill, AddPerson without label).
- **Templates (on-wiki, at deploy)**: `Template:Book` / `Template:ScholarlyArticle` etc. may
  render the new statements (`access URL`, `file`, `license`, entity publisher) — optional,
  follow-up.
- **Testing**: the dev-stack page-flow E2E covers the autofill, publisher-entity, access
  local-file **and** access download-mode (a stable public PNG fixture — the `fetchFile()`
  regression test, would have caught the EMPTY_FILE failure), picker-manual and
  selection-link flows; the pure `NameSplitter` is PHPUnit-covered. The download-mode E2E
  required granting `upload_by_url` + enabling copy uploads in the dev/CI config
  (`dev/config/Extensions.php`, CI parity with production).
- **Docs**: this ADR; `extensions/AGENTS.md`; on-wiki `RonzzIT:Deployment/Wikibase`,
  `RonzzIT:Runbook/Wikibase`, `Help:Contributing/import` (+`entities`); local `logs/wikibase.md`.

## References

- Issue #35 (this feature set); issues #7 (external authorities), #12 (manual fallback),
  #26 (AddSoftware pages + logo upload), #30 (client enablement).
- ADRs: `class-first-addsource.md` (class-first AddSource), `pages-and-fields.md` (classic pages).
- Wikidata alignments: P123 (publisher), P275 (license), P953 (full work available at URL),
  P1325 (external data available at URL).
