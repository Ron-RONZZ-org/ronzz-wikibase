# wiki_content — read-only mirror of the public wiki content

**Generated. Do not edit these files by hand.** The wiki
(`https://wikibase.ronzz.org`) is the source of truth; this directory is a
one-way snapshot maintained by `tools/wiki_content_mirror.py`
(`.github/workflows/wiki-content.yml`, every 15 minutes).

- `state.json` — per page: mirror path, wiki `revid`, content `sha256`,
  sync timestamp. The next run fetches content only for pages whose revid
  changed.
- `<Namespace>/<Title>.wiki` — page source (`Main/` for the main namespace,
  subpages become directories).

## Scope

Mirrored namespaces: **Main**, **Cheatsheets**, **HowItWorks**, **FOSS**,
**Person**, **Source**, **Collective**, **Software**.

Deliberately excluded:

- **`RonzzIT:` / `RonzzInt:`** — gated ops documentation, mirrored in the
  private `ronzz-it-runbooks` repo. This repo is public; the tool refuses to
  mirror them.
- `MediaWiki:` / `Template:` — two-way synced in [`../wiki_pages/`](../wiki_pages/)
  instead.
- `Item:` / `Property:` — Wikibase entities (database-backed, not page
  content), plus user/talk/category pages and `File:` metadata.

## Local commands

```bash
# Show what would change (no writes)
python3 tools/wiki_content_mirror.py --dry-run

# Update the mirror; --prune removes pages deleted on the wiki
# (a mass prune >10% needs --force-prune)
python3 tools/wiki_content_mirror.py --prune

# Force-refetch every page (e.g. after a format change)
python3 tools/wiki_content_mirror.py --full
```

This tool never writes to the wiki. To edit content, use the wiki (or the
MediaWiki MCP tools); the mirror catches up on the next scheduled run.
