# Decision: browser-native PDF viewer on File: pages with fullscreen

- **Status**: Accepted (Nov 2026)
- **Scope**: `File:` pages, `Special:SourceFile`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A `File:` page for a PDF rendered no preview at all (only the description and
licensing text). `Special:SourceFile` already embedded the PDF in a
same-origin iframe using the browser's built-in viewer.

## Decision

- **No third-party PDF library.** `File:` pages for `application/pdf` uploads
  get the same self-hosted iframe (the browser's native viewer).
- A small `pdfviewer.js` module adds a toolbar with **View full screen**
  (`requestFullscreen()` on the wrapper, `webkit` fallback) and **Open in a
  new tab**. It also wraps the existing `Special:SourceFile` preview.
- The viewer is inserted at the top of the File page content; internal-use
  files keep their existing anonymous-reader gate (no viewer).

## Consequences

- No assets/dependencies; the browser's viewer handles rendering.
- The fullscreen control is progressive enhancement: a browser without the
  Fullscreen API still shows the iframe and the new-tab link.

## References

- `resources/pdfviewer.js`, `resources/pdfviewer.css`
- `resources/filepage.js`
- `includes/Spec/SpecialSourceFile.php`
