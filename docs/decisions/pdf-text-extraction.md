# Decision: PDF → Markdown extraction (Tika) + Source qualifier + MCP text tool

- **Status**: Accepted (Nov 2026)
- **Scope**: access-file PDFs, `Special:SourceText`, `action=sourcefiletext`,
  the MediaWiki MCP `get-file-text` tool
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Models and readers had to download a PDF and parse it themselves. Scanned
PDFs have no text layer, so a local parser returns nothing. Apache Tika 4 +
Tesseract already runs for Open WebUI (`owui-tika`, `latest-full`, OCR
`AUTO`); the wiki server is host PHP-FPM and cannot reach the internal
`openwebui_default` network today.

## Decision

1. **Tika on host loopback.** Publish `owui-tika` on
   `127.0.0.1:9998` only (no public port) and use
   `PUT http://127.0.0.1:9998/tika/json/md` (Tika 4's JSON envelope; content
   under `tk:content`). Timeout 360 s (Tika's default is 1 h); a
   `PARTIAL_TIMEOUT` is recorded as partial.
2. **Storage:** a deterministic text File companion
   `<name>.extracted.txt` (Markdown content; `.txt` avoids a per-instance MIME
   map entry). It carries the SAME `InternalFile` marker/category as the PDF,
   so nginx gates internal extractions exactly like the source file. The File
   page text links the PDF and notes the Tika/partial provenance.
3. **Companion item + Source link:** the `.txt` File page gets a sitelinked
   item (`instance of access file`, `file` URL). Every Source item whose
   `has access file` statement points at the PDF's access-file item gets an
   **`extracted text` qualifier** (new wikibase-item property) pointing at
   that item. Reverse lookup uses WDQS; the maintenance script also accepts
   `--source=Q…` for an explicit link (WDQS may lag).
4. **Access = the source file.** `Special:SourceText?file=File:X.pdf` and
   `action=sourcefiletext` read the companion: public PDFs are readable by
   anyone, internal-use PDFs require a logged-in session. The API is the
   MCP/LLM fast path (no PDF download, no client OCR).
5. **Links:** `Special:SourceFile` and the `File:` page toolbar show
   **Read extracted text** / **Open in a new tab**; `{{#source-access:}}`
   renders a `text` link next to the PDF (from the qualifier).
6. **Extraction runs on a dedicated job queue** (ADR
   `pdf-text-extraction-queue.md`): a PDF File page save enqueues one
   `extractPdfText` job (`removeDuplicates`); the type is excluded from the
   default queue and a 10-minute worker runs
   `runJobs.php --type=extractPdfText --maxjobs=1 --maxtime=480`. A failed
   extraction is retried up to `maxTries` (3), then pruned.
   `maintenance/extractPdfText.php` remains the manual/backfill tool
   (`--file`, `--scan`/`--limit`, `--all`/`--force`, `--dry-run`,
   `--source`), and `maintenance/enqueuePdfExtractions.php` backfills PDFs
   that predate the queue.
7. **MCP:** a new `get-file-text` tool calls `action=sourcefiletext` and
   returns the Markdown + metadata; `get-file-content` remains the
   file-bytes/local-extraction tool and points to `get-file-text` for the
   wiki extraction/OCR path.

## Consequences

- Readers/models get searchable text from scanned and text-layer PDFs without
  downloading; internal-use text stays login-gated.
- The `.txt` companion consumes file storage (~the extracted text size) and
  is re-generated when the PDF is replaced.
- Tika is loopback-only but has no auth: any local process can call it. The
  Tika deployment runbook documents the port publish and the service limits.
- The extraction is best-effort; a Tika failure leaves the PDF untouched and
  the cron retries on the next run.
- Deploy: extension rsync + `php-fpm` restart + parser-cache purge; the
  `extracted text` property via the D1 importer + full seed re-emission
  (`sourceProperties.extractedText`); `$wgFileExtensions[] = 'txt'`; Tika
  loopback publish; the queue settings + a dedicated worker cron (see
  `pdf-text-extraction-queue.md`). The MCP tool is a separate
  image bump.
- Tests: `TikaClientTest` (envelope/partial/failure), `AccessEntries`
  qualifier attach, `SourceAccessRenderer` text link; a live Tika run is
  manual (CI has no Tika service).

## References

- `includes/Pdf/TikaClient.php`, `includes/Pdf/PdfTextExtractor.php`,
  `includes/Api/ApiSourceFileText.php`,
  `includes/Spec/SpecialSourceText.php`,
  `maintenance/extractPdfText.php`
- `manifests/properties.csv` (`extracted text`), `seed/config_builder.py`
- `resources/sourcefiletext.js` / `.css`, `resources/filepage.js`
- mediawiki-mcp-server `get-file-text`
