# AGENTS.md — ronzz-wikibase

This is the canonical, repo-wide instruction file for AI agents working on
**ronzz-wikibase** (the Wikibase customization for wikibase.ronzz.org).

## Hierarchical Context Model

Agents **must** follow this rule:

> When working inside a directory, load the nearest `AGENTS.md` file and merge it with parent `AGENTS.md` files up to root.
> Local rules override global rules.

Context resolution order (highest priority first):
1. `AGENTS.md` in module directories (e.g. `content-creation/AGENTS.md`) — module-specific context
2. `AGENTS.md` in current working directory (if present)
3. Root `AGENTS.md` — global project rules

---
## Project Overview

ronzz-wikibase is the customization and maintenance project for the
self-hosted Wikibase (structured-data wiki) at **wikibase.ronzz.org**. This
repo tracks the v1 plan and follow-up issues, holds the instance
documentation (`docs/`), and hosts the custom extension code
(EmbeddableContent, WikibaseCitation) plus the vendored third-party DPLforum
forum extension, and the seed tooling that bootstrapped the instance.

---

## Deployment

- **Production is deployed on `ronzz-linux-server-2`** (158.178.193.231),
  serving wikibase.ronzz.org. All server-wide operations — the OS, services
  (nginx, php-fpm, WDQS/Blazegraph + updater, MySQL), OCI identity, `.env`
  paths, credentials and maintenance runbooks — are documented in the
  **private ops doc**
  `wikibase.ronzz.org/wiki/RonzzIT:LinuxServer2` (gated wiki page).
- **Operational/server credentials never belong in this repo** — anything
  server-wide lives in that ops doc, never here. Instance-specific facts
  (stack, endpoints, access control, decisions) live in `docs/` in this
  repo.
- Never develop directly on the production server — extension work targets
  the dev/CI wikibase-docker stack (see Coding Guidelines #6 and
  `dev/AGENTS.md`).
- **Deployments follow the runbook sequence exactly** (`RonzzIT:Runbook/Wikibase`
  §Update): backups first (LocalSettings.php + extension/seed tarballs + the
  config map), then rsync + chown, D1 importers, seed re-emission with the
  FULL vocabulary set **including `dogfood`** (never `--only=config`), any
  LocalSettings namespace blocks (`php -l` after), restart php-fpm, purge
  objectcache + message blob store, and `rebuildtextindex.php` for custom
  namespaces (not searched by default). Verify each layer live afterwards
  (siteinfo namespaces, Special: pages 200, forms render the new fields).
- **Never re-rsync byte-identical content to the server.** When the deployed
  files already match the target state (e.g. synced from a branch/worktree
  before the merge, so the merge brings the same bytes into `main`), **skip
  the deploy** — fewer server ops, less downtime. Verify equality with a
  read-only checksum compare (`md5sum` both sides) instead of re-syncing;
  only rsync what actually differs.
- **Page↔item properties are eventually consistent on production** —
  `$wgJobRunRate = 0` + the 5-min cron means a freshly created classic page's
  `wikibase_item` property (and its infobox) fills in up to a few minutes
  after creation — expected, not a regression.

---

## Language and Naming Conventions

- Code, comments, commit messages and documentation in **English**.
- PHP follows MediaWiki coding conventions; Python follows PEP 8; on-wiki
  content follows the instance language policy (en/fr/eo — see
  `content-creation/AGENTS.md`).
