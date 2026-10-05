# AGENTS.md — tests Agent Instructions

## Summary

Test suites for ronzz-wikibase: pure-PHP PHPUnit unit tests (`tests/Unit/`)
with no MediaWiki runtime, and Python E2E suites (`tests/e2e/`) that curl the
live endpoints of a seeded instance (acceptance, XSS, issue-#7 page flows),
plus the Playwright browser UX suite for the query GUI (query.ronzz.org).

## Purpose and Expected Behavior

- **`tests/Unit/`** — pure-PHP logic tests: renderers and the
  FragmentSanitizer (EmbeddableContent), manifest readers, citation
  converters/serializers (CitationFormatter, BibtexSerializer,
  RisSerializer, StatementToCslConverter, CitationMapReader), plus the
  `Fetch/` provider layer.
- **`tests/e2e/run_e2e.py`** — acceptance checks against a seeded instance:
  3 embed surfaces (quote/code/math), 5 citation styles, SPARQL
  (`--sparql-wait` retry, `--allow-sparql-fail` in CI); and the **XSS
  suite** (`xss` subcommand) — injections must not survive rendering. It also
  asserts **field-contract parity**: every field an `action=*-fields` endpoint
  advertises must be an accepted param of that flow's write module
  (`action=paraminfo`), so the "advertised but silently dropped" drift cannot
  recur.
- **`tests/e2e/run_pages_e2e.py`** — issue-#7 page flows:
  `Special:AddPerson` / `AddSource` / `AddCollective` + the AddQuotation
  form; **self-cleaning** (removes what it creates).
- **`tests/e2e/run_forum_e2e.py`** — DPLforum forum: `Forum:` namespace
  registration (NS 110/111) + a board's `<forum>` listing showing a created
  thread; **self-cleaning** (deletes its scratch board/thread pages).
- **`tests/e2e/run_diagrams_e2e.py`** — vendored Diagrams: siteinfo loads the
  extension; the `<uml>`/`<graphviz>`/`<mscgen>` server-side tags render
  cached SVG files under `/images/diagrams/` (HTTP 200); `<mermaid>` emits
  its container + hidden source + the bundled `ext.diagrams.mermaid` module;
  a broken GraphViz source and a PlantUML `!include /etc/passwd` probe each
  render a graceful error span (the latter proves the SANDBOX security
  profile is active); XSS payloads do not survive rendering;
  **self-cleaning**. Needs the diagram renderers in the wiki container (apt
  `graphviz`/`mscgen` + the pinned PlantUML jar via
  `tools/install-plantuml.sh` — see `dev/README.md` step 0b / ci.yml).
- **`tests/e2e/run_geogebra_e2e.py`** — the GeoGebra extension: `ggb` is an
  allowed upload extension, a `.ggb` uploads as `application/geogebra` (the
  MIME hooks), `[[File:name.ggb|600px]]` renders the sandboxed player iframe
  (class + player URL + file/size params + `sandbox`/`allow`/`loading`), the
  player origin serves the player page + the installed GeoGebra app
  (`GeoGebra/deployggb.js`), the wiki serves the `.ggb` with a CORS header for
  the player origin, and injections do not survive; **self-cleaning**. Needs
  the app bundle (`tools/install-geogebra.sh`) and the CORS step on the wiki
  (see ci.yml / `dev/README.md`). The browser render is verified by
  `tests/e2e/run_geogebra_ux_e2e.mjs` (Playwright, manual/production like the
  math UX suite).
- **`tests/e2e/run_codeblock_spaces_e2e.py`** — the CodeBlockSpaces
  extension: read-only (`action=parse` of inline `text=` payloads — no page,
  no login, safe against production) asserts that `<pre>`/`<code>` and
  SyntaxHighlight blocks carry plain spaces (no `&#160;`/`&nbsp;`) for
  `lang="text"`, `lang="vim"`, plain `<pre>`/`<code>` and the katex command,
  while prose keeps its French-space armoring (the fix is scoped to code).
  The reported clipboard flow is exercised end to end by
  `tests/e2e/run_codeblock_spaces_ux_e2e.mjs` (Playwright, manual/production
  like the wiki UX suite): it creates a scratch page with a
  `<syntaxhighlight lang="text" copy>` block, clicks the copy button and
  asserts the clipboard is byte-for-byte the command (no NBSP), then deletes
  the page.
- **`tests/e2e/run_wiki_ux_e2e.mjs`** — Playwright browser UX suite for the
  Sep-2026 UX batch B (manual/production, needs `--user` + `--password-file`
  for Special:Upload): the File: page copy buttons write `[[File:xxx]]` /
  the media URL to the clipboard; the AddSource picker and the AddCollective
  class select are alphabetical (the latter with human labels); the
  Special:Upload file picker is gated on the File radio, an empty
  Author/License warns (cancellable), and "upload another" opens the upload
  in a new tab. Zero tolerance for page/console errors.
- **`tests/e2e/run_query_gui_e2e.py`** — HTTP-level acceptance for the
  query.ronzz.org frontend stack (read-only): bare `wd:`/`wdt:` prefixes
  (the store's `prefixes.conf`) and explicit `PREFIX` clauses both return
  the instance's entity URIs; the `/sparql` read-only guard (`?update=` /
  `application/sparql-update` → 403, production proxy only);
  `wbsearchentities` CORS `*` for the GUI origin (the autocomplete API
  contract); the GUI's runtime config merge (`custom-config.json` values);
  the public `SPARQL examples` page parses anonymously (the Examples
  dialog); the Query Builder at `/querybuilder/`. Checks self-skip when the
  endpoint they need wasn't provided — production runs everything, CI runs
  the SPARQL + API parts against the dev stack.
- **`tests/e2e/run_query_gui_ux_e2e.mjs`** — Playwright browser UX suite for
  the query GUI (read-only, never writes): ctrl+space entity/property
  autocomplete (the regression test for the entity-autocomplete patch
  `dev/query-gui/patches/0002-entity-autocomplete.patch`), keyword/variable
  hints, run-a-query with result links carrying the instance's entity URIs,
  the Examples dialog and the Query Builder navbar link, zero page/console
  errors. **Run it whenever the query frontends are touched** (patch,
  `custom-config.json`, `query-builder.env.production`, the
  `frontends-deploy.yml` workflow): it is the frontends-deploy post-deploy
  gate (red = rollback from `/var/backups/wdqs-frontends`), and it runs
  against any base URL, so it can also smoke a local GUI build before
  pushing. Needs `playwright` installed where the script runs (npm scratch
  dir on CI, `~/node_modules` locally).
- The `integration` CI job runs the full E2E stack on 16 GB runners — see
  root AGENTS.md for the recommended edit → unit → push → CI loop.

## Constraints and Invariants

- **Python standard library only** in the E2E suites (urllib, json, argparse)
  — no pip dependencies. The one exception is the Playwright browser UX
  suite (`run_query_gui_ux_e2e.mjs`): browser behavior (ctrl+space
  autocomplete, click-to-run rendering) cannot be exercised with curl, so it
  uses the `playwright` npm package — pinned in the CI workflows that run it.
- **Playwright browser UX suites — local setup**: the `.mjs` suites use
  `playwright` **1.55.0** (the CI pin, `frontends-deploy.yml`). Playwright
  pins each release to an exact browser build, so install it once per machine
  with `npx playwright install chromium` (for 1.55.0 that is `chromium-1187` +
  `chromium_headless_shell-1187`; the shared `~/.cache/ms-playwright` also
  holds other revisions from other projects, which is why a bare launch can
  fail with "Executable doesn't exist … revision N"). ⚠️ Run the installer
  under a **supported Node (18/20/22)** — on an unsupported Node (e.g. 26)
  Playwright 1.55.0's streaming unzip **hangs after the download** (writes the
  first zip entry, then stalls); pointing `CHROME_PATH` at another revision's
  binary is a workaround, not a fix. Re-run the install if the pinned version
  ever changes.
