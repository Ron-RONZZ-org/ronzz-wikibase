# Decision: Integrated diagram rendering via Extension:Diagrams (vendored)

- **Status**: Accepted (Aug 24 2026)
- **Scope**: `wikibase.ronzz.org` — render diagrams in wiki pages from inline
  text (PlantUML / GraphViz / Mscgen / Mermaid), no external tool round-trip
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Editors asked for **integrated diagram rendering** on RonzzWikiBase — the
diagram source must live in the wiki page text and render automatically on
page view, so that editing a diagram never requires leaving the wiki and
re-exporting an image from external software.

The classic **Extension:PlantUML is archived** (Sept 2023, mediawiki.org
archived-extension category): unmaintained and unsupported on MediaWiki 1.46.
MediaWiki's own documentation points to Mermaid and the newer diagram
extensions as replacements. The viable options were evaluated:

| Option | Verdict |
|--------|---------|
| **Extension:Diagrams** (samwilson, stable v1.0.0, MW 1.40–1.47, GPL-3.0-or-later) | **Chosen.** One extension replacing the archived PlantUML + GraphViz + Mermaid extensions: `<uml>` (PlantUML), `<graphviz>` (dot/neato/fdp/sfdp/circo/twopi/osage), `<mscgen>` rendered **server-side** by local binaries into SVG/PNG cached in the wiki's own file store (`images/diagrams/`, per-source-hash); `<mermaid>` rendered **client-side** by the bundled mermaid.js (vendored, never a CDN). Zero runtime composer deps (`ext-libxml` only) → plain file-copy install like DPLforum. Renders through MediaWiki's shell sandbox (`ShellCommandFactory::createBoxed()`, falls back to a local boxed executor with CPU/walltime/memory limits on stock MW 1.46 — no Shellbox service). |
| **Extension:Mermaid** (SemanticMediaWiki, stable v6.0.2) | Mermaid-only syntax (no PlantUML), client-side JS only → blank in print/static export; the repo's curl-based E2E suite cannot verify the rendered SVG (would need Playwright machinery). Rejected for the full requirement; its functionality is already inside Extension:Diagrams' `<mermaid>` tag. |
| **Extension:Kroki** | One Java service covering 30+ diagram types, but a new always-on rendering service on production + dev/CI (a server-side component per diagram render). More moving parts than local binaries for the same result. |
| **External rendering services** (plantuml.com, kroki.io, a self-hosted diagrams-service) | Rejected: the external services would receive the diagram source (content leak on a gated instance); a self-hosted service adds a permanent component for no benefit over local binaries. |
| **Render externally, paste image** | Explicitly rejected by the user (edit round-trip back to external software). |

## Decision

1. **Vendor Extension:Diagrams v1.0.0** as a plain file copy at
   `extensions/Diagrams/` (instance deploy model — rsynced file copies, not
   git checkouts). Upstream commit pinned: `10cfd40b53c5` (master, 2026-03-15,
   tag `1.0.0`; provenance in `extensions/Diagrams/VENDORED.md`). Runtime
   files only. Upstream license GPL-3.0-or-later — vendored as-is, never
   modified (upgrade = re-vendor).
2. **Rendering model**:
   - `<uml>`, `<graphviz>`, `<mscgen>` — **server-side** by local binaries;
     output SVG/PNG **cached in the wiki file store** under
     `images/diagrams/` (per-source-hash md5 — re-rendered only when the
     source changes; served through the existing `/images/` nginx route, no
     nginx change). `$wgDiagramsDefaultFormat = 'svg'` (crisp/small; `png`
     selectable per tag with `format="png"`).
   - `<mermaid>` — **client-side** via the bundled `mermaid.min.js`
     (vendored in `resources/foreign/mermaid/`, ~1.1 MB — the repo's
     no-CDN rule).
