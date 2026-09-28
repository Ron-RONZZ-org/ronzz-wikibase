# AGENTS.md — CSVTable extension

## Summary

Standalone MediaWiki extension that renders an uploaded **CSV file as a wiki
table** when embedded with `[[File:data.csv]]`. Upload/versioning use the
standard `Special:Upload` + `File:` machinery; the extension registers a
`text/csv` media handler so the file embeds as a `<table class="wikitable">`
instead of a plain file link.

No mainstream maintained MediaWiki extension does this (see
`../../docs/decisions/csv-table-embed.md` for the survey) — it is the same
house media-handler pattern as `GeoGebra`.

Design rationale: `../../docs/decisions/csv-table-embed.md`.

## How it works

1. **MIME** — `text/csv` is already a core MimeMagic type (`csv` extension),
   and a `.csv` upload detected as `text/plain` is improved to `text/csv` by
   core's `improveTypeFromExtension()` textual-type rule. No MIME hook is
   needed; `$wgFileExtensions[] = 'csv'` is set in the instance config
   (extension.json cannot append to that array).
2. **Handler** (`CSVTableHandler`, an `ImageHandler`): a CSV is not an image
   and has no pixel size, but `File::allowInlineDisplay()` is
   `handler->canRender()`, so the handler reports `canRender() = true` and a
   nominal size, and `doTransform()` returns `CSVTableOutput`. No thumbnail
   file is written.
3. **Output** (`CSVTableOutput`, a `MediaTransformOutput`): reads the file's
   local path at render time, parses it (`CSVTableParser`, pure) and renders
   a `wikitable`. **Every cell is HTML-escaped — this is the XSS boundary.**
   Oversized / unreadable / over-capped files degrade to an error notice.
4. **Parser** (`CSVTableParser`, pure PHP, unit-tested): RFC-4180 quoting via
   `fgetcsv`, UTF-8 BOM strip, delimiter auto-detection (`,`/`;`/tab/`|`) and
   row/column caps.

## Constraints and Invariants

- **Escape every cell** before rendering — the XSS suite/E2E gates it.
- **Cap size, rows and columns** (`CSVTableMaxBytes`/`Rows`/`Cols`) — a large
  upload must never hang a page render.
- **No thumbnail file** is written; the table is built from the source at
  render time, so the `[[File:…]]` usage is the parser-cache dependency and
  the table follows the current revision.
- i18n ships en/fr/eo (+ qqq).
- No DB/seed/manifest/config-map surface — a deploy is an rsync +
  `wfLoadExtension` + `$wgFileExtensions[] = 'csv'` + php-fpm restart/cache
  purge.

## Input/Output Expectations

- **Input**: an uploaded `.csv`; `[[File:…]]` (width params are accepted but
  ignored — the table is full-width).
- **Output**: a `<table class="wikitable csv-table">` (HTML), or an error
  notice / download link.
- **Unit-test surface**: `tests/Unit/CSVTableParserTest.php` (pure parser).
  The MW-bound handler + rendered table are covered by
  `tests/e2e/run_csv_e2e.py` (dev-stack CI + live).

## Documentation Reference

- `../../docs/decisions/csv-table-embed.md` — the design decision + the
  existing-extension survey
- On-wiki: `Help:Contributing/richMediaContent`
- `RonzzIT:Deployment/Wikibase` + `RonzzIT:Runbook/Wikibase` — instance ops

## Domain-Specific Rules for Agents

- Do not switch to a client-side renderer or a Node sidecar — server-side
  HTML from the file is the design.
- Keep `CSVTableParser` free of MediaWiki dependencies (unit-testable).
- When raising a cap, update the config description and the E2E.
