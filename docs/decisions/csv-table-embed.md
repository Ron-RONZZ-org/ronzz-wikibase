# ADR — CSV files embed as wiki tables (`extensions/CSVTable`)

Status: accepted (2026-09-28). Owner: ronzz.org.

## Context

Editors want to upload tabular data as a CSV file (`Special:Upload`) and
embed it on a page as a wiki table with `[[File:data.csv]]` — the natural
expectation, mirroring `[[File:x.ggb]]` (GeoGebra) and `[[File:x.png]]`.

MediaWiki core has no handler for `text/csv`, so `[[File:data.csv]]` renders
as a plain file link. A survey of existing extensions found **no maintained
extension that renders an uploaded wiki file as a table**:

| Extension | What it does | Why it does not fit |
|-----------|--------------|---------------------|
| `Extension:SimpleTable` | renders pasted TSV/CSV in `<tab>` tags | unstable/unmaintained; not a file embed |
| `Extension:External Data` | reads CSV from whitelisted **server filesystem** paths or URLs | does not read wiki `File:` pages; pointing it at the upload dir is fragile (hashed paths) and a new security surface |
| `Extension:DataTables` | bundles the jQuery DataTables library for developers | unmaintained; not a user feature |
| `Extension:JsonConfig/Tabular` | `Data:*.tab` pages (JSON) | not CSV uploads |
| `Extension:Cargo` | DB-stored tables | not CSV embeds |

## Decision

Build a small **house extension, `extensions/CSVTable/`**, following the
established GeoGebra media-handler pattern:

1. `text/csv` is already a core MimeMagic type (`csv` extension); a `.csv`
   upload detected as `text/plain` is improved to `text/csv` by core's
   textual-type rule. The instance sets `$wgFileExtensions[] = 'csv'`.
2. `CSVTableHandler` (an `ImageHandler`) reports `canRender() = true` — the
   parser's inline-display gate (`File::allowInlineDisplay()` →
   `handler->canRender()`) — and a nominal size, and returns `CSVTableOutput`.
3. `CSVTableOutput` reads the file's local path at render time, parses it
   (`CSVTableParser`, pure) and renders `<table class="wikitable csv-table">`.
   Every cell is `htmlspecialchars`-escaped (**the XSS boundary**). Size,
   row and column caps degrade to an error notice, never a broken page.
4. `CSVTableParser` (pure PHP, unit-tested) does RFC-4180 quoting via
   `fgetcsv`, UTF-8 BOM strip and delimiter auto-detection (`,` `;` tab `|`).

## Consequences

- `[[File:data.csv]]` renders a table everywhere the parser embeds the file,
  including the File: page; uploads/versioning use stock MediaWiki.
- The `[[File:…]]` usage is the parser-cache dependency, so the table follows
  the current file revision (no stored thumbnail to invalidate).
- Config: `$wgCSVTableDelimiter` (`auto`), `$wgCSVTableFirstRowHeader`
  (`true`), `$wgCSVTableMaxBytes` (256 KiB), `$wgCSVTableMaxRows` (500),
  `$wgCSVTableMaxCols` (50).
- Deploy: rsync the extension + `wfLoadExtension( 'CSVTable' )` +
  `$wgFileExtensions[] = 'csv'` + php-fpm restart/cache purge. No DB/seed/
  manifest/config-map surface.

## Alternatives considered

- **Parser function `{{#csv-table:File:Data.csv}}`** — simpler (no MIME/handler
  plumbing) but does not match the requested `[[File:]]` UX and is more
  verbose to author.
- **External Data pointed at the upload directory** — fragile (hashed paths)
  and widens the filesystem-read surface for little gain.
