# AGENTS.md — ronzz-wikibase

## Scope

This repo tracks the v1 plan (GitHub issue #6) and follow-up issues for the
Wikibase customization at ronzz.org (wikibase.ronzz.org), holds the instance
documentation (`docs/`), and will hold the extension code once implementation
starts. **Operational/server credentials
never belong in this repo** — they live in the private Nextcloud docs
(`docs/IT/ronzz-linux-server-2.md`); everything else about the instance is in
`docs/` here.

## Key constraints (already decided — do not relitigate)

- **Standalone MediaWiki extensions, never forks of Wikibase.** See
  `decisions/opaque-id.md` (fork maintenance rejected) and citation acceptance
  criterion "no forked code". Deep integration via the stable Wikibase service API
  (`WikibaseRepo::getEntityLookup()`, `WikibaseRepoEntityTypes` hooks), like
  WikibaseMediaInfo / EntitySchema.
- **Opaque Q/P entity IDs** (no custom slugs; `[A-Z]\d+` contract).
- **Ontology alignment as data** (mirror properties + equivalent-property
  statements; **no number-mirroring** of Wikidata P-numbers).
- **Two-worlds**: entity-live data in Wikibase + raw RDF in the same Blazegraph
  store under its own URI namespace; raw data never uses `/entity/`.
- Upstreamable from day one: `extension.json`, i18n, MediaWiki coding conventions,
  PHPUnit + MediaWiki integration tests, GPL-2.0-or-later.

## Workflow

1. The v1 plan is GitHub issue #6 (umbrella — supersedes the earlier #1–#5, now
   closed). Discuss in the issue before coding.
2. Extension work targets a dev instance (wikibase-docker reference deployment) —
   never develop directly on the production server.
3. Content model: properties first, then items (house rule on the instance).
4. Test layers: PHPUnit unit + MediaWiki integration + E2E (curl the endpoints:
   `tests/e2e/run_e2e.py` acceptance/XSS, `tests/e2e/run_pages_e2e.py` for the
   issue-#7 page flows); XSS suite is mandatory for EmbeddableContent.

## CI (GitHub Actions — public repo, unlimited minutes)

- **`unit` job** — pure-PHP PHPUnit (Dockerfile.test image) + seed tooling
  (unittest, `--dry-run`). Runs on every push/PR; ~2–3 min.
- **`integration` job** — full wikibase-docker stack (MW 1.46 + MariaDB + WDQS)
  on a 16 GB runner: D1 importers → D2 seed → wiki restart with the emitted
  config map → E2E acceptance (3 embed surfaces, 5 citation styles, SPARQL) →
  XSS suite → page-flow E2E (`run_pages_e2e.py`, issue-#7 Special pages).
  Triggered on push/PR and via **`workflow_dispatch`** (on-demand full
  validation without pushing). Depends on `unit`.
- **Recommended loop on resource-tight machines** (this box: ~3.6 GiB free —
  the full stack needs ~2.5 GiB): edit → local unit tests
  (`docker run --rm -v "$PWD":/app -w /app ronzz-wikibase-test vendor/bin/phpunit`)
  → push → CI gate. Use `workflow_dispatch` for ad-hoc full validation; only
  run the stack locally (`dev/README.md`) when interactive debugging is needed.
- Never develop directly on the production server (see Workflow #2) — CI's
  ephemeral stack is the sanctioned integration surface.

## Reference

- Overall plan: GitHub issue #6 (supersedes #1–#5)
- instance docs: `docs/` in this repo.
- MediaWiki/Wikibase docs: mediawiki.org, wikibase-docker (github.com/wmde/wikibase-docker).
- Dev/CI stack: `dev/README.md`; workflows: `.github/workflows/ci.yml`.
