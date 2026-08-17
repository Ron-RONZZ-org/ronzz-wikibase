# ronzz-wikibase

Customization project for **wikibase.ronzz.org** — the self-hosted Wikibase
(structured-data wiki) at ronzz.org. The v1 plan lives in
[**GitHub issue #6**](https://github.com/Ron-RONZZ-org/ronzz-wikibase/issues/6)
(fresh-instance bootstrap + EmbeddableContent + WikibaseCitation).

## Status

| Deliverable | Status |
|---|---|
| D1 — vocabulary manifests + import scripts | implemented |
| D2 — seed orchestrator | implemented (`seed/`) |
| D3 — EmbeddableContent end-to-end | implemented |
| D4 — WikibaseCitation (citeproc-php) | implemented |
| D5 — tests & acceptance (unit + E2E + XSS + page flows) | implemented |
| D6 — deployment + docs | **deployed to production Aug 17 2026** (seeded + verified) |
| Issue #7 — external-authority entity creation | implemented + deployed (`Special:AddPerson` / `AddSource` / `AddCollective`, fetch layer, citation type/source overhaul) |

## Repository layout

- `extensions/EmbeddableContent/` — vocabulary manifests (`manifests/`, incl.
  the issue-#7 ExternalId authority + citation-metadata properties and
  agent/work classes) + `maintenance/importVocabulary.php`; D3 renderer,
  `Special:Embed`, `api.php?action=embed`, oEmbed, the content pages
  (`Special:AddQuotation` / `AddCodeSnippet` / `AddMath`), the issue-#7
  external-authority pages (`Special:AddPerson` / `AddSource` / `AddCollective`,
  login-gated, import-on-reference), the `includes/Fetch/` provider layer
  (Wikidata hub + dblp SPARQL, OpenAlex, Crossref, Open Library, ORCID —
  SSRF-allowlisted), entity-page gadget (copy embed / copy citation).
- `extensions/WikibaseCitation/` — citation map manifests +
  `maintenance/importCitationMap.php` (publishes the 4 admin-editable
  `MediaWiki:Citation-*` pages); D4 `api.php?action=citation` with
  **citeproc-php** (APA/Vancouver, vendored CSL styles in `styles/`) and native
  BibTeX/RIS serializers. Issue #7: CSL type follows the **source** class;
  harvested source fields (published in, publisher, pages, volume, issue, DOI,
  ISBN). No Node sidecar.
- `seed/` — instance bootstrap orchestrator (`seed_instance.py`), API client,
  dogfood entities, config/report emission, self-verification (idempotent,
  exact-label skip).
- `tools/` — manifest generators + `fetch-smoke.php` (live provider smoke test).
- `tests/` — PHPUnit unit tests (pure-PHP logic, no MediaWiki runtime) and the
  `tests/e2e/` suites against a seeded live instance: `run_e2e.py`
  (acceptance + XSS) and `run_pages_e2e.py` (issue-#7 page flows:
  AddPerson/AddSource/AddCollective + the AddQuotation form).

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
  D2 seed → config-map restart → E2E acceptance → XSS suite → **page-flow E2E**
  (issue-#7 Special pages)

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
python3 tests/e2e/run_pages_e2e.py --base-url https://wikibase.ronzz.org \
    --user SeedBot --password-file seed/.seedbot.pass   # page flows, self-cleaning
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
