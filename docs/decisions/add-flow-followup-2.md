# Decision: Source access row + Special:SourceFile, description limit 2000, class-at-review, collective parent organization

- **Status**: Accepted (Aug 24 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSource`/`Special:AddPerson`/`Special:AddCollective`,
  the Source:/Collective: page templates, the term-store schema, and the local config
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Four editor-facing follow-ups to the issue-#35 add-flows:

1. **Access rendering**: the access facts (non-direct `access URL`, stored `file`,
   `license`) have existed on the data side since issue #35, but the auto-generated
   `Source:` pages never render them — the per-class templates are plain
   `{{#statements:}}` infoboxes with no "Access" row, and there is no page where a
   stored copy can be previewed and downloaded under a licence gate.
2. **Description length**: Wikibase's default term limit (250 chars, `string-limits`
   `multilang.length`) is too short for real-world work/person descriptions; the
   Add* forms already cap the description field at 250 to match.
3. **Class on the selection step**: the search-results selection step carries a
   "Class" field (visible for the multi-class kinds, AddCollective/AddSoftware) —
   but the class is about how to *classify* the picked record, which belongs on the
   review step where the harvested data informs the choice.
4. **Collective parent**: `Special:AddCollective` has no way to record a collective's
   parent organization (P749-aligned).

## Decision

### 1. `{{#source-access:}}` parser function + `Special:SourceFile`

- **Parser function** `{{#source-access:}}` (EmbeddableContent, `ParserFunctions/SourceAccess.php`,
  magic word `sourceaccess`, wikitext spelling `source-access`, en/fr/eo):
  resolves the CURRENT page's sitelinked item (the same site-link store the Sitelink
  tab uses) and renders the infobox "Access" cell from its statements:
  1. a `file` statement (the copy stored on this wiki) → the file name linked to
     `Special:SourceFile?item=<Q>&file=<File: title>`;
  2. else an `access URL` statement (non-direct) → a clickable external link;
  3. else → localized "N/A".
  Returns **wikitext** (not HTML): the cell participates in the template's normal
  parse. The item page is registered as a parser-cache dependency
  (`ParserOutput::addTemplate()`, the WikibaseCitation pattern), so editing the item
  re-renders every page showing the cell.
- **Templates** (on-wiki, at deploy): the 4 access-field classes that create pages —
  `Template:Book`, `Template:ScholarlyArticle`, `Template:Song`, `Template:Film` —
  gain one row: `| Access || {{#source-access:}}`. (bookExcerpt has no page;
  website/webpage/video/YouTube* never set access statements — their templates are
  unchanged, per the explicit scope decision: no main-URL-as-access mapping.)
- **`Special:SourceFile`** (EmbeddableContent, `Spec/SpecialSourceFile.php`):
  - `?item=Q42&file=File:Foo.pdf` (the parser function's link carries both).
  - **PDF preview**: a self-hosted `<iframe>` of the file URL (browser-native viewer;
    the instance has no PdfHandler/ghostscript — deliberately not added). Bitmap/
    drawing media get a 320px thumbnail; other types get no inline preview (the
    file page has the player).
  - **Licence information**: the owning item's `license` statement (P275-aligned
    entity) → the licence item's label linked to its item page, plus its `url`
    statement when recorded. Defensive fallback "Unknown".
  - **Download button**: an HTMLForm checkbox *"I accept the license conditions of
    {license}"* (required) + Download. The unchecked submit is rejected server-side;
    the checked submit is login-gated (house pattern — the instance is anon
    read-only) and redirects to the file URL. The page LOAD is public (preview +
    licence are informational).
- **Deploy**: rsync extension + chown → restart php-fpm → purge caches → apply the
  on-wiki template edits (MCP bot). No vocabulary/config-map changes.

### 2. Entity descriptions up to 2000 chars

- **Storage**: the term store's deduplicated text column `wbt_text.wbx_text` is
  `VARBINARY(255)` — a hard DB ceiling below the validator. Deploy runs
  `ALTER TABLE wbt_text MODIFY wbx_text VARBINARY(2000) NOT NULL;` (the unique
  index on 2000 bytes fits InnoDB's 3072-byte utf8mb4 key limit). CI runs the same
  ALTER after the stack's first-boot install (the docker-entrypoint initdb.d
  scripts run BEFORE MediaWiki creates the schema, so it is an explicit step).
- **Validator**: `$wgWBRepoSettings['string-limits']['multilang']['length'] = 2000;`
  (production LocalSettings + dev/CI `dev/config/Extensions.php`). NOTE: the
  `multilang` limit is SHARED by labels/descriptions/aliases — the storage and the
  UI now accept 2000 for all three. The Add* forms deliberately keep the **label**
  field at 250 (labels become Person:/Source:/Collective: page titles) and raise
  only the **description** field (`descriptionFieldSpec` maxlength 2000).
- **Regression**: the page-flow E2E creates an item with a >255-char description
  via the API and asserts it reads back intact — fails without both the ALTER and
  the config raise.

### 3. Class selection moves to the review step

- The **selection step** of the Add* flows drops its "Class" field entirely: the
  step only decides WHICH record to import (`onSelectSubmit` no longer reads or
  validates the class). The **review step** already carries the class field
  (`classFieldSpec`); for multi-class kinds it is a select pre-selected by the
  harvest inference (`defaultClassItemId`), single-class kinds keep it hidden, and
  the submit falls back to the inferred class when the field is absent.

### 4. `Special:AddCollective` — optional "Parent organization" entity field

- New **`parent organization`** property (wikibase-item, P749-aligned) in
  `manifests/properties.csv`; config map section **`collectiveProperties`**
  (`parentOrganization` → the property id, emitted by the seed).
- The AddCollective review/manual form gains an optional entity combobox
  "Parent organization"; a filled id writes the statement, an empty field writes
  none, an unparseable value is skipped (the AddPerson place-field contract).

## What this does NOT do (and why)

- **No PdfHandler / ghostscript** — the iframe preview needs no server-side PDF
  rendering; first-page thumbnails can be added later if wanted elsewhere.
- **No main-URL-as-access** for website/webpage/video/YouTube classes — their
  access row would conflate the `url` fact with the access field (explicit scope
  decision).
- **No server-side licence gate on the raw file URL** — files under `/images/`
  remain directly fetchable; the checkbox is a UX acceptance gate, not access
  control (the instance is anon read-only anyway).
- **No label-field raise** — the 2000-char storage limit applies to labels too,
  but the forms keep labels at 250 (page-title sanity).
- **No parent-org harvest** — the field is manual/editor entry only.

## Consequences

- **Vocabulary**: +1 property (`parent organization`, P749). Config map:
  `collectiveProperties`.
- **Config**: `string-limits.multilang.length` 250 → 2000 (production + dev).
- **DB migration** (production + CI): `wbt_text.wbx_text` → `VARBINARY(2000)`.
- **Deploy sequence** (runbook): backups (LocalSettings + extension tarball) →
  rsync + chown → D1 importers (1 property) → full seed re-emission (incl.
  `dogfood`, never `--only=config`) → LocalSettings (`string-limits`; `php -l`) →
  the `wbt_text` ALTER → restart php-fpm → cache purge → `rebuildtextindex.php`
  (not needed — no namespace change) → on-wiki template edits (4 source templates
  + Collective) → live verification (Special:SourceFile 200, a Source: page's
  access row, a long-description save).
- **Testing**: the pure `SourceAccessRenderer` is PHPUnit-covered (branching,
  escaping); the page-flow E2E covers the parser function (file/URL/N-A cells via
  `action=parse`), Special:SourceFile (licence + gated download, PDF iframe), the
  description-limit regression, and the collective parent-organization statement.
  The selection-step class removal is exercised by every search flow (the helpers
  no longer post `wpclass` at selection).
- **Docs**: this ADR; `extensions/AGENTS.md`; on-wiki `RonzzIT:Deployment/Wikibase`,
  `RonzzIT:Runbook/Wikibase`, `Help:Contributing/import`; local `logs/wikibase.md`.

## References

- Issue #35 (access field, publisher entity, autofill); ADR
  `publisher-entity-access-manual.md` (the access field this renders);
  `pages-and-fields.md` (the 500→250 description cap this supersedes).
- Wikidata alignments: P749 (parent organization), P1325 (file), P953 (access URL),
  P275 (license).
