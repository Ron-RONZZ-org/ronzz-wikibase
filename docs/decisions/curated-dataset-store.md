# Decision: curated scientific datasets — Wikibase + WDQS + MySQL, not Cargo

- **Status**: Accepted (Nov 22 2026). Supersedes the Cargo storage decision
  recorded in epic #6 and the Cargo-specific parts of issues #7/#9.
- **Scope**: the `Dataset:` namespace, the curated-dataset store, the
  `#dataset-*` parser functions, `action=datasetupsert` / `action=datasetquery`,
  and the MediaWiki MCP `embeddable-dataset-upsert` tool (the conceptual operation is “dataset-upsert”; MCP tool names carry the pack prefix).
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Epic #6 asks for curated scientific tables (a meta-analysis table cited by an
article, for example) to be **stored, searchable, queryable and visualisable**
on wikibase.ronzz.org, with full provenance, instead of being plain-text
tables. The original plan chose **Cargo** as the measurement store and
Wikibase for identity/provenance, with the `Dataset:` page save acting as the
Cargo write.

A source-level review of Cargo 3.9.5 — the version #7 targets — showed that
Cargo is deliberately a **page-to-table extractor**, not a dataset store:

- `CargoHooks::onPageSaveComplete` deletes every Cargo row belonging to the
  page, then **re-parses the whole page** to re-run `#cargo_store`
  (  `CargoHooks.php:263-303`). The delete and the re-insert are not one
  transaction, so readers can see a partially re-stored table.
- The same hook runs for **every** wikitext save, and `#cargo_store` is
  invoked during the storage parse; a normal page view skips the write
  (`CargoStore::storeTable` requires an `origin` setting).
- Each `#cargo_store` call stores one row in its own transaction
  (`CargoStore.php:130-230`); a table of *n* cells means *n* calls and *n*
  transactions per save (twice: display parse + storage parse).
- Cargo auto-indexes **every non-`Text` field** (`CargoUtils.php:1040-1090`).
  The proposed `observations` table has ~14 non-`Text` columns, so every cell
  write updates ~14 indexes.
- `#cargo_query` is capped at `$wgCargoMaxQueryLimit = 5000`
  (`CargoSQLQuery.php:95`); in the proposed long format one row is one cell.
- The generated data page is bounded by `$wgMaxArticleSize` (2,097,152 bytes
  on production), which the long format reaches at roughly 7,000–8,000 cells.
- `runcargoqueries` is granted to `*` by default (`extension.json`
  `GroupPermissions`), so `action=cargoquery`, `Special:Drilldown` and
  `Special:CargoExport` expose every row — `access_tier` cannot be enforced
  per dataset.

MediaWiki itself does not provide a structured-data store in core. The
Wikimedia-native mechanisms are:

| Mechanism | What it is | Fit here |
|---|---|---|
| **Wikibase + WDQS** | statements + SPARQL over entities | identity, provenance, citation, metadata queries — **yes** |
| **TabularData** (`Data:` namespace on Commons) | JSON `.tab` pages (Frictionless-shaped), CC0/CC-BY/CC-BY-SA/ODbL, 2 MB page cap, 400-char strings, **no query support** (Lua via Scribunto only) | public derived exports only — **not the store** |
| **Extension:Chart** | Chart.js charts over `.tab` pages | visualisation after the **MW 1.47** upgrade (instance is 1.46) |
| **Extension:Graph** | Vega graphs | archived; do not use |
| **Cargo** | page-extracted SQL tables | wrong data model (above) |

Cargo is a community extension (Canasta, Fandom, Miraheze, wiki.gg); it is not
part of Wikimedia production. The instance already runs Wikibase repository +
client, WDQS, MySQL 8.0.46, PHP 8.3 and the house `EmbeddableContent`
extension. Reusing that infrastructure avoids a new service, a new driver and
a second backup path.

## Decision

1. **Wikibase** remains the identity/provenance/citation store: one item per
   dataset and per data table, with `has data table`, `extracted from`,
   `table number`, `table id`, `dataset id`, `page(s)`, `review status` and
   `extraction method` statements; the dataset item is sitelinked to
   `Dataset:<slug>`.
