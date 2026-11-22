# EmbeddableContent field contract

`field-contract.json` is the canonical, machine-readable field contract of the
entity-mode Add\* flows (quotation/math/code-snippet, citation sources,
semantic entities). It is a **projection** of the single authoring source — the
`Flow/SpecialContentFieldMap`, `Flow/SourceFieldMap` and
`Flow/SemanticEntityFieldMap` classes — assembled by `Flow/FieldContract` and
written by `maintenance/emitFieldContract.php` (a pure PHP script; the maps
carry no MediaWiki runtime dependency).

Downstream clients consume this artifact instead of re-encoding the field
lists: the MediaWiki MCP server generates its embeddable add tools' input
schemas from a pinned copy. That is what stops the drift where a field is
advertised by an `action=*-fields` endpoint (or a `Special:Add*` form) but the
write module or the client silently drops it.

## Regenerate

Run after ANY change to a `Flow/*FieldMap` class, and commit the result:

```sh
php extensions/EmbeddableContent/maintenance/emitFieldContract.php
```

`tests/Unit/FieldContractTest` fails if the committed file is not the exact
projection of the maps.

## Who reads it

- The API modules derive their accepted params from the maps
  (`ApiAdd*::fieldParams()`), never a hardcoded copy:
  `ApiAddSource` and `ApiAddSpecialContent` return `*FieldMap::ALL_FIELDS`;
  `ApiAddSemanticEntity` returns `SemanticEntityFieldMap::apiParamFields()`
  (`ALL_FIELDS` minus the typed `pageKind`).
- The `action=*-fields` discovery endpoints report the same maps.
- The MediaWiki MCP server (`Ron-RONZZ-org/mediawiki-mcp-server`) pins a
  verbatim copy at
  `src/tools/extensions/embeddable-content/contract/field-contract.json` and
  generates its tool schemas with `npm run gen:field-contract`.

## Dataset manifest contract (canonical)

The single contract shared by the curated-dataset pipeline:

```
pdf-figures-mcp extract_pdf_table
        │  envelope { manifest, validation_report, manifest_path }
        ▼
mediawiki-mcp-server embeddable-dataset-upsert   (thin client)
        │  the same manifest object
        ▼
api.php?action=datasetupsert          (authoritative validate/apply)
        │
        ▼
MySQL measurement store  +  Wikibase identity/provenance  +  Dataset: pages
```

The extractor's output **is** the client's input and the API's input. There is
no shape adapter anywhere.

## Files

| File | Kind | Purpose |
|---|---|---|
| `dataset-manifest.schema.json` | generated | The manifest JSON Schema (2020-12). |
| `dataset-envelope.schema.json` | generated | `{ manifest, validation_report, manifest_path }`. |
| `validation-report.schema.json` | generated | The report shape emitted by the extractor **and** the wiki. |
| `dataset-store-fields.json` | generated | Manifest path → SQL `table.column`, `requiredFor`, and the wiki-generated fields. |
| `dataset-manifest.example.json` | **hand-written** | The golden manifest fixture; validated by every consumer's tests. |
| `dataset-envelope.example.json` | generated | The manifest example wrapped with a report and a path. |
| `../sql/dataset-schema.sql` | generated | The MySQL DDL (`wb_dataset_*` tables). |

`contract/dataset-manifest.example.json` is the only hand-maintained contract
artifact. Every generated file is a projection of
`includes/Spec/DatasetSchema.php` (manifest) and `includes/Spec/DatasetSqlSchema.php`
(DDL); editing a generated file by hand is a bug.

## Regenerate

```bash
php extensions/EmbeddableContent/maintenance/emitDatasetContract.php
```

Pure PHP (no MediaWiki bootstrap), so it runs in the unit job. CI re-runs it
and fails on `git diff --exit-code`; `DatasetSchemaTest` also compares the
committed bytes at test time.

## The manifest

- `manifest_version` is `"1.0"`. A consumer rejects an unknown major.
- `dataset` holds identity/provenance/citation metadata. `access_tier`
  (`public` / `registered` / `internal`) is **orthogonal** to the source PDF's
  internal-use marker; the API never infers it from the file.
- `tables[]` holds one entry per table, with nested `panels`, `columns`,
  `observations`, `footnotes`, `extraction_runs`.
