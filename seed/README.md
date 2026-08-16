# Seed orchestrator (D2)

One-time bootstrap of the fresh ronzz-wikibase instance (issue #6, §2, D2).

## What it does

1. Reads the D1 vocabulary manifests (`extensions/EmbeddableContent/manifests/`):
   properties, classes, and the Pygments-derived language items.
2. Creates the entities via the Wikibase API (`wbcreateproperty` /
   `wbcreateitem` / `wbeditentity`), skipping labels that already exist
   (idempotent, resume-safe).
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
7. Self-verifies: curls the embed surface, the citation API and SPARQL
   against the dogfood entities (doubles as the v1 acceptance harness).

## Usage

```bash
# Plan only (offline, no writes):
python3 -m seed.seed_instance --dry-run

# Real run (bot password recommended: 'Rongzhou@seed'):
python3 -m seed.seed_instance --user 'Rongzhou@seed' --password '***'

# Selective phases:
python3 -m seed.seed_instance --only=properties,classes,config --dry-run

# Self-verification only (read-only):
python3 -m seed.seed_instance --only=verify --user ... --password ...
```

Options: `--api-url` (default `https://wikibase.ronzz.org/api.php`),
`--base-url`, `--sparql-url`, `--manifests-dir`, `--lang` (primary label
language, default: first manifest language), `--config-out`, `--report-out`,
`--publish-report <page>`.

## Deployment

Include the generated fragment from `LocalSettings.php`:

```php
require_once __DIR__ . '/seed/generated/ronzz-wikibase.config.php';
```

Order matters: the vocabulary must be seeded **before** the extensions are
loaded with the config map, and before the dogfood-based acceptance checks
run. The nginx `/embed/` rewrite and the citation sidecar-free setup are
documented in `docs/` at deployment time (D6).

## Note on the removed Node sidecar

Issue #6 originally proposed a Node sidecar (`127.0.0.1:8181`) for citation
formatting. D4 now uses **citeproc-php** (in-process), so no sidecar URL is
emitted and no Node service is required. See the issue update for the diff.