- Entity IDs are opaque `[A-Z]\d+` (Q/P) — never invent slugs.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Production host | `ronzz-linux-server-2` (158.178.193.231) — server-wide ops doc: `RonzzIT:LinuxServer2` (gated wiki page) |
| Wiki platform | MediaWiki 1.46 + Wikibase (repo), self-hosted at wikibase.ronzz.org |
| Query service | WDQS (Blazegraph SPARQL 0.3.156) |
| Database | MySQL / MariaDB |
| Custom extensions | EmbeddableContent (D3 + issue #7), WikibaseCitation (D4) — standalone, never forks of Wikibase; DPLforum (vendored third-party forum, `extensions/DPLforum/` — see `VENDORED.md`); Diagrams (vendored third-party diagram extension — PlantUML/GraphViz/Mscgen server-side + Mermaid client-side, `extensions/Diagrams/` — see `VENDORED.md`) |
| Seed/tooling | Python 3 (stdlib only) |
| Unit tests | PHPUnit 10 (pure-PHP) + Python `unittest` |
| E2E | Python suites in `tests/e2e/` (curl the live endpoints) |
| Dev/CI stack | wikibase-docker (WBS), Docker Compose (`dev/docker-compose.ci.yml`) |
| CI | GitHub Actions (public repo, unlimited minutes) — `.github/workflows/ci.yml` |

## Dependency management

- **PHP**: dependencies in the root `composer.json` (dev-only: phpunit,
  wikibase/data-model, data-values, seboettg/citeproc-php). The
  WikibaseCitation extension installs its own `vendor/` (citeproc-php) inside
  the extension dir (gitignored). DPLforum and Diagrams are vendored
  third-party code with **no composer runtime dependencies** (DPLforum's
  `composer.json` is dev-only: codesniffer/lint tooling; Diagrams needs only
  `ext-libxml`, a PHP core extension).
- **Diagram renderers**: the Diagrams extension's server-side tags need local
  binaries — `graphviz` + `mscgen` via apt, and the **pinned PlantUML jar**
  (not the apt `plantuml` package — Ubuntu noble's 1.2020.2 predates PlantUML
  security profiles) installed by `tools/install-plantuml.sh` with the
  **SANDBOX** security profile. See `docs/decisions/diagrams.md`.
- **Python**: seed, tools and E2E suites use the **standard library only**
  (urllib, json, csv, argparse) — no pip dependencies.
- Vendored third-party frontend assets live in
  `extensions/EmbeddableContent/resources/` (KaTeX, highlight.js, CSL styles) —
  never re-fetch from CDNs at runtime.

## Coding Guidelines

1. **Standalone MediaWiki extensions, never forks of Wikibase** — deep
   integration via the stable Wikibase service API
   (`WikibaseRepo::getEntityLookup()`, `WikibaseRepoEntityTypes` hooks), like
   WikibaseMediaInfo / EntitySchema. See `docs/decisions/opaque-id.md` (fork
   maintenance rejected) and the citation acceptance criterion "no forked
   code".
2. **Opaque Q/P entity IDs** (no custom slugs; `[A-Z]\d+` contract).
3. **Ontology alignment as data** — mirror properties + equivalent-property
   statements; **no number-mirroring** of Wikidata P-numbers.
4. **Two-worlds**: entity-live data in Wikibase + raw RDF in the same
   Blazegraph store under its own URI namespace; raw data never uses
   `/entity/`.
5. **Upstreamable from day one**: `extension.json`, i18n (en/fr/eo), MediaWiki
   coding conventions, PHPUnit + MediaWiki integration tests,
   GPL-2.0-or-later.
6. Extension work targets a dev instance (wikibase-docker reference
   deployment) — **never develop directly on the production server**; CI's
   ephemeral stack is the sanctioned integration surface.
7. Content model: properties first, then items (house rule on the instance).

## Documentation Standards

- **Every module directory must have a corresponding `AGENTS.md`** (see the
  Module-Level table below).
- Instance documentation: ADRs + contribution pointers in `docs/`;
  deployment details (stack, endpoints, CLI ops) live on the gated wiki
  (`RonzzIT:Wikibase*`, moved 2026-08-20) — contribution guide, CLI ops,
  `decisions/` ADR-style decisions stay in the repo.
- On-wiki help lives on the instance itself (`Help:Contributing` family,
  multilingual) — see `content-creation/AGENTS.md`.
- Private ops/credentials stay out of this repo (see Project Overview).

---

## Commit Message Format

Use [Conventional Commits](https://www.conventionalcommits.org/):
- `feat:`, `fix:`, `docs:`, `chore:`, `test:`, `refactor:`
- Mention relevant GitHub issues (`#N`) in commit messages.
- One logical concern per commit.

---

## Testing Requirements

### Test Framework & Execution

| Aspect | Convention |
|--------|-----------|
| Framework | PHPUnit 10 (pure-PHP, no MediaWiki runtime) + Python `unittest` for seed tooling |
| Run all unit tests | `docker run --rm -v "$PWD":/app -w /app ronzz-wikibase-test vendor/bin/phpunit` (after `docker build -f Dockerfile.test -t ronzz-wikibase-test .`) |
| Seed unit tests | `python3 -m unittest discover -s seed/tests` |
| Seed dry-run (offline plan) | `python3 -m seed.seed_instance --dry-run` |
| E2E acceptance + XSS | `python3 tests/e2e/run_e2e.py check ...` / `python3 tests/e2e/run_e2e.py xss ...` (see `dev/README.md` for full flags) |
| Page-flow E2E (issue #7) | `python3 tests/e2e/run_pages_e2e.py --base-url ... --user SeedBot --password-file seed/.seedbot.pass` (self-cleaning) |
| CI | `gh workflow list` → `unit` job (fast) and `integration` job (full stack, 16 GB runners) |

### Testing Principles

1. **Test via the public API wherever possible** — the E2E suites curl the
   live endpoints (`api.php`, embed surfaces, citation API, SPARQL) against a
   seeded instance. Mock external services (fetch providers) only at system
   boundaries.
2. **Do not test directly via backend API alone** — the E2E suites verify the
   user-facing surfaces (3 embed surfaces, 5 citation styles, SPARQL, page
   flows on `Special:` pages).
3. **The XSS suite is mandatory for EmbeddableContent** — injections must not
   survive rendering (quote/code/math embed surfaces).
4. **Every bug fix must include a test that would have caught the regression**
   (e.g. the `Special:Embed` 500 and special-page-visibility fixes shipped
   with regression E2E).
5. **Console errors in browser tests indicate real bugs** — fix them even if
   tests pass.
6. **The pure-PHP unit suite cannot see MediaWiki-bound paths** — Special-page
   flows, entity saves and HTMLForm behavior are invisible to it (no MW
   runtime). Changes to the Add* flows or the page machinery must be validated
   by the dev-stack page-flow E2E or the CI integration job, not PHPUnit
   alone: a data-model API mismatch and an int-namespace title both passed the
   unit suite and only the dev-stack E2E caught them.
7. **Validate E2E helpers before blaming the extension** — the E2E suite is
   code under review: when an assertion fails, check the helper's response
   shape and regex capture first (a term-value dict compared to a string, and
   a title regex that dropped the namespace, both masqueraded as "feature
   broken" failures).
8. **Assert the expected outcome branch in E2E flows** — fallback branches in
   helpers silently mask regressions. A flow helper that resolved the created
   item "either from an Item: redirect or a classic page" let page-creation
   failures pass unnoticed; assert which branch was taken (e.g. the flow
   landed on a Person:/Source:/Collective: page).
9. **E2E environment prerequisites and external flakiness** — page-flow runs
   need the dev-stack's full setup (dev/README steps 0/1b: Cite, citation
   maps, the jobrunner container); missing pieces masquerade as code bugs.
   Flows hitting live external authorities are flaky — re-run before treating
   a timeout as a regression.
10. **Seed dogfood entities are E2E fixtures and production data** — keep them
    complete (the dogfood person must be classified `instance of` person, or
    author validation rejects it when parent-inference copies it). Seed phases
    are not independent: `--only=dogfood` alone leaves `class_ids` empty and
    silently no-ops guarded logic — run the dependency phases
    (properties/classes) when validating a phase in isolation.

### CI (GitHub Actions — public repo, unlimited minutes)

- **`unit` job** — pure-PHP PHPUnit (Dockerfile.test image) + seed tooling
  (unittest, `--dry-run`). Runs on every push/PR; ~2–3 min.
- **`integration` job** — full wikibase-docker stack (MW 1.46 + MariaDB +
  WDQS) on a 16 GB runner: D1 importers → D2 seed → wiki restart with the
  emitted config map → E2E acceptance (3 embed surfaces, 5 citation styles,
  SPARQL) → XSS suite → page-flow E2E (`run_pages_e2e.py`, issue-#7 Special
  pages) → forum E2E (`run_forum_e2e.py`, DPLforum: Forum: namespace +
  `<forum>` listing). Triggered on push/PR and via **`workflow_dispatch`**
  (on-demand full validation without pushing). Depends on `unit`.
- **Recommended loop on resource-tight machines** (this box: ~3.6 GiB free —
  the full stack needs ~2.5 GiB): edit → local unit tests → push → CI gate.
  Use `workflow_dispatch` for ad-hoc full validation; only run the stack
  locally (`dev/README.md`) when interactive debugging is needed.

### Known environment quirk (do not re-debug)

**WDQS updater quirk (0.3.156)**: on a *fresh* instance its backoff polling
can skip entities created while it is mid-catch-up. This is known, bounded
(catch-up only; steady-state production polling is unaffected), and
documented — see `dev/README.md`, issue #6, and the upstream ticket
(wmde/wikibase-suite#962). **Do not re-debug it.** The SPARQL acceptance
check runs as a warning in CI (`--allow-sparql-fail`) and is *fatal* in the
seed's self-verification, which is the production safety net for this quirk.

---

## What to Avoid

- Do not fork or patch Wikibase itself — extensions only.
- Do not add Node sidecars/services (the citation sidecar was removed; D4 is
  in-process citeproc-php).
- Do not add pip dependencies to seed/tools/E2E — stdlib only.
- Do not mirror Wikidata P-numbers; use equivalence statements instead.
- Do not develop directly on the production server.
- Do not put credentials, `.env` values or server secrets in this repo.

---

## Module-Level AGENTS Files

The following module-specific AGENTS files are located in their respective directories:

| Module | AGENTS File | Documentation |
|---|---|---|
| content-creation | `content-creation/AGENTS.md`, `content-creation/AGENTS-translation.md` (static LLM translation companion), `content-creation/glossary.md` | on-wiki `Help:Contributing` family (live pages; `docs/contribution-guide.md` is a pointer) |
| dev | `dev/AGENTS.md` | `dev/README.md` |
| docs | `docs/AGENTS.md` | `docs/README.md` (public-safe pointer); instance docs on gated wiki `RonzzIT:Wikibase*` |
| extensions | `extensions/AGENTS.md` | gated wiki `RonzzIT:Wikibase/Reference` (stack), `docs/decisions/` |
| seed | `seed/AGENTS.md` | `seed/README.md` |
| tests | `tests/AGENTS.md` | `tests/e2e/run_e2e.py`, `tests/e2e/run_pages_e2e.py` |
| tools | `tools/AGENTS.md` | gated wiki `RonzzIT:Wikibase/CLI` (pointer in `docs/wikibase-cli.md`) |

(Update this table as new modules are added)

---


## Dependency and Inheritance Map

```
Root AGENTS.md (global rules)
    │
    ├── content-creation/AGENTS.md  (wiki content via MCP — live pages, never local files)
    ├── dev/AGENTS.md               (dev/CI wikibase-docker stack)
    ├── docs/AGENTS.md              (instance documentation)
    ├── extensions/AGENTS.md        (EmbeddableContent + WikibaseCitation + vendored DPLforum + Diagrams)
    ├── seed/AGENTS.md              (instance bootstrap orchestrator)
    ├── tests/AGENTS.md             (PHPUnit unit + E2E/XSS/page-flow suites)
    └── tools/AGENTS.md             (manifest generators + fetch smoke test)
```

Local rules override global rules. Module-level files focus on domain-specific
behavior, constraints, and invariants.
