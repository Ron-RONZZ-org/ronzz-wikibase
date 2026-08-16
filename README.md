# ronzz-wikibase

Customization project for **wikibase.ronzz.org** — the self-hosted Wikibase
(structured-data wiki) at ronzz.org. The v1 plan lives in
[**GitHub issue #6**](https://github.com/Ron-RONZZ-org/ronzz-wikibase/issues/6)
(fresh-instance bootstrap + EmbeddableContent + WikibaseCitation); the extension
code itself will live here as it is written.

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