2. **WDQS** answers cross-dataset metadata/provenance questions (which
   datasets came from a source, which tables are verified, …).
3. **MySQL** stores the measurements in a purpose-built schema on the existing
   MySQL 8 server. This is MediaWiki's own database engine, so there is no new
   service, no new PHP driver, and the same `mysqldump` / boot-volume backup
   already covers it. The schema is defined once in
   `extensions/EmbeddableContent/sql/dataset-schema.sql` and loaded by a
   maintenance script. v1 uses `wb_dataset_`-prefixed tables in the existing
   MediaWiki database (no new database or DB user); a separate database stays
   an option if isolation is needed later.
4. **The manifest is the canonical source of truth.** It is stored verbatim on
   `Dataset:<slug>/manifest` as a JSON page (wiki revision history = audit
   trail, diff, access control, backup). MySQL rows and Wikibase items are
   **derived** and can be rebuilt by replaying the manifest. This makes a
   failed apply recoverable and a Cargo-style half-written table impossible.
5. **`Dataset:<slug>` is a view page**, not a data page. It renders the
   infobox, one section per table, footnotes, provenance and citation through
   the house parser functions:
   - `{{#dataset-table:<table_id>|panel=<key>}}`
   - `{{#dataset-footnotes:<table_id>}}`
   - `{{#dataset-provenance:<table_id>}}`
   - `{{#dataset-cite:<dataset_id>}}` (optional, reuses the citation engine)
6. **`action=datasetupsert`** validates the manifest synchronously
   (`dryrun=1`) and enqueues a **`datasetApply` job** for `apply`, returning a
   `run_id`. The job writes MySQL in one transaction, creates/updates the
   Wikibase items in draft and finalises them. A companion
   `action=datasetquery` provides structured filters, pagination and
   CSV/JSON export. The MediaWiki MCP `embeddable-dataset-upsert` tool is a thin client
   for that API.
7. **Namespace**: `Dataset` = NS 2018, `Dataset talk` = NS 2019 (the next free
   pair after `Software` 2016/2017). Subpages are enabled (subpage range
   extended to 2019). `Special:EntityIndex/Dataset` lists the namespace.
8. **No Cargo.** No `#cargo_declare`/`#cargo_store` templates, no
   `Special:Drilldown`, no `action=cargoquery`. The equivalent read surfaces
   are `action=datasetquery`, `Special:Datasets` (a small filter page) and
   `Special:EntityIndex/Dataset`.
9. **No new service.** PostgreSQL was considered for row-level security and
   window-function analytics but rejected for v1: it adds a service, a driver
   (`pdo_pgsql` is not installed) and a backup path. SQLite was rejected
   because `pdo_sqlite`/`sqlite3` are not installed and it would add a second
   storage engine. TabularData was rejected as a store (page-based, CC0,
   2 MB, no query); it is a possible **public export** later.
