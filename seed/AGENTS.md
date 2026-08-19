# AGENTS.md — seed Agent Instructions

## Summary

Instance bootstrap orchestrator (D2) for ronzz-wikibase: one-time seeding of
the fresh instance — vocabulary entities from the D1 manifests, alignment
statements, dogfood entities, the LocalSettings config map, and
self-verification (the production safety net).

## Purpose and Expected Behavior

`python3 -m seed.seed_instance` performs, in phases (selectable via
`--only=`):

1. Reads the D1 vocabulary manifests
   (`extensions/EmbeddableContent/manifests/`): properties, classes, and the
   Pygments-derived language items.
2. Creates the entities via the Wikibase API (`wbcreateproperty` /
   `wbcreateitem` / `wbeditentity`), skipping labels that already exist
   (**idempotent, resume-safe**).
3. Adds alignment statements (`equivalent property` / `equivalent class`) and
   classifies the language items (`instance of → programming language`).
4. Creates the dogfood entities: one quotation, one code snippet, one math
   item, one person (Ada Lovelace), one book.
5. Emits the LocalSettings fragment (`seed/generated/ronzz-wikibase.config.php`)
   — `$wgEmbeddableContentConfig` (the D3/D4 config map) plus the Wikibase
   settings (`string-limits` 50 KB, `wellKnownReferencePropertyIds`,
   `sandboxEntityIds`, data-rights URLs).
6. Generates the seed report (wikitext) and optionally publishes it as a
   `MediaWiki:` page.
7. **Self-verifies**: curls the embed surface, the citation API and SPARQL
   against the dogfood entities (doubles as the v1 acceptance harness).

## Constraints and Invariants

- **Python standard library only** (urllib, json, csv, argparse,
  http.cookiejar) — no pip dependencies.
- Idempotency: exact-label skip — re-running must not duplicate entities.
- The SPARQL self-verification is **fatal** (unlike CI's
  `--allow-sparql-fail` warning) — it is the production safety net for the
  WDQS 0.3.156 fresh-instance updater quirk (do not re-debug, see root
  AGENTS.md).
- Generated output (`seed/generated/`, `seed/tests/out/`) is gitignored.
- Credentials are passed as CLI args / bot password files — never committed.

## Input/Output Expectations

- **Input**: manifests dir (default
  `extensions/EmbeddableContent/manifests/`), API/base/SPARQL URLs (defaults
  point at production `https://wikibase.ronzz.org`), `--user`/`--password`.
- **Output**: seeded entities, `--config-out` fragment (PHP), `--ids-out`
  (JSON id map for E2E), `--report-out` (wikitext), optional published
  report page.
- **Modes**: `--dry-run` (offline plan, no writes), `--only=verify`
  (read-only self-verification), selective `--only=properties,classes,...`.

## Documentation Reference

- `seed/README.md` — full usage, deployment (require_once of the fragment),
  options
- `seed/tests/test_seed.py` — unit tests (`python3 -m unittest discover -s seed/tests`)
- `dev/README.md` — how CI drives the seed against the dev stack
- `.github/workflows/ci.yml` — seed phase in the `integration` job

## Domain-Specific Rules for Agents

- Run `--dry-run` first for any manifest change — it is the offline plan.
- Order matters: vocabulary must be seeded **before** the extensions are
  loaded with the config map, and before the dogfood-based acceptance checks
  run.
- The D1 importers (`importVocabulary.php`) and the seed both read the
  manifests — a manifest change may need both paths updated; keep the
  exact-label skip contract intact.
- Entity IDs are opaque `[A-Z]\d+` — the seed never invents slugs.