- Arrays are JSON arrays (`footnote_keys`, `bbox`), never comma strings.
- BBoxes are `[x0, y0, x1, y1]` in PDF user-space points, origin top-left.
- Dates are `YYYY-MM-DD`; datetimes are ISO 8601 with a timezone.
- Missing values are `null`; booleans are JSON booleans.
- `value_text` always holds the verbatim cell string; exactly one of
  `value_float` / `value_int` / `value_date` holds the typed value for
  numeric/date cells; non-numeric cells use `value_text` alone.

### Stable IDs

The formula is shared so the extractor can emit references and the wiki can
verify them:

```
table_id    = dataset_id + "-t" + slug(table_number)
panel_id    = table_id + "-" + panel_key
column_id   = table_id + "-" + (panel_key or "p0") + "-" + column_key
obs_id      = table_id + "-r" + row_index + "-" + (panel_key or "p0") + "-" + column_key
footnote_id = table_id + "-" + footnote_key
```

`table_id` may be omitted; the wiki derives it. `panel_id`, `column_id`,
`obs_id` and `footnote_id` are not manifest fields — the wiki generates them
from the formula. The caller supplies `panel_key`, `column_key`, `row_index`,
`row_key` and `footnote_key`.

### Required for validate vs apply

The JSON Schema's `required` lists the fields needed for `dryrun=1`. The
`requiredFor` map in `dataset-store-fields.json` adds the apply-only fields
(`dataset.source_page`, `dataset.source_item`, `tables[].pdf_file`,
`tables[].pdf_sha1`, `tables[].extractor`, `tables[].extractor_version`,
`tables[].method`, `tables[].extraction_runs[].tool`,
`tables[].extraction_runs[].tool_version`). The wiki API is authoritative;
the MCP client uses the same map for a fast pre-check.

### Wiki-generated fields

`dataset-store-fields.json.generated` lists the SQL columns the wiki fills:
`search_text`, timestamps, `manifest_hash`, `manifest_revision`, the derived
IDs, `data_tables.dataset_id`, `extraction_runs.table_id`. A manifest must not
send them.

## The validation report

Both the extractor and the wiki emit:

```json
{
  "status": "ok | warnings | errors",
  "checks": [
    { "code": "manifest-invalid", "severity": "error", "path": "tables[0].observations[2].value_text", "message": "…", "hint": "…" }
  ],
  "summary": { "tables": 1, "panels": 1, "columns": 2, "observations": 4, "footnotes": 1 },
  "tool": "pdf-figures-mcp",
  "tool_version": "0.2.0",
  "generated_at": "2026-11-22T17:00:00Z"
}
```

`checks[].code` uses the API error vocabulary (`manifest-invalid`,
`referential-integrity`, `pdf-provenance`, `page-conflict`, `page-too-large`,
`schema-mismatch`, `store-error`, `wikibase-write`, `permission`,
`size-limit`). The extractor's report is advisory; the wiki re-validates and
returns its own report in the same shape.

## Pinning

The generated files + the example are pinned **verbatim** in:

- `mediawiki-mcp-server` → `src/tools/extensions/embeddable-content/contract/`
  (with `scripts/gen-dataset-contract.cjs` projecting the field map to
  TypeScript; `preflight` regenerates and diffs).
- `ronzz-pdf-figures-mcp` → `contract/`
  (`tests/test_manifest_contract.py` validates the example and the builder
  output with `jsonschema`).

Refresh both after a change here:

```sh
# mediawiki-mcp-server
cp <ronzz-wikibase>/extensions/EmbeddableContent/contract/dataset-*.json \
   <mediawiki-mcp-server>/src/tools/extensions/embeddable-content/contract/
npm run gen:dataset-contract

# pdf-figures-mcp
cp <ronzz-wikibase>/extensions/EmbeddableContent/contract/dataset-*.json \
   <pdf-figures-mcp>/contract/
```

## Versioning

- Adding an optional field is a **minor** bump (`1.1`) and all three repos
  update their pinned copies in the same change.
- Removing/renaming a field or changing a type is a **major** bump (`2.0`).
- `additionalProperties: false` everywhere: an unlisted field is rejected, so
  a producer/consumer pair cannot silently disagree.
