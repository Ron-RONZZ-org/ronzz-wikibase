# ronzz-wikibase

Customization project for **wikibase.ronzz.org** — the self-hosted Wikibase
(structured-data wiki) at ronzz.org. This repo tracks **proposals, designs and
issues** for the extensions being built on top of it; the extension code itself
will live here as it is written.

## Status (Aug 16 2026)

- Instance: MediaWiki 1.46 + Wikibase (source checkout) on `ronzz-linux-server-2`,
  WDQS/Blazegraph SPARQL live, anon read-only, registration closed.
- Two extensions planned, replacing retired `ronzzdoi` features (no data migration —
  ronzzdoi never reached production):
  - **WikibaseCitation** — entity statements → APA/Vancouver/JSON/BibTeX/RIS
  - **EmbeddableContent** — quotations / code / KaTeX as entity content + HTML embeds
- Standalone extensions, never forks of Wikibase (see issues + docs).

## Issue map

| # | Issue | Type |
|---|-------|------|
| #1 | Property-vocabulary importer (rdf/rdfs/owl/skos/dcterms/…) | proposal |
| #2 | WikibaseCitation — entities → citations | proposal |
| #3 | EmbeddableContent — quotes/code/KaTeX + embeds | proposal |
| #4 | Design: WikibaseCitation (endpoints, flows, config, deployment) | design (sub-issue of #2) |
| #5 | Design: EmbeddableContent (surfaces, renderers, security, deployment) | design (sub-issue of #3) |

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