10. **Access tiers.** `access_tier` is a first-class dataset field with the
    values `public`, `registered` and `internal`. It is **orthogonal** to
    `licence`/`rights_basis` (the dataset's own data licence) and to the
    source PDF's internal-use marker:
    - **Extracted data is not copyright-protected.** A dataset may be
      `public` while its source PDF remains behind the existing internal-use
      file gate. The API never derives `access_tier` from
      `InternalFile::isInternal()` and **never rejects a `public` manifest
      because the PDF is internal**. `data_tables.source_file_access` records
      the file's access status as provenance only.
    - `rights_basis` describes the legal basis for the data, not the
      audience; the two are independent.
    - **v1**: store the declared tier. `public` is readable by anyone,
      `registered` requires a logged-in session, and `internal` is
      **deny-by-default** in `#dataset-*`, `action=datasetquery` and the
      export endpoint (no group is granted it yet). This prevents a leak
      without building the full mechanism.
    - **Future** (explicitly not v1): a per-tier mechanism — a dedicated
      group (e.g. `dataset-internal` or `it`), page-level Lockdown, API/export
      gating, and optionally a separate schema/DB user for defense-in-depth.
      The schema and API contract reserve the field for it now.

## Schema (summary)

The schema mirrors the normalised shape in #7, with the following corrections.
The DDL is generated with the `wb_dataset_` prefix; the store map uses logical
table names and the service applies the prefix:

| Table | Key columns / indexes |
|---|---|
| `datasets` | `dataset_id` (unique), `manifest_hash`, `manifest_revision`, title, source item/page, DOI, year, licence (+label), rights basis (+label), `access_tier`, curator, `status` (`draft`/`reviewed`/`published`/`archived`), timestamps, `search_text` (FULLTEXT) |
| `data_tables` | `table_id` (unique), `dataset_id`, `table_number`, caption, page, `pdf_file`, `pdf_sha1`, `pdf_revision`, **`source_file_access`** (`public`/`internal`, provenance only), extractor/version, method, confidence, `review_status`, reviewer, review date, notes, `search_text` |
| `panels` | `panel_id` (unique), `table_id`, `panel_key`, label, order, description |
| `data_columns` | `column_id` (unique), `table_id`, `panel_key`, `column_key`, label, description, unit, datatype, role, missing token, sort order, **`column_parent_key`**, **`column_level`**, `search_text` |
| `observations` | `obs_id` (unique), `table_id`, `panel_key`, `row_index`, `row_key`, `row_label`, `row_path`, **`row_parent_key`**, **`row_depth`**, `column_key`, `value_float`, `value_int`, `value_text`, `value_date`, `flag_significant`, `flag_note`, `footnote_keys`, `source_page`, `cell_bbox`, `search_text` |
| `footnotes` | `footnote_id` (unique), `table_id`, `footnote_key`, `marker_raw`, text, scope, language, sort order, status, `search_text` |
| `extraction_runs` | `run_id` (unique), `table_id`, tool/version, model, prompt version, start/end, status, confidence, `report_json`, `diff_json` |

Value rule (unambiguous): `value_text` always holds the verbatim cell string;
exactly one of `value_float` / `value_int` / `value_date` holds the typed
machine value for numeric/date cells; `value_text` alone is the value for
non-numeric cells.

Indexes: `observations (table_id, panel_key, row_index, column_key)`,
`observations (table_id, column_key)`, `observations (table_id, row_key)`,
`footnotes (table_id, footnote_key)`, `data_columns (table_id, panel_key,
column_key)`, plus the unique IDs. FULLTEXT on the `search_text` columns.

`cell_bbox` is `x0,y0,x1,y1` in **PDF user-space points, origin top-left of
the page**, as produced by the extraction tool; the convention is recorded in
the manifest schema.

## Manifest contract (shared, contract-first)

The shape is fixed **before** the extractor is built, so the PDF extractor,
the MCP client and the wiki API cannot drift:

```
pdf-figures-mcp extract_pdf_table
        │  envelope { manifest, validation_report, manifest_path }
        ▼
mediawiki-mcp-server embeddable-dataset-upsert   (thin client)
        │  the same manifest object
        ▼
api.php?action=datasetupsert          (authoritative validate/apply)
```

- **Canonical definition**: `extensions/EmbeddableContent/includes/Spec/DatasetSchema.php` (manifest fields) + `Spec/DatasetSqlSchema.php` (DDL).
  `maintenance/emitDatasetContract.php` emits:
  - `contract/dataset-manifest.schema.json`
  - `contract/dataset-envelope.schema.json`
  - `contract/validation-report.schema.json`
  - `contract/dataset-store-fields.json` (manifest path → SQL column,
    `requiredFor: validate|apply`, and the wiki-generated fields)
  - `sql/dataset-schema.sql` (the `wb_dataset_*` DDL)
  - `contract/dataset-envelope.example.json` (wraps the hand-written
    `dataset-manifest.example.json`).
- **Pinned copies**: `mediawiki-mcp-server` and `ronzz-pdf-figures-mcp` copy
  the generated files + the golden example verbatim. The MCP server projects
  the field map to TypeScript (`npm run gen:dataset-contract`); the extractor's
  `pdf_figures.manifest` builder and `tests/test_manifest_contract.py` validate
  against the same schemas. CI fails on drift.
- **The extractor returns the envelope**, not a separate shape:
  `{ manifest, validation_report, manifest_path }`. `embeddable-dataset-upsert` accepts
  the envelope, a bare manifest, or a path to either. No adapter.
- **Stable IDs** (shared formula; the extractor emits, the wiki verifies):

  ```
  table_id    = dataset_id + "-t" + slug(table_number)
  panel_id    = table_id + "-" + panel_key
  column_id   = table_id + "-" + (panel_key or "p0") + "-" + column_key
  obs_id      = table_id + "-r" + row_index + "-" + (panel_key or "p0") + "-" + column_key
  footnote_id = table_id + "-" + footnote_key
  ```

- **Validation report**: `status` (`ok|warnings|errors`), `checks[]`
  (`code` from the API error vocabulary, `severity`, `path`, `message`,
  `hint`), `summary`, `tool`, `tool_version`, `generated_at`. The extractor's
  report is advisory; the wiki returns its own report in the same shape.
- **Strictness**: `additionalProperties: false` everywhere; a new field is a
  `manifest_version` bump coordinated across the three repos. Dates are
  `YYYY-MM-DD`, datetimes ISO 8601, bboxes arrays, `null` for missing,
  `footnote_keys` an array.
- **Now vs follow-up**: the contract, the golden example, the pinned copies,
  the MCP client and the extractor's builder land now. The
  `extract_pdf_table` table parser (PyMuPDF `find_tables`, OCR/vision fallback,
  footnote pairing, multi-level headers) is the follow-up and emits the
  already-fixed shape.

## Size budget

| Limit | Value | Why |
|---|---|---|
| Cells per dataset (v1) | **2,000** | keeps an apply under a minute and the page view small |
| Cells per dataset (hard reject) | **5,000** | beyond this the long format is the wrong shape; a wide per-dataset table or a dedicated analytical store is the migration path |
| Manifest JSON | **1 MB** | under `post_max_size`; the manifest page is under `$wgMaxArticleSize` |
| Rows per `#dataset-table` call | paginated, default 500 | bounded parser output |
| `action=datasetquery` rows | paginated, max 5,000 | bounded API response |

If real datasets exceed ~2,000 cells, the next step is a **wide per-dataset
observation table** (one row per source row, one column per measure) generated
from the manifest, not a return to Cargo.

## Write path (`action=datasetupsert`)

1. **Validate** (synchronous, `dryrun=1`): JSON Schema subset, allowed values,
   referential rules, footnote pairing, PDF revision SHA1, duplicate stable
   IDs, size budget. **Access is not validated against the source file**: an
   internal-use PDF may back a public dataset, because the extracted data is
   not copyright-protected. The file's access status is recorded as
   provenance (`source_file_access`), not as the dataset tier.
2. **Save the manifest** to `Dataset:<slug>/manifest` (JSON content model),
   conflict-checked against the revision the caller read.
3. **Enqueue `datasetApply`** with `run_id = dataset_id + manifest_hash`.
4. The job:
   - opens one MySQL transaction on the dataset database;
   - deletes the dataset's existing rows by `dataset_id` (child tables by
     `table_id`);
   - batch-inserts the new rows (500–1,000 per statement);
   - creates/updates the dataset and table items in `status=draft`;
   - commits;
   - verifies row counts and a sample;
   - clears the draft status / sets `status=published`;
   - purges the `Dataset:<slug>` page and any known embeds.