- **Test via the public API wherever possible** — curl the live endpoints
  (`api.php`, embed surfaces, citation API, SPARQL). Mock external services
  (fetch providers) only at system boundaries.
- **Do not test directly via backend API alone** — verify the user-facing
  surfaces (embed pages, `Special:` pages, rendered output).
- **The XSS suite is mandatory for EmbeddableContent** — injections must not
  survive rendering on any embed surface.
- E2E suites run against a seeded instance and need IDs (dogfood entities,
  `instance of`, quotation class) — fed from `seed/generated/ids.json` in CI.

## Input/Output Expectations

- **Input**: PHPUnit config (`phpunit.xml.dist`), seeded instance URLs +
  entity IDs, bot credentials for write flows (page-flow E2E is
  self-cleaning).
- **Output**: pass/fail per suite; console errors in browser contexts
  indicate real bugs — fix them even if tests pass.

## Documentation Reference

- `tests/e2e/run_e2e.py` / `run_pages_e2e.py` / `run_forum_e2e.py` /
  `run_diagrams_e2e.py` — usage/help in the scripts
- `dev/README.md` — full E2E command lines against the local stack
- `.github/workflows/ci.yml` — `unit` job (PHPUnit + seed unittest +
  `--dry-run`) and `integration` job (full E2E stack)
- `composer.json` — dev-only PHPUnit 10 + Wikibase data-model deps
  (`Tests\` autoload-dev)

## Domain-Specific Rules for Agents

- **Every bug fix must include a test that would have caught the regression**
  (precedent: the `Special:Embed` 500 and special-page-visibility fixes
  shipped with regression E2E).
- Run unit tests locally only when they stay fast (<30 s); delegate the full
  stack to CI (`gh workflow run ci.yml`) on tight machines.
- When changing a `tests/Unit/` test, keep it pure-PHP — no MediaWiki runtime
  (Dockerfile.test image provides PHP + composer deps only).
- Keep the E2E suites stdlib-only and self-cleaning; never point them at
  production with write credentials unless the flow cleans up after itself.
