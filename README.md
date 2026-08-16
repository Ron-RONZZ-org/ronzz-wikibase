# ronzz-wikibase

Customization project for **wikibase.ronzz.org** — the self-hosted Wikibase
(structured-data wiki) at ronzz.org. The v1 plan lives in
[**GitHub issue #6**](https://github.com/Ron-RONZZ-org/ronzz-wikibase/issues/6)
(fresh-instance bootstrap + EmbeddableContent + WikibaseCitation).

## Status (v1, issue #6)

| Deliverable | Status |
|---|---|
| D1 — vocabulary manifests + import scripts | implemented (`d1-vocabulary-manifests`) |
| D2 — seed orchestrator | implemented (`seed/`, on `v1-impl-d2-d6`) |
| D3 — EmbeddableContent end-to-end | implemented |
| D4 — WikibaseCitation (citeproc-php) | implemented |
| D5 — tests & acceptance (unit + E2E + XSS) | implemented |
| D6 — deployment + docs | code/docs done; **instance deployment pending** (wikibase-docker dev instance) |

## Repository layout

- `extensions/EmbeddableContent/` — D1 vocabulary manifests (`manifests/`) +
  `maintenance/importVocabulary.php`; D3 renderer, `Special:Embed`,
  `api.php?action=embed`, oEmbed, the three `Special:Add*` pages, entity-page
  gadget (copy embed / copy citation).
- `extensions/WikibaseCitation/` — D1 citation map manifests +
  `maintenance/importCitationMap.php`; D4 `api.php?action=citation` with
  **citeproc-php** (APA/Vancouver, vendored CSL styles in `styles/`) and native
  BibTeX/RIS serializers. No Node sidecar.
- `seed/` — D2 instance bootstrap orchestrator (`seed_instance.py`), API client,
  dogfood entities, config/report emission, self-verification.
- `tools/` — manifest generators (e.g. `generate_language_manifest.py`, which
  derives the language vocabulary from the installed Pygments lexers).
- `tests/` — PHPUnit unit tests (pure-PHP logic, no MediaWiki runtime) and the
  `tests/e2e/` acceptance + XSS suite (runs against a seeded live instance).

## Development

### Unit tests (no MediaWiki — pure-PHP logic only)

```bash
docker build -f Dockerfile.test -t ronzz-wikibase-test .
docker run --rm -v "$PWD":/app -w /app ronzz-wikibase-test vendor/bin/phpunit
python3 -m unittest discover -s seed/tests
```

### CI (recommended for the integration layer)

GitHub Actions runs **two jobs** on every push/PR (public repo → unlimited
minutes; the full stack runs on 16 GB runners, so it never touches your
machine):

- `unit` — PHPUnit + seed tooling (~2–3 min)
- `integration` — real wikibase-docker stack (MW 1.46 + WDQS): D1 importers →
  D2 seed → config-map restart → E2E acceptance → XSS suite

**Recommended loop** (especially on a resource-tight machine): edit → local
unit tests above → push → CI gate. For an on-demand full validation without
pushing, run the workflow manually:
[Actions → CI → Run workflow](https://github.com/Ron-RONZZ-org/ronzz-wikibase/actions)
(`workflow_dispatch`). For interactive stack debugging, see `dev/README.md`
(local stack, ~2.5 GiB RAM).

E2E acceptance + XSS suite (against a seeded instance):

```bash
python3 tests/e2e/run_e2e.py check --base-url https://wikibase.ronzz.org \
    --quote Q5 --code Q6 --math Q7 --instance-of P31 --quotation-class Q1
python3 tests/e2e/run_e2e.py xss --api-url ... --user 'User@bot' --password '***'
```

## Documentation

Instance documentation lives **in this repo** under `docs/`:

- `docs/README.md` — instance stack, endpoints, access control, skins
- `docs/contribution-guide.md` — editing rules for the instance (content editors)
- `docs/wikibase-cli.md` — server-side admin/CLI operations
- `docs/decisions/` — ADR-style decisions (`opaque-id.md`, `ontology-alignment.md`,
  `raw-rdf-in-blazegraph.md`)

Private ops/credentials (server config, OCI identity, `.env` paths — e.g.
`docs/IT/ronzz-linux-server-2.md` in Nextcloud) stay **out of this repo**.

## Conventions

- MediaWiki extension conventions: `extension.json`, i18n, PHPUnit +
  MediaWiki integration tests; **GPL-2.0-or-later** for extensions.
- Commit with [Conventional Commits](https://www.conventionalcommits.org/).
- See `AGENTS.md` in this repo for agent guidance.
