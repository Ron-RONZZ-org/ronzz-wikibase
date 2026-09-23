# AGENTS.md — GeoGebra extension

## Summary

Standalone MediaWiki extension that renders **interactive GeoGebra
worksheets** (`[[File:name.ggb]]`) on classic wiki pages. A `.ggb` is a
renamed ZIP (containing `geogebra.xml`); the extension registers the
`application/geogebra` MIME + a media handler so `[[File:name.ggb|600px]]`
embeds an applet instead of a file link. Upload/versioning use the standard
`Special:Upload` + `File:` machinery.

Design rationale: `../../docs/decisions/geogebra.md`. App-bundle provenance +
licence: [`ASSETS.md`](ASSETS.md).

## How it works

1. **MIME** (`Hooks`): `MimeMagicInit` teaches the analyzer
   `application/geogebra` ↔ `ggb` and maps the MIME to media type `DRAWING`;
   `MimeMagicImproveFromExtension` rewrites the ZIP-detected `application/zip`
   back to `application/geogebra` for `.ggb` uploads. `$wgFileExtensions[] =
   'ggb'` + `$wgTrustedMediaFormats[] = 'application/geogebra'` are set in the
   instance config (extension.json cannot append to those arrays).
2. **Handler** (`GeoGebraHandler`, an `ImageHandler`): `canRender()` is always
   true (a `.ggb` has no intrinsic pixel size); `normaliseParams()` applies the
   configured default size / aspect ratio; `doTransform()` returns
   `GeoGebraOutput`; `parserTransformHook()` adds `ext.geogebra.styles`.
3. **Output** (`GeoGebraOutput`, a `MediaTransformOutput`): `toHtml()` emits a
   **sandboxed `<iframe>`** whose `src` is `$wgGeoGebraPlayerUrl` carrying the
   file URL + size + app. With no player URL configured it degrades to a plain
   file link.
4. **Player** (`resources/player/`, served from the cookie-less origin): loads
   the self-hosted app (`GeoGebra/deployggb.js`) and the file, building the
   applet with `disableJavaScript: true` + `useBrowserForJS: true` (Layer 1
   hardening).

## Constraints and Invariants

- **Never commit the GeoGebra app bundle** — it is non-commercial-licensed and
  installed per environment by `tools/install-geogebra.sh` (gitignored). See
  `ASSETS.md`.
- **The player origin must differ from the wiki origin.** The iframe sandbox
  includes `allow-same-origin`, which is only a sandbox across origins; on a
  same-origin iframe it would be no isolation at all.
- **The wiki must serve `.ggb` with CORS for the player origin** — the applet
  fetches the file cross-origin. Production: an nginx rule; CI: the
  `Enable CORS for .ggb` step.
- **Layer 1 hardening is not optional** — `disableJavaScript: true` +
  `useBrowserForJS: true` must stay in the applet config.
- i18n ships en/fr/eo (+ qqq).
- No DB, seed, manifest or config-map surface — a deploy is an rsync + the
  install script + `wfLoadExtension` + a php-fpm restart/cache purge.

## Input/Output Expectations

- **Input**: an uploaded `.ggb`; `[[File:…]]` options (width/height).
- **Output**: a sandboxed player `<iframe>` (HTML).
- **Unit-test surface**: `tests/Unit/GeoGebraEmbedTest.php` covers the pure
  `GeoGebraEmbed` builders. The MW-bound paths (MIME hooks, handler, rendered
  iframe) are covered by `tests/e2e/run_geogebra_e2e.py` (dev-stack CI + live)
  and the browser render by `tests/e2e/run_geogebra_ux_e2e.mjs`.

## Documentation Reference

- `../../docs/decisions/geogebra.md` — the design decision + security model
- `ASSETS.md` — the app-bundle provenance/licence
- On-wiki: `Help:Contributing/richMediaContent`
- `RonzzIT:Deployment/Wikibase` + `RonzzIT:Runbook/Wikibase` — instance ops

## Domain-Specific Rules for Agents

- Do not add a server-side rasterizer (headless browser) — rendering is
  client-side by design; the `.ggb`'s embedded `geogebra_thumbnail.png`
  extraction is the documented follow-up for a File-page preview.
- Keep the player origin a **separate origin** — never "simplify" it to a
  same-origin iframe or an inline applet (that removes the sandbox).
- Update `tools/install-geogebra.sh` (version + three sha256 values) and
  `ASSETS.md` together when bumping the bundle.