5. **Idempotent**: the `manifest_hash` + a stored manifest revision make a
   re-apply a no-op; a changed manifest replaces the dataset's rows in one
   transaction (readers see the old or the new snapshot, never a partial one).
6. **Errors** (API codes): `manifest-invalid`, `schema-mismatch`,
   `referential-integrity`, `pdf-provenance`, `page-conflict`,
   `page-too-large`, `store-error`, `wikibase-write`, `permission`,
   `size-limit`. A partial apply is `status=partial` with the failing step and
   the run id; the manifest remains the recovery input.

## Read path

- `DatasetStore` (PHP service) is the only reader; parser functions and the
  query API go through it.
- `#dataset-table` reads `observations` + `data_columns` for one `table_id`,
  pivots long → wide in PHP, renders a wikitable, and converts
  `footnote_keys` to `<sup><a href="#fn…">…</a></sup>`.
- `#dataset-footnotes` reads `footnotes` for the table and renders the notes
  list with backlinks.
- `#dataset-provenance` reads `data_tables` + `extraction_runs`.
- `action=datasetquery` accepts `dataset_id`/`table_id`, column filters,
  `search`, `order`, `limit`, `offset`, and `format=json|csv`; it applies the
  access filter (`public` anyone, `registered` logged-in, `internal`
  deny-by-default until the future mechanism).
