# AGENTS.md — tests Agent Instructions

## Summary

Test suites for ronzz-wikibase: pure-PHP PHPUnit unit tests (`tests/Unit/`)
with no MediaWiki runtime, and Python E2E suites (`tests/e2e/`) that curl the
live endpoints of a seeded instance (acceptance, XSS, issue-#7 page flows).

## Purpose and Expected Behavior

- **`tests/Unit/`** — pure-PHP logic tests: renderers and the
  FragmentSanitizer (EmbeddableContent), manifest readers, citation
  converters/serializers (CitationFormatter, BibtexSerializer,
  RisSerializer, StatementToCslConverter, CitationMapReader), plus the
  `Fetch/` provider layer.
- **`tests/e2e/run_e2e.py`** — acceptance checks against a seeded instance:
  3 embed surfaces (quote/code/math), 5 citation styles, SPARQL
  (`--sparql-wait` retry, `--allow-sparql-fail` in CI); and the **XSS
  suite** (`xss` subcommand) — injections must not survive rendering.
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
- The `integration` CI job runs the full E2E stack on 16 GB runners — see
  root AGENTS.md for the recommended edit → unit → push → CI loop.

## Constraints and Invariants

- **Python standard library only** in the E2E suites (urllib, json, argparse)
  — no pip dependencies.
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