3. **Server-side binary provenance** (production + CI + dev stack must agree):
   - `graphviz` + `mscgen` — **apt packages** (the WBS image / production are
     Debian-based; `dot` is also what PlantUML uses internally for
     non-sequence diagrams).
   - `plantuml` — **pinned jar, NOT the apt package**: Ubuntu 24.04 (noble)
     ships `plantuml` 1.2020.2, which **predates PlantUML's security
     profiles** (introduced 1.2020.11). The wiki runs PlantUML under the
     **SANDBOX profile** (no local-file access, no URL fetching) because the
     diagram text is arbitrary editor input — an old jar silently ignores the
     profile. `tools/install-plantuml.sh` installs the pinned jar
     (`1.2026.6`, sha256-verified) + a `/usr/local/bin/plantuml` wrapper that
     exports `PLANTUML_SECURITY_PROFILE=SANDBOX`; the MediaWiki config line
     carries the same env prefix as belt-and-braces.
4. **Config** (production LocalSettings.php **and** `dev/config/Extensions.php`
   — CI parity rule): `wfLoadExtension('Diagrams');` +
   `$wgDiagramsDefaultFormat = 'svg';` +
   `$wgDiagramsLocalCommands['plantuml'] = 'env PLANTUML_SECURITY_PROFILE=SANDBOX /usr/local/bin/plantuml';`.
   No new namespaces, no DB changes, no `update.php`.
5. **CI/dev parity**: the compose stack bind-mounts `extensions/Diagrams`
   read-only; the integration job installs the binaries (apt + the pinned
   jar) and runs a dedicated diagrams E2E (`tests/e2e/run_diagrams_e2e.py`,
   self-cleaning) asserting: siteinfo loads Diagrams, the three server-side
   tags render cached SVG files (HTTP 200), the mermaid container + bundled
   module appear, a broken GraphViz source and a PlantUML `!include
   /etc/passwd` probe each render a **graceful error span** (proving the
   SANDBOX profile is active), and XSS payloads do not survive rendering.
6. **Sandboxing**: server-side commands run through MediaWiki's
   `createBoxed()` → local boxed executor (CPU/walltime/memory limits from
   `$wgShell*`); `disableNetwork()` is requested by the extension. PlantUML
   additionally runs under its own SANDBOX profile. Editors are the closed
   user base (anon read-only, registration closed), so this is
   defense-in-depth, not a trust boundary.
7. **On-wiki content** (post-deploy): `Help:Contributing/diagrams` subpage
   (en + static fr/eo copies per the static-translation convention) with the
   tag syntax, the `type`/`format`/`renderer` attributes, the SANDBOX
   caveat (`!include` of local files/URLs is disabled; the bundled PlantUML
   stdlib like `!include <C4/C4_Context>` works), and the mermaid
   client-side/JS caveat.

## Consequences

- Diagram sources are wiki text: watchlists, history, diffs, search and the
  existing permission model apply; no external tool round-trip, no content
  leaves the server.
- Server-side tags render in print/static export and are fully verifiable
  with the repo's curl-based E2E/XSS suites; `<mermaid>` needs JS in the
  viewer's browser (documented on the help page).
- Rendered files are cached per source-hash in the wiki file store — no
  per-view re-render cost; disk grows with distinct diagrams (cleanup via
  the vendored `maintenance/deleteOldDiagramsFiles.php`, default 30-day TTL).
- New server footprint: `graphviz` + `mscgen` apt packages + a pinned
  ~29 MB jar + a JRE (production already runs Java for WDQS/Blazegraph).
- The `<mermaid>` tag bundles 1.1 MB of vendored JS (RL-compressed on load).
- Extension i18n is en/nl/qqq (upstream) — the diagram tags have no
  user-facing chrome, so fr/eo editors see the help pages, not extension UI.
- Upstream is actively maintained (v1.0.0, Mar 2026; "master maintains
  backward compatibility"); the vendored commit is pinned and the surface is
  small, so future incompatibilities are cheap to re-vendor or patch.
