# AGENTS.md — docs Agent Instructions

## Summary

Instance documentation for ronzz-wikibase (wikibase.ronzz.org): the stack,
endpoints, access control, admin/CLI operations, contribution rules and
ADR-style decisions. **Operational/server credentials never belong in this
repo** — they live in the private Nextcloud docs
(`~/shared-ronzz-nextcloud/docs/IT/ronzz-linux-server-2.md`).

## Purpose and Expected Behavior

This module is the human-readable companion to the code: how the instance is
deployed, how to operate it, and why it is designed the way it is.

- `docs/README.md` — instance stack (MW 1.46 + Wikibase repo + WDQS 0.3.156 +
  MySQL), endpoints, services, access control, SPARQL/API usage, skins,
  Translate/ULS setup, MCP server setup. On-wiki dev cheatsheets live in the
  `Cheatsheets:` namespace (issue #20, LocalSettings-only change).
- `docs/contribution-guide.md` — pointer: the editor-facing rules
  (properties/items, statements, API bulk editing, house rules) live on-wiki
  at the `Help:Contributing` family.
- `docs/wikibase-cli.md` — server-side admin/CLI operations (editing
  `MediaWiki:` pages, maintenance scripts, cache gotchas).
- `docs/decisions/` — ADR-style decisions: `opaque-id.md` (opaque Q/P IDs,
  fork maintenance rejected), `ontology-alignment.md` (mirror properties +
  equivalence mappings, no storage-injection), `raw-rdf-in-blazegraph.md`
  (two-worlds: curated entities + native RDF under its own URI namespace),
  `cite-by-qid.md` (citations as a derived view over items — `{{#cite}}`/
  `{{#citations}}` parser functions, issues #24/#25; accepted but not yet
  implemented).

## Constraints and Invariants

- **No credentials in this repo** — server config, OCI identity, `.env`
  paths and bot passwords live in the private Nextcloud ops doc
  (`~/shared-ronzz-nextcloud/docs/IT/ronzz-linux-server-2.md`) or in
  `~/.config/mediawiki-mcp/ronzz-wikibase.json` (MCP bot credentials). Never
  copy them here.
- The 127.0.0.1:8080 port belongs to **Nextcloud** (docker-proxy, since Aug
  14 2026); the wiki's internal nginx block is `127.0.0.1:8081`. Never put it
  back on 8080 — the WDQS updater would poll Nextcloud and crash.
- Decisions in `docs/decisions/` are already settled — do not relitigate;
  propose a new ADR instead.
- Document the *current* deployed state — update these files whenever a
  deploy or structural change lands (stale docs are worse than no docs).

## Input/Output Expectations

- **Input**: deployment reality (what is actually running on
  ronzz-linux-server-2), decisions, endpoint facts.
- **Output**: accurate markdown reference for humans and agents; nothing
  executable, nothing secret.

## Documentation Reference

- `docs/README.md` — stack table, endpoints, services, gotchas (port 8081),
  access control, SPARQL/API examples, official MediaWiki/Wikibase links
- `docs/contribution-guide.md` — pointer to the on-wiki `Help:Contributing`
  family (see also `content-creation/AGENTS.md` for the live-wiki workflow)
- `docs/wikibase-cli.md` — admin CLI operations
- `docs/decisions/` — ADR-style decisions
- `docs/AGENTS.md` — this file (module conventions)

## Domain-Specific Rules for Agents

- Keep instance facts (versions, endpoints, paths, service names) exact —
  they are copied into runbooks and troubleshooting.
- Mark gotchas prominently (port ownership, case-sensitive usernames,
  `$wgJobRunRate = 0;` + cron, render-job claims) — they are hard-won.
- When a docs change accompanies a code change, land them together (see
  Documentation Standards in the repo root AGENTS.md).
- If a documented behaviour diverges from reality, fix the doc *and* flag the
  discrepancy — do not silently pick one side.
