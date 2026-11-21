# Decision: pre-upload image editor (TOAST UI) on the upload surfaces

- **Status**: Accepted (Nov 2026)
- **Scope**: `Special:Upload`, Add\*/Update\* portrait/logo/image uploads, the
  Add\* multi-image dialog
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

There was no way to rotate, crop or colour-correct an image before upload.
The existing pre-upload tools (`resources/uploadimage.js`) already preview,
downscale and auto-correct the extension client-side; an editor had to fit
that model without a Node sidecar or a CDN.

Two FOSS candidates were evaluated:

| | Cropper.js 2.3.1 | Toast UI Image Editor 3.15.3 |
|---|---|---|
| Min JS | 50.9 KB (15 KB gzip) | 672.5 KB (179 KB gzip) + colour-picker 35.1 KB (10 KB gzip) |
| CSS | none | 39.9 KB (5.8 KB gzip) + 3.6 KB (1.7 KB gzip) |
| Icons | none | 144.9 KB SVG (not needed: the CSS uses data URIs) |
| Features | crop/rotate/flip/zoom/export | full editor UI + filter sliders + drawing/text/shapes/mask/undo |
| Telemetry | none | one Google Analytics event on first use unless disabled |

Cropper is far smaller, but it is geometry-only: the requested colour
filtering would need a custom slider UI anyway. Toast UI ships the complete
toolbar/filter UI and is MIT-licensed; its telemetry is disabled and the
bundle is vendored, so it was chosen.

## Decision

1. **Vendor** `tui-image-editor` 3.15.3 + `tui-color-picker` 2.2.8 (JS/CSS +
   licences) under `resources/tui-image-editor/`; no CDN. SHA-256 and the
   refresh procedure are in `VENDORED.md`.
2. **Disable the Google Analytics ping** with `resources/tui-telemetry-off.js`
   (loaded first) and `usageStatistics: false` on the constructor.
3. **Load on demand** (`mw.loader.using('ext.embeddableContent.imageeditor')`)
   only when the user clicks **Edit image**; a normal upload pays nothing for
   the ~750 KB bundle.
4. **Raster only**: JPEG/PNG/WebP. SVG and animated GIF are left untouched (the
   editor would rasterise the SVG and destroy the GIF animation). WebP is
   exported as PNG for canvas-encoding compatibility; the existing
   auto-extension step renames the destination.
5. **Editor UX**: an OOUI `ProcessDialog` with Crop / Flip / Rotate / Filter
   menus; Apply exports via `editor.toDataURL()` into a new `File`, which
   replaces the file input's file and re-runs the existing preview/resize/
   auto-extension pipeline. Cancel returns the original file. The dialog uses
   the wiki's Codex theme tokens and an inverted icon set in night mode.
6. **Surfaces**: `uploadimage.js` (Special:Upload + Add\*/Update\* portrait/
   logo) adds the trigger under the preview; `mediaeditor.js` (the Add\*
   multi-image dialog) adds it next to its file input.

## Consequences

- Contributors can rotate/crop/filter before upload on every image surface.
- The large editor bundle is fetched only on demand and self-hosted.
- SVG/GIF are deliberately not editable; the trigger is absent for them
  (mediaeditor shows a short unsupported-format hint).
- The vendored bundle is ~750 KB on disk; the ResourceLoader module is not
  added to any page by default.
- Tests: module-source + asset reachability in `run_pages_e2e.py`; the
  existing upload UX suite covers the preview/resize paths the edited file
  re-enters. `ThemeTokensTest` audits `imageeditor.css`.

## References

- `resources/imageeditor.js`, `resources/imageeditor.css`,
  `resources/tui-telemetry-off.js`, `resources/tui-image-editor/`
- `resources/uploadimage.js`, `resources/mediaeditor.js`
- `extensions/AGENTS.md` upload-tools section
