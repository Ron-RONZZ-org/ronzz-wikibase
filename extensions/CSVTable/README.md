# CSVTable

Renders an uploaded CSV file as a wiki table when embedded on a page:

```wikitext
[[File:Sales-2026.csv]]
```

The first row is rendered as a header (configurable). The table is built
server-side from the file at render time; every cell is HTML-escaped.

## Installation

```php
wfLoadExtension( 'CSVTable' );
$wgFileExtensions[] = 'csv';
```

`text/csv` is a core MIME type, so no MIME hook is required.

## Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| `$wgCSVTableDelimiter` | `auto` | Field delimiter, or `auto` to detect from the first line (`,` `;` tab `\|`). |
| `$wgCSVTableFirstRowHeader` | `true` | Render the first row as `<th>`. |
| `$wgCSVTableMaxBytes` | `262144` | Maximum embedded file size (bytes). |
| `$wgCSVTableMaxRows` | `500` | Maximum rendered rows. |
| `$wgCSVTableMaxCols` | `50` | Maximum rendered columns. |

Files past a cap render an error notice instead of a table. See
[`AGENTS.md`](AGENTS.md) and [`../../docs/decisions/csv-table-embed.md`](../../docs/decisions/csv-table-embed.md).
