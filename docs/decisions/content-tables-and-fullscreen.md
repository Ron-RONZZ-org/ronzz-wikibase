# Decision: content-page tables, figure width and the native fullscreen viewer

- **Status**: Accepted (Nov 2026)
- **Scope**: classic content pages — wikitables, thumbnail/frame figures,
  embedded image credits and the click-to-view affordance
- **Decider**: Rongzhou (`ron@ronzz.org`)
- **Amends**: `media-credits-all-embeds.md` (wikitable credits),
  `fullscreen-removed-open-newtab.md` (native fullscreen for content media)

## Context

Three live layout reports on the same pages:

1. **A wikitable overflows the content column and misaligns its rows** —
   `Crystal#Unit_cell_and_lattice_system` rendered **1432 px wide inside a
   752 px column**. Each small image cell also carried the automatic credit
   line (`Image: author · license · source`, a long source URL), which set
   the table's max-content width and made every row taller.
2. **A thumbnail/frame is wider than its media** — `Solid oxide fuel cell`'s
   250 px videos rendered in **542 px figures** because the figcaption's
   credit line set the figure's max-content width. The result was a large
   empty side margin and a squeezed text column.
3. **A tall float overlaps the next section** — `Crystal#Defect`'s video
   figure (371 px tall) ran into the next `h4`, which started 45 px above the
   figure's bottom, because MediaWiki headings do not clear floats.

The reader-facing click also went to the `File:` description page — a
behind-the-scenes page most readers do not expect when they click a picture.

## Decision

1. **Wikitable credits move to one paragraph below the table.** The server
   emits a `tableCredit` line per file in `wbEmbeddedFiles`
   (`Image: <file> · <author> · <source> · <licence>`, the file name linked
   to its File: page); `resources/mediaopen.js` removes every
   `.wb-image-credit` from the table cells and renders ONE
   `.wb-table-credits` paragraph after the table, one file per line. The
   inline `Open in a new tab` / `File page` links stay in the cell.
2. **Wikitables scroll, not overflow.** `resources/contentlayout.js` wraps
   each content `table.wikitable` (infoboxes excluded) in
   `.wb-table-block > .wb-table-tools + .wb-table-scroll`; the scroll box is
   `max-width: 100%; overflow-x: auto`.
3. **Figures are pinned to their media width.**
   `contentlayout.js` sets a thumb/frame's used width to the media's declared
   `width` (and `max-width: 100%`), so a long caption or credit wraps instead
   of widening the box.
4. **Block elements clear floats.** `contentlayout.css` applies
   `clear: both` to content headings, wikitables, table blocks, galleries and
   the references block. A tall float no longer overlaps the next section.
5. **Content images open the native fullscreen viewer on click.** A plain
   left click on a File:-linked content image requests fullscreen on its
   `<figure>` (or the `<img>` for a plain/gallery image); the viewer swaps
   the rendered thumbnail for the ORIGINAL media URL, and restores the
   thumbnail on exit. Ctrl/Cmd/Shift/middle click still follows the File:
   link, and a small noprint **File page** link is added next to the existing
   **Open in a new tab** so the description page stays reachable.
   **Native Fullscreen API only** — no `position: fixed` wrapper, which is
   what forced the earlier PDF fullscreen removal.
6. **Tables get the same native fullscreen** through a **Full screen**
   button in the table's toolbar row.

## What this does NOT do

- **No click hijack for arbitrary linked images.** Only an image wrapped in
  a File: description link is intercepted; `[[File:X|link=Article]]` keeps
  its article link.
- **No fullscreen for video.** The browser's native video controls already
  have one; clicking the video still plays it.
- **No fullscreen control on the PDF iframe.** The browser's PDF viewer has
  its own.
- **No infobox changes.** Infoboxes (`.wb-infobox`, `.wb-image-gallery`)
  remain credit/open-link/fullscreen free (a compact data summary).

## Consequences

- Widespread layout improvements beyond the reported pages: any wikitable
  with a long caption/credit now fits or scrolls, and any tall thumbnail
  clears the next heading.
- The client-side passes are progressive: a JS-disabled reader keeps the
  inline credits (and the old table width), but the credit/open-link UX
  already required JS.
- `wbEmbeddedFiles` gains `tableCredit`, so the server output changed — a
  **parser-cache purge** is required on deploy. The new i18n keys need a
  message-blob purge.
- The fullscreen viewer shows the original media, not the article thumbnail;
  for very large originals the browser scales to the viewport.
- Tests: `Spec\ImageCredit::tableLine` (unit), the page-flow re-upload and
  module-source guards, and the Playwright table/figure/fullscreen check in
  `run_wiki_ux_e2e.mjs`.

## References

- `extensions/EmbeddableContent/resources/contentlayout.js` /
  `contentlayout.css`
- `extensions/EmbeddableContent/resources/mediaopen.js` (table relocation,
  detail link, `data-wb-media-url`)
- `extensions/EmbeddableContent/includes/Media/ImageCredit.php`
  (`creditLinesFor`), `includes/Hooks.php` (`wireEmbeddedMediaOpenLinks`,
  `wireContentLayout`), `includes/Spec/ImageCredit.php` (`tableLine`)
- `tests/e2e/run_wiki_ux_e2e.mjs`, `tests/Unit/Spec/ImageCreditTest.php`
