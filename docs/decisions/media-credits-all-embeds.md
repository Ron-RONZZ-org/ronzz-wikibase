# Decision: credit every embedded file, however it is displayed

- **Status**: Accepted (Nov 2026)
- **Scope**: content-page embeds of images, audio, video and PDFs
- **Decider**: Rongzhou (`ron@ronzz.org`)
- **Amends**: the thumbnail-only credit in `extensions/AGENTS.md`

## Context

`Media\ImageCredit` only credited `thumb`/`framed` bitmap/drawing images. A
plain `[[File:X|Caption]]`, an audio/video player, a PDF link and a gallery
showed no attribution even when the file's item carried author/license/source
facts. Core has no caption slot for the captionless forms, so extending the
server-side caption hook alone cannot cover them.

## Decision

1. **Drop the media-type guard.** A captioned embed of any file type (image,
   audio, video, PDF) gets the credit line.
2. **Captionless embeds get the credit client-side.** `Hooks::
   wireEmbeddedMediaOpenLinks` resolves a `credit` line for every file on the
   page (the same `ImageCredit::creditLineFor` builder) into the
   `wbEmbeddedFiles` map; `resources/mediaopen.js` appends it (and the open
   link) after plain images, audio/video players and PDF/EPUB/DJVU links.
   The server-built fragments are already escaped; the module inserts them
   with `.html()`. A figure caption that already carries the server open
   link (`.wb-media-open`) is left alone — appending another would show
   "Open in a new tab" twice.
3. **Galleries get ONE paragraph below the gallery** for all their images
   (credit + open link per image), not one line per thumbnail. Infoboxes
   (`.wb-infobox`, `.wb-image-gallery`) are skipped — a compact data summary,
   not a content figure. The infobox tables get a `wb-infobox` class on the
   on-wiki templates; CSS also hides the credit/open link there.
4. **Suppression** stays `class=noCredit` (canonical) and a bare `noCredit`
   caption. The server strips the token and leaves a `wb-no-credit` marker on
   the image wrapper so the client-side credit also skips it.
5. **Internal-use files**: the module skips the open link/credit for an
   anonymous reader (`mw.user.isAnon()` + the map's `internal` flag); the
   server-side thumbnail hook is already stopped by `InternalFileGuard`.

## Consequences

- Attribution is visible on every content-page file embed, not just framed
  thumbnails.
- Captionless credits are client-rendered (the wiki already requires JS for
  its toolbars); the printed live page includes them, the `noprint` open link
  does not. Server-rendered thumbnail credits are unchanged.
- The `wbEmbeddedFiles` map grows by the credit line per file; it is parser
  output, so a **parser-cache purge** is required on deploy.
- Tests: `run_imagefacts_e2e.py` asserts the captionless map credit; the
  existing thumbnail/noCredit assertions remain; `run_pages_e2e.py` keeps the
  module-source contract.
- On-wiki: add `wb-infobox` to the infobox tables (deploy step).

## References

- `includes/Media/ImageCredit.php`, `includes/Hooks.php`
  (`wireEmbeddedMediaOpenLinks`)
- `resources/mediaopen.js`, `resources/fileviewer.css`
- `tests/e2e/run_imagefacts_e2e.py`

## Amendment (Nov 2026): credits inside a wikitable

The per-cell credit line broke table layout: a credit in an 80 px cell sets
the table's max-content width (`Crystal` rendered 1432 px in a 752 px
column) and makes every row taller. **Credits for images inside a
`table.wikitable` now move out of the cells into ONE paragraph immediately
below the table**, one file per line, in the table order:
`Image: <file> · <author> · <source> · <licence>` (the file name links to its
File: page). The server adds this line as `tableCredit` in
`wbEmbeddedFiles` (`ImageCredit::creditLinesFor`), and
`resources/mediaopen.js` relocates every `.wb-image-credit` from the table
into `.wb-table-credits`. The inline `Open in a new tab` / `File page` links
stay in the cell. Infobox tables are excluded, as before. See
`content-tables-and-fullscreen.md`.
