# AGENTS.md — ronzz-wikibase

## Scope

This repo tracks proposals, designs and issues for the Wikibase customization at
ronzz.org (wikibase.ronzz.org), holds the instance documentation (`docs/`), and will
hold the extension code once implementation starts. **Operational/server credentials
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

1. Proposals/designs are GitHub issues (#1–#5). Discuss in the issue before coding.
2. Extension work targets a dev instance (wikibase-docker reference deployment) —
   never develop directly on the production server.
3. Content model: properties first, then items (house rule on the instance).
4. Test layers: PHPUnit unit + MediaWiki integration + E2E (curl the endpoints);
   XSS suite is mandatory for EmbeddableContent.

## Reference

- Proposals & designs: GitHub issues #1–#5.
- Decisions & instance docs: `docs/` in this repo.
- MediaWiki/Wikibase docs: mediawiki.org, wikibase-docker (github.com/wmde/wikibase-docker).
