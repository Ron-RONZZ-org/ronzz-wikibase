# ADR — raw GeoGebra commands via the `<geogebra>` tag

- **Status**: accepted (2026-10-14). Owner: ronzz.org.
- **Extends**: `geogebra.md` (the `[[File:x.ggb]]` media handler).

## Context

Editors asked whether **raw GeoGebra commands** could be parsed and rendered
directly, without creating a `.ggb` locally and uploading it. A `.ggb` is a
renamed ZIP; producing one from plain commands needs a GeoGebra engine, which
the wiki does not have server-side.

The player already runs the self-hosted GeoGebra app inside a sandboxed,
cookie-less cross-origin iframe (`geogebra.md`), and the app exposes
`ggbApplet.evalCommand(cmd)` — so the commands can be evaluated **client-side**
by the trusted, sandboxed player instead of being pre-compiled to a `.ggb`.

## Decision

1. A new **`<geogebra>` parser tag** takes the raw GeoGebra commands (one per
   line) and optional `width` / `height` / `app` attributes, and emits the
   same sandboxed player iframe contract (`GeoGebraEmbed::commandIframeAttributes`,
   class `ggb-embed`, the shared `sandbox`/`allow`/`loading`/`referrerpolicy`
   attributes). The commands ride the `commands` query parameter
   (`rawurlencode`d by `http_build_query`; UTF-8 safe).
2. The **player** (`resources/player/player.js`) accepts either `file`
   (the media-handler mode) or `commands`. In command mode it builds the
   applet without a `filename` and, in `appletOnLoad`, splits the commands on
   newlines and runs each non-empty line through `api.evalCommand()`.
3. **Security is unchanged.** The iframe stays a cross-origin sandbox; the
   Layer-1 hardening (`disableJavaScript: true` + `useBrowserForJS: true`)
   stays on, so a crafted command set cannot run page JavaScript or reach the
   wiki DOM/cookies/session — exactly the `.ggb` threat model (`geogebra.md`
   §"Security properties"). Commands are only editable by trusted users
   (registration closed, anon read-only), matching the trusted-upload gate.
4. **Configuration**: `$wgGeoGebraEnableCommands` (default `true`) and
   `$wgGeoGebraCommandMaxLength` (default `20000` bytes; `0` = unlimited — an
   over-long tag renders an error notice, never a broken iframe). The tag also
   renders an error notice when the player URL is unset or the input is empty.

## Alternatives considered

| Option | Verdict |
|--------|---------|
| **`ggbBase64`** (the legacy Extension:GeoGebra approach) | Rejected: it takes a base64-encoded whole `.ggb` (ZIP), so the editor still has to build a `.ggb` — it does not accept raw commands, which is the point of the request. |
| **Server-side commands → `.ggb`** | Rejected: needs a GeoGebra engine in PHP; the extension has no server-side rasterizer/engine by design (`geogebra.md`). |
| **Inline `<div>` + a same-origin applet** | Rejected: removes the origin isolation (`geogebra.md` invariant). |
| **`<geogebra>` tag** | **Chosen** — matches the request's notation, carries attributes naturally, and reuses the existing sandboxed player unchanged except for the command branch. |

## Consequences

- Editors can write an interactive worksheet with plain commands and no upload:
  ```wikitext
  <geogebra width=640>
  A = (1, 2)
  f(x) = x^2
  </geogebra>
  ```
- Rendering, asset installation, the `ggb.ronzz.org` origin and the CORS rule
  are all shared with the file mode — no new infrastructure.
- **Deploy**: extension rsync + php-fpm restart + a message/objectcache purge
  (new i18n keys). No DB/seed/manifest change.

## References

- `extensions/GeoGebra/src/Hooks.php` (`onParserFirstCallInit` + `renderGeoGebra`),
  `src/GeoGebraEmbed.php` (`commandPlayerSrc`/`commandIframeAttributes`),
  `resources/player/player.js`
- `geogebra.md` — the media handler, the player-origin sandbox and the
  Layer-1 hardening
- `tests/e2e/run_geogebra_e2e.py`, `tests/Unit/GeoGebraEmbedTest.php`
- GeoGebra Apps Embedding API (`evalCommand`, `appletOnLoad`)
