# Decision: PDF extraction runs on a dedicated MediaWiki job queue

- **Status**: Accepted (Nov 2026)
- **Scope**: `Jobs/ExtractPdfTextJob`, `Hooks::onPageSaveComplete`,
  `maintenance/enqueuePdfExtractions.php`
- **Decider**: Rongzhou (`ron@ronzz.org`)
- **Amends**: `pdf-text-extraction.md` §6 (the extraction runner)

## Context

The first runner was a 10-minute cron calling
`extractPdfText.php --scan=50 --limit=1`. `--limit` counted SUCCESSFUL
extractions, not attempts, so the loop kept trying candidates until one
succeeded. A PDF that exceeds the 6-minute Tika client timeout therefore
consumed ~6 minutes per attempt, and a backlog of such PDFs could keep one
run alive for hours — continuously OCRing on a 2-vCPU box. The 2026-11-21
deploy added `flock` + `timeout 540` as a safety rail, which bounds a run
but makes the 10-minute slot a permanent worker whenever the newest files
are unparseable. Attempt-counting alone would be worse: the scan is
newest-first, so one poison PDF would starve every older file.

## Decision

1. **MediaWiki's job queue is the queue.** It is already DB-backed
   (`JobQueueDB`, the `job` table) and drained by the existing job runners —
   no Redis, RabbitMQ or sidecar.
2. **Producer.** `Hooks::onPageSaveComplete` enqueues one `extractPdfText`
   job for a PDF `File:` page save (upload, re-upload, restore).
   `removeDuplicates` makes a re-save while a job is pending a no-op.
   `maintenance/enqueuePdfExtractions.php` backfills PDFs that predate the
   queue.
3. **Consumer.** The type is excluded from the default queue
   (`$wgJobTypesExcludedFromDefaultQueue[] = 'extractPdfText'`) because OCR
   can take minutes. A dedicated 10-minute root cron runs
   `runJobs.php --type=extractPdfText --maxjobs=1 --maxtime=480` under
   `flock` + `timeout 540`, as `www-data` (the upload tree is
   www-data-owned).
4. **Failure policy.** The job returns false on an extraction error, so the
   runner releases it for retry (`maxTries` defaults to 3, `job_attempts`
   increments on each claim); after the retries the row is pruned. One
   unparseable PDF cannot block the queue. A current companion or a non-PDF
   title acks immediately, so a metadata-only save is cheap.
5. **Ordering** is `timestamp` (oldest first) via `$wgJobTypeConf`.
6. **Backfill.** `enqueuePdfExtractions.php --scan=N [--dry-run] [--force]`
   enqueues the PDFs that need extraction; it never extracts inline.

## Consequences

- Each PDF is retried at most `maxTries` times at 10-minute intervals, then
  dropped; a later backfill can re-enqueue it.
- The shared `runJobs.php` cycle and request-time jobs never pick up the
  type, so the wiki's general job load is unaffected.
- The queue does not make OCR faster. The Tika per-document timeout remains
  the real bound; a very large scan can still fail three times and be
  dropped.
- Tests: the CI integration job uploads a PDF, asserts the save hook
  enqueued one job, asserts the default jobrunner did not consume it
  (`job_attempts = 0`), runs the dedicated worker once and asserts the job
  failed and was released (`job_attempts = 1`); the unit job lints the new
  PHP.
- `extractPdfText.php` stays the manual/operator tool (`--file`,
  `--scan`/`--limit`, `--dry-run`, `--force`/`--all`, `--source`); the
  scanner cron is retired.

## References

- `extensions/EmbeddableContent/includes/Jobs/ExtractPdfTextJob.php`
- `extensions/EmbeddableContent/maintenance/enqueuePdfExtractions.php`
- `extensions/EmbeddableContent/includes/Hooks.php`
- `dev/config/Extensions.php`, `.github/workflows/ci.yml`
- `docs/decisions/pdf-text-extraction.md`
