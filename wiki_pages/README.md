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

# Verify the bot credentials without writing (prints the logged-in user)
python3 tools/wiki_pages.py login --user RonzzWikiSync@GitHub --password-file /path/to/pass

# Send repo changes to production (needs the sync bot login)
python3 tools/wiki_pages.py push --user RonzzWikiSync@GitHub --password-file /path/to/pass

# Resolve a conflict explicitly
python3 tools/wiki_pages.py resolve --accept-prod   # keep the wiki
python3 tools/wiki_pages.py resolve --accept-repo   # keep the repo
```

Every command except `push`/`resolve --accept-repo` is anonymous. Push uses
`WIKI_SYNC_BOT_USER` / `WIKI_SYNC_BOT_PASS` (or `--user`/`--password-file`).
**Bot passwords log in with the `User@AppId` form** — the secret must be
`RonzzWikiSync@GitHub`, not the bare account name (a bare name fails with
"Incorrect username or password" even when the password is correct).

## Sync rules

- Only `manifest.json` titles are ever written.
- `pull` auto-discovers new `MediaWiki:` / `Template:` pages: a title that
  exists on the wiki but not in the manifest is added and pulled in the same
  run (pass `--no-discover` to skip). Existing entries, paths and `model`
  overrides are never changed by discovery.
- A page that changed on **both** sides since the last sync is a conflict:
  the tool exits 3, writes a report, and writes nothing. The hourly workflow
  opens/updates a `wiki-sync-conflict` issue.
- Deletions are never automatic. Missing pages block until resolved.
- The hourly pull auto-commits to `main`; a push to `main` touching this
  directory deploys those pages to the wiki.

See `docs/decisions/wiki-pages-parity.md` for the full design and the bot
rights.
