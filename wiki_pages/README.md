# wiki_pages — production wiki page mirror

This directory mirrors the production `MediaWiki:` / `Template:` pages that
are **not** generated from extension code. `manifest.json` is the allowlist,
`state.json` records the last synchronized revision/hash of each page, and
`tools/wiki_pages.py` performs the two-way sync.

## Layout

- `MediaWiki/Common.css` — site CSS (content model `css`)
- `MediaWiki/Licenses.wiki`, `MediaWiki/Sidebar.wiki`, … — interface
  overrides
- `Template/Book.wiki`, `Template/FOSS/Infobox.wiki`, … — custom templates
  (subpage `/` becomes a directory, wikitext files get `.wiki`)

Do **not** track `MediaWiki:Citation-*.json`: those pages are published from
the repo's citation manifests by
`extensions/WikibaseCitation/maintenance/importCitationMap.php`.

## Local commands

```bash
# See what changed where (read-only, anonymous)
python3 tools/wiki_pages.py status
python3 tools/wiki_pages.py verify

# Take production changes into the working tree
python3 tools/wiki_pages.py pull

# Send repo changes to production (needs the sync bot login)
python3 tools/wiki_pages.py push --user RonzzWikiSync --password-file /path/to/pass

# Resolve a conflict explicitly
python3 tools/wiki_pages.py resolve --accept-prod   # keep the wiki
python3 tools/wiki_pages.py resolve --accept-repo   # keep the repo
```

Every command except `push`/`resolve --accept-repo` is anonymous. Push uses
`WIKI_SYNC_BOT_USER` / `WIKI_SYNC_BOT_PASS` (or `--user`/`--password-file`).

## Sync rules

- Only `manifest.json` titles are ever written.
- A page that changed on **both** sides since the last sync is a conflict:
  the tool exits 3, writes a report, and writes nothing. The hourly workflow
  opens/updates a `wiki-sync-conflict` issue.
- Deletions are never automatic. Missing pages block until resolved.
- The hourly pull auto-commits to `main`; a push to `main` touching this
  directory deploys those pages to the wiki.

See `docs/decisions/wiki-pages-parity.md` for the full design and the bot
rights.
