# Decision: interactive GeoGebra worksheets via `[[File:x.ggb]]`

- **Status**: Accepted (Sep 23 2026)
- **Scope**: `wikibase.ronzz.org` — upload a GeoGebra worksheet (`.ggb`) and
  embed it interactively in a classic wiki page with `[[File:name.ggb]]`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Editors asked to "integrate GeoGebra files in classic wiki pages: interactive
view", with the proposed design **regular upload/versioning via
Special:Upload + in-page embed via `[[File:xxx.ggb]]`, similar to images**,
and the caveat *"if a mature existing extension exists, use it directly"*.

The only mature GeoGebra MediaWiki extension is
[Extension:GeoGebra](https://www.mediawiki.org/wiki/Extension:GeoGebra)
(wikimedia Gerrit/GitHub, GPL-2.0+, in Wikimedia version control). It is a
**tag extension** that does **not** implement the proposed design:

- editors paste a `<ggb_applet width=… height=… ggbBase64="…"/>` tag, or the
  legacy `<ggb_applet filename="X.ggb"/>` (which base64-inlines the file at
  parse time);
- the documentation states plainly *"Upload of ggb files to the wiki is
  obsolete and not longer supported!"* — `ggbBase64` was introduced
  specifically to avoid uploads;
- it has **no `[[File:]]` support** (a parser hook, not a media handler);
- it loads the GeoGebra app from `cdn.geogebra.org` by default;
- it has been functionally frozen since 2018 (v3.0.9; recent commits are
  dependency/localisation bumps only).

So the caveat's condition — "a mature extension that does *this*" — is **not
met**. The faithful implementation of the request is a small standalone media
handler, modelled on Wikimedia's `Extension:3D` (REL1_46), the closest
precedent: a `type: media` extension that renders an interactive `[[File:]]`
viewer for a non-image format via a registered `MediaHandlers` entry.

Options evaluated:

| Option | Verdict |
|--------|---------|
| **A — vendor Extension:GeoGebra as-is** | Rejected as the primary path: tag-based, base64-inlined, upload documented as obsolete — delivers a *different* UX than requested. |
| **B — custom `ggb` media handler (`[[File:]]`)** | **Chosen.** Registers the `application/geogebra` MIME + a media handler; `[[File:x.ggb]]` renders an interactive applet. Upload/versioning via Special:Upload works like images. |
| **C — hybrid (vendor A + thin `[[File:]]` bridge)** | Rejected: two extensions, no benefit — the applet logic is ~60 lines. |

## Decision

1. **`extensions/GeoGebra/`** — a standalone house extension
   (GPL-2.0-or-later, MW ≥ 1.46, i18n en/fr/eo, PHPUnit + E2E), modelled on
   `Extension:3D`. It registers:
   - the `application/geogebra` MIME for `.ggb` (`MimeMagicInit` +
     `MimeMagicImproveFromExtension` — a `.ggb` is a renamed ZIP, so the
     content sniffer reports `application/zip` and the extension hook corrects
     it);
   - a media type (`DRAWING`) so `[[File:]]` embeds rather than links;
   - a `GeoGebraHandler` whose transform emits the player `<iframe>`.
2. **Self-hosted GeoGebra app, never a runtime CDN** (repo rule).
   `tools/install-geogebra.sh` fetches the **pinned** official *GeoGebra Math
   Apps Bundle* (`5.4.930.2`, sha256-checked, ~48 MB trimmed to
   `deployggb.js` + `HTML5/5.0/web3d/`) into the player directory (gitignored,
   like the MathJax tree / the PlantUML jar).
3. **Cookie-less player origin — `ggb.ronzz.org`** (option 2′). The applet
   runs inside a **sandboxed cross-origin `<iframe>`**; Same-Origin Policy
   isolates it from the wiki's DOM, cookies and session. The wiki serves the
   `.ggb` with `Access-Control-Allow-Origin` for the player origin (the
   cross-origin fetch the applet performs). `sandbox="allow-scripts
   allow-same-origin allow-popups allow-downloads allow-modals"` —
   `allow-same-origin` is safe because the framed origin **differs** from the
   parent (on a same-origin iframe it would be no sandbox at all).
4. **App-level script hardening** (Layer 1, always on): the player builds the
   applet with `disableJavaScript: true` (no JavaScript from material files)
   and `useBrowserForJS: true` (ignore `ggbOnInit` / object JS update scripts
   from the file). Both parameters are present in the pinned bundle
   (verified). GeoGebra *Script* (the app's own DSL) still runs — it is
   interpreted by the GeoGebra engine and cannot reach the DOM or the network.
5. **No upload-time sanitisation** (Layer 3) — redundant given 4, and
   brittle. Uploads remain trusted-user-only (registration closed, anon
   read-only).

## Security properties and residual risk

| Control | Protects against |
|---------|------------------|
| Trusted uploads only (existing) | the outer threat model |
| `disableJavaScript` + `useBrowserForJS` | JavaScript embedded in a `.ggb` (the common malicious-material vector) |
| Cookie-less cross-origin iframe | a GeoGebra app vulnerability reaching the wiki DOM/cookies/session |
| CSP `frame-ancestors` + `X-Robots-Tag` on the player vhost | the player being framed elsewhere / indexed |

Residual: the applet can still reach the wiki's **public** read endpoints
(no session) — acceptable; and the GeoGebra app itself is hosted on our
infrastructure, so a GeoGebra 0-day stays contained inside `ggb.ronzz.org`.

## Licensing

The **GeoGebra Math Apps Bundle is licensed for non-commercial use**
(https://www.geogebra.org/license; the bundle's own `README.txt`). The
instance is a personal/community, non-commercial wiki. The bundle is
**fetched from geogebra.org by `tools/install-geogebra.sh`, never
redistributed in this repo** (mirroring the MathJax/PlantUML asset pattern) —
see `extensions/GeoGebra/ASSETS.md`. The extension code itself is
GPL-2.0-or-later.

## Consequences

- Editors upload a `.ggb` via Special:Upload (versioning, history, the
  standard permission model) and write `[[File:name.ggb|600px]]`; the page
  renders a sandboxed interactive applet. `[[File:name.ggb|thumb|caption]]`
  works too.
- Rendering is client-side: a page view ships the player iframe, which loads
  the app (~48 MB, browser-cached on the player origin) and the `.ggb`. The
  server never rasterizes (no headless browser); the file's embedded
  `geogebra_thumbnail.png` is **not** extracted in v1 (documented follow-up).
- The wiki must serve `.ggb` with a CORS header for the player origin
  (production nginx rule; a CI step enables the equivalent on the WBS image's
  Apache).
- New infrastructure: the `ggb.ronzz.org` static vhost (DNS + TLS + nginx) —
  the first per-feature subdomain besides `query.ronzz.org`.
- CI exercises the full path: `ggb` allowed extension, `application/geogebra`
  MIME, the sandboxed iframe contract, the player origin serving the app, and
  the `.ggb` CORS header (`tests/e2e/run_geogebra_e2e.py`); a Playwright UX
  suite (`run_geogebra_ux_e2e.mjs`) verifies the applet renders in a browser
  (manual/production, like the math UX suite).

## References

- `extensions/GeoGebra/AGENTS.md` · `extensions/GeoGebra/ASSETS.md`
- `tools/install-geogebra.sh` · `dev/config/Extensions.php` · `dev/docker-compose.ci.yml`
- `tests/e2e/run_geogebra_e2e.py` · `tests/e2e/run_geogebra_ux_e2e.mjs` · `tests/Unit/GeoGebraEmbedTest.php`
- mediawiki.org: Extension:GeoGebra (tag-based, upload deprecated) ·
  Extension:3D (the `[[File:]]` media-handler precedent)
- GeoGebra: Apps Embedding · App Parameters (`disableJavaScript`,
  `useBrowserForJS`) · File Format (`.ggb` is a ZIP)
