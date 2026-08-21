# Wikibase — wikibase.ronzz.org

Self-hosted **Wikibase** (structured-data wiki, the software behind Wikidata) on
`ronzz-linux-server-2` (158.178.193.231). Re-enabled Aug 15 2026.

> **Instance deployment details (stack, endpoints, services, access control,
> uploads, media, CLI operations) are documented on the gated wiki** at
> **`RonzzIT:Wikibase`** · **`RonzzIT:Wikibase/Reference`** ·
> **`RonzzIT:Wikibase/CLI`** (wikibase.ronzz.org, `it` user group).
> They were moved out of this public repo on 2026-08-20 — update the wiki pages,
> not this file.

## What stays public (this repo)

- Extension code: EmbeddableContent, WikibaseCitation (+ the shared entity model)
- seed/ (bootstrap orchestrator), tools/, tests/, dev/ (CI stack)
- `docs/decisions/` — ADR-style design rationale (opaque IDs, ontology alignment,
  raw RDF in Blazegraph, cite-by-QID, static LLM translation)
- `docs/contribution-guide.md` — pointer to the on-wiki `Help:Contributing` family
- Editor-facing rules live on-wiki at `Help:Contributing` (public)

## Official documentation & tutorials

- **MediaWiki** — https://www.mediawiki.org/wiki/MediaWiki · Manual: https://www.mediawiki.org/wiki/Manual:Contents
- **Wikibase** — https://www.mediawiki.org/wiki/Wikibase · API docs: https://doc.wikimedia.org/Wikibase/master/php/
- **Wikidata intro** — https://www.wikidata.org/wiki/Wikidata:Introduction
- **SPARQL 1.1 spec** — https://www.w3.org/TR/sparql11-query/
- **Wikidata SPARQL tutorial** — https://www.wikidata.org/wiki/Wikidata:SPARQL_tutorial
- **WDQS** — https://www.mediawiki.org/wiki/Wikidata_Query_Service
- **MediaWiki API** — https://www.mediawiki.org/wiki/API:Main_page
- **Wikibase API** (entity CRUD) — https://www.wikidata.org/w/api.php
- **wikibase-docker** (reference deployment) — https://github.com/wmde/wikibase-docker

## Decisions

Architecture/design choices (ADR-style) live in `docs/decisions/`:
`opaque-id.md` (entity IDs stay opaque Q/P), `ontology-alignment.md` (mirror
properties + equivalence mappings), `raw-rdf-in-blazegraph.md` (two-worlds:
curated entities + native RDF), `cite-by-qid.md` (citations as a derived view),
`static-llm-translation.md` (static LLM-maintained copies, no translation markup),
`owui-wiki-writer.md` (Open WebUI as the LLM writer studio — MCP endpoint +
least-privilege writer bot).
