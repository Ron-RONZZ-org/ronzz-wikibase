# Diagrams (vendored)

Third-party MediaWiki extension, vendored as a plain file copy (the instance
deploy model — extensions are file copies rsynced from the repo, not git
checkouts).

| | |
|---|---|
| Upstream | https://github.com/samwilson/diagrams-extension (mediawiki.org: https://www.mediawiki.org/wiki/Extension:Diagrams) |
| Version | 1.0.0 |
| Vendored commit | `10cfd40b53c5` (master, 2026-03-15, tagged `1.0.0`) |
| License | GPL-3.0-or-later (`COPYING`; `extension.json` declares GPL-3.0-or-later, `composer.json` says MIT — the file headers carry the GPL notice) |
| MW requirement | `>= 1.40.0, <= 1.47` (instance runs 1.46) |
| PHP requirement | PHP 8.1+ (instance runs 8.3) |
| DB changes | none |
| Composer runtime deps | none (`ext-libxml` only, a PHP core extension) — plain file-copy install works |

Scope of the vendor: runtime files only (`includes/`, `maintenance/`, `i18n/`,
`resources/` incl. the bundled `mermaid.min.js`, `extension.json`,
`composer.json`, `COPYING`, `README.md`). Upstream dev tooling
(`package.json`, `package-lock.json`, `.eslintrc.json`, `.stylelintrc.json`,
`.phpcs.xml`, `.github/`, `CODE_OF_CONDUCT.md`, `.gitignore`) is
intentionally not vendored.

Diagram syntax is rendered **from wikitext** (no external tool round-trip, no
content leaves the server):

- `<uml>…</uml>` — PlantUML, **server-side** by the local `plantuml` binary
  (Debian/Ubuntu package; runs under Java), hardened with the PlantUML
  **SANDBOX security profile** (`env PLANTUML_SECURITY_PROFILE=SANDBOX
  plantuml` in `$wgDiagramsLocalCommands` — no local-file access, no URL
  fetching, set in `dev/config/Extensions.php` and the production
  LocalSettings block).
- `<graphviz>…</graphviz>` / `<mscgen>…</mscgen>` — GraphViz / Mscgen,
  **server-side** by the local `dot`/`neato`/… and `mscgen` binaries.
- `<mermaid>…</mermaid>` — Mermaid, **client-side** via the bundled
  `mermaid.min.js` (vendored in `resources/foreign/mermaid/`, never fetched
  from a CDN).

Server-side rendering runs through MediaWiki's shell sandbox
(`ShellCommandFactory::createBoxed()` with `disableNetwork()` + resource
limits — local boxed executor, no Shellbox service required) and the
generated SVG/PNG files are cached in the wiki's file store under
`images/diagrams/` (per-source-hash; re-rendered only when the source
changes). The `<mermaid>` tag requires JavaScript in the viewer's browser.

The classic Extension:PlantUML is archived upstream (2023) and does not
support MW 1.46 — this extension is its maintained replacement
(PlantUML + GraphViz + Mscgen + Mermaid in one).

See `docs/decisions/diagrams.md` (repo ADR) for the choice rationale and
`RonzzIT:Deployment/Wikibase` §Diagrams for the production config.
