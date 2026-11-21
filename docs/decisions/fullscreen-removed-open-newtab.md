# Decision: remove the PDF fullscreen feature; generalise “Open in a new tab”

- **Status**: Accepted (Nov 2026)
- **Scope**: `File:` pages, `Special:SourceFile`, files embedded on content pages
- **Decider**: Rongzhou (`ron@ronzz.org`)
- **Supersedes**: `file-pdf-viewer.md` (fullscreen part)

## Context

The browser-native PDF viewer (`resources/pdfviewer.js`) added a toolbar with
**View full screen** and **Open in a new tab**. The fullscreen control wrapped
the viewer in a `position: fixed; inset: 0` element with a flex iframe; it
caused unexplained blank areas at the bottom of the page and needed ongoing
maintenance, while the browser’s own PDF viewer already provides a native
fullscreen button. The “Open in a new tab” link existed only for PDFs, although
every File page already exposes its media URL (`wbFileUrl`).

## Decision

1. **Remove the fullscreen feature entirely** — the toolbar, the
   `requestFullscreen`/`webkit` fallback, the fixed-position CSS, the module
   JavaScript and the `embeddablecontent-pdf-fullscreen*` messages. The PDF
   remains a plain same-origin iframe (the browser’s native viewer); the iframe
   sizing CSS stays.
2. **Generalise “Open in a new tab”**:
   - the `File:` page toolbar gains an **Open in a new tab** link to the raw
     media URL (`wbFileUrl`) for every file type;
   - `Special:SourceFile` renders the same link for every preview type
     (PDF, image, audio, video, other);
   - files embedded on content pages get a small `noprint` link next to the
     embed. Thumbnails/frames get it in the caption server-side
     (`Media\FileOpenLink`); captionless images, audio/video players,
     PDF/EPUB/DJVU link embeds and galleries are handled by
     `resources/mediaopen.js`, fed by a `ParserOutput` JS config map
     (`wbEmbeddedFiles`).
3. **Galleries** get one paragraph below the gallery for all their images, not
   one link per thumbnail.
4. **Infoboxes are excluded** (`.wb-infobox`, `.wb-image-gallery`): they are a
   compact data summary, not content figures. The infobox tables get a
   `wb-infobox` class on the on-wiki templates; CSS hides the affordance there.

## Consequences

- No fullscreen maintenance and no fixed-position layout regressions.
- Readers can reach the raw media from any File page, `Special:SourceFile`, or
  content-page embed.
- The content-page links are `noprint`; the printed page keeps the media and
  its credit, not the navigation chrome.
- `resources/pdfviewer.js` is deleted; `resources/pdfviewer.css` becomes the
  shared `resources/fileviewer.css` (iframe + open-link styles). The
  `ext.embeddableContent.pdfviewer` module is replaced by the style-only
  `ext.embeddableContent.fileviewer`.
- Changing the rendered HTML requires a **parser-cache purge** on deploy
  (the caption open link and the `wbEmbeddedFiles` map are parser output).
- No new dependency; the content-page enhancer is a small jQuery module.

## References

- `resources/filepage.js`, `resources/fileviewer.css`,
  `resources/mediaopen.js`
- `includes/Media/FileOpenLink.php`, `includes/Hooks.php`
  (`wireEmbeddedMediaOpenLinks`), `includes/Spec/SpecialSourceFile.php`
- `tests/e2e/run_wiki_ux_e2e.mjs`