- `#dataset-table` / `#dataset-footnotes` / `#dataset-provenance` apply the
  same access filter; an unauthorized `internal` read renders an access
  notice, not data.
- `Special:Datasets` lists datasets with status/year/access/review filters;
  `Special:EntityIndex/Dataset` continues to list the namespace.
- Cache: `datasetApply` purges the dataset view page and the pages registered
  as embeds. `#dataset-table` must call `ParserOutput::addTemplate` on the
  `Dataset:` page (or the `#dataset-*` template wrapper) so MediaWiki’s
  dependency tracking purges article caches too.

## Consequences

- **No Cargo install** on production or CI; no `$wgCargoDB*`, no Cargo
  templates, no `Special:Drilldown`.
- The dev/CI integration job must use **MySQL 8** for the dataset tests (the
  current stack is MariaDB 10.11), to match production.
- The manifest page is the audit/rebuild artifact; `mysqldump` of the dataset
  database is the fast restore path, and manifest replay is the correctness
  path.
- The `Dataset:` namespace, parser functions and API modules live in
  `EmbeddableContent`; the MCP `embeddable-dataset-upsert`/`-query`/`-status` tools live in
  `mediawiki-mcp-server`.
- `access_tier` is stored now but the full per-tier mechanism is future work;
  `internal` is deny-by-default until then. The source PDF's internal-use
  marker is provenance, never an API rejection reason.
- Charts are deferred: the Plotter image path is the v1 option; Wikimedia
  `Extension:Chart` (with a public `.tab` export) becomes viable after the
  MediaWiki 1.47 upgrade.
- The manifest contract is emitted from `DatasetSchema.php` and pinned in
  `ronzz-wikibase`, `mediawiki-mcp-server` and `ronzz-pdf-figures-mcp`; the
  extractor's `pdf_figures.manifest` builder and the MCP client land now, and
  the `extract_pdf_table` table parser is the follow-up that emits the fixed
  envelope.

## Migration of the issues

- **#6**: the “Storage: Cargo” decision is superseded by this ADR.
- **#7**: revised to the MySQL schema + `Dataset:` namespace + parser
  functions + `action=datasetupsert`; the Cargo setup, `#cargo_declare`
  templates and `Special:Drilldown` requirements are removed.
- **#9**: revised to the `embeddable-dataset-upsert` thin client + a generated
  manifest↔MySQL contract; the Cargo schema mapping is replaced by the
  generated MySQL field map.

## References

- Cargo 3.9.5 source: `CargoHooks.php` (`onPageSaveComplete`),
  `includes/parserfunctions/CargoStore.php`, `includes/CargoUtils.php`
  (indexing), `includes/CargoSQLQuery.php` (`$wgCargoMaxQueryLimit`),
  `extension.json` (`GroupPermissions`).
- MediaWiki: [Manual:Managing data in MediaWiki](https://www.mediawiki.org/wiki/Manual:Managing_data_in_MediaWiki),
  [Help:Tabular data](https://www.mediawiki.org/wiki/Help:Tabular_data),
  [Extension:Chart](https://www.mediawiki.org/wiki/Extension:Chart),
  [Extension:Cargo](https://www.mediawiki.org/wiki/Extension:Cargo),
  [Extension:Graph](https://www.mediawiki.org/wiki/Extension:Graph).
- Instance: `RonzzIT:Runbook/Wikibase`, `RonzzIT:Deployment/Wikibase`.
