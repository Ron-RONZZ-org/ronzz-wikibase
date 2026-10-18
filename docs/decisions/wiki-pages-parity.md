# Decision: two-way repo ↔ production sync for wiki pages

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — the `MediaWiki:` and `Template:`
  customisations (`MediaWiki:Common.css`, license/sidebar/message overrides,
  the source/person/FOSS infobox templates, …)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The repo tracked extension code, seed vocabulary and documentation, but the
instance's **wiki-page customisations** existed only in the production
database: `MediaWiki:Common.css`, `MediaWiki:Licenses`, `MediaWiki:Sidebar`,
`MediaWiki:Toolbox`, the `MediaWiki:` interface-message overrides, and 57
`Template:` pages. They had no review history, no CI check, and a rebuild
from the repo could not recreate them. A few `MediaWiki:Citation-*.json`
pages are different: they are **generated** by
`extensions/WikibaseCitation/maintenance/importCitationMap.php` from repo
manifests, so they are excluded from the tracked set.

## Decision

### 1. Repo mirror + state

`wiki_pages/` is a file mirror of the tracked pages:

- `wiki_pages/manifest.json` — the authoritative allowlist (title ↔ path ↔
  optional content model);
- `wiki_pages/state.json` — per page: the last synchronized wiki `revid`,
  content `sha256`, and timestamp.
- `tools/wiki_pages.py` — stdlib-only sync tool (pull / push / verify /
  status / resolve / discover).

Paths mirror the namespace (`MediaWiki/Common.css`,
`Template/FOSS/Infobox.wiki`); `.css`/`.js`/`.json` keep their extension,
wikitext gets `.wiki`.

### 2. Conflict contract

Every operation compares **both sides** against the state:

| Wiki since sync | Repo since sync | Result |
|---|---|---|
| no | no | in-sync (refresh a moved revid) |
| yes | no | `prod-ahead` → pull |
| no | yes | `repo-ahead` → push |
| yes | yes, same content | in-sync (both converged) |
| yes | yes, different content | **conflict** → exit 3, write a report, write nothing |

A conflict blocks the **whole** pull/push before any page is written, so the
repo is never left half-synced. A race after that point is still caught by
`baserevid` (the successful pages are recorded; the racing page fails loudly).
Deletions are never automatic: a page missing on either side is reported and
blocks until a human resolves it. Writes are limited to manifest titles; the
tool has no ad-hoc title argument.

### 3. Automation

`.github/workflows/wiki-pages.yml`, concurrency group `wiki-pages-sync`:

- **hourly (`0 * * * *`)**: `pull` — wiki changes are written into the repo
  and auto-committed to `main` by `github-actions[bot]` with `[skip ci]`.
  The pull **auto-discovers** new `MediaWiki:`/`Template:` pages: missing
  titles are merged into `manifest.json` (never dropping or reordering an
  existing entry) and pulled in the same run, so a new template is tracked
  without a manual manifest edit. `--no-discover` opts out;
- **push to `main` touching `wiki_pages/`**: `push` — repo changes are
  written to the wiki, then the new revisions are committed to
  `state.json`;
- **manual `workflow_dispatch`**: `status` / `verify` / `pull` / `push`.

On exit 3 the workflow creates or comments on an open
`wiki-sync-conflict` issue with the per-page wiki/repo hashes and fails the
job. Nothing is overwritten. Resolve explicitly with:

```bash
python3 tools/wiki_pages.py resolve --accept-prod   # keep the wiki
python3 tools/wiki_pages.py resolve --accept-repo   # keep the repo
```

A state-only commit uses `GITHUB_TOKEN` (no recursive workflow run), and a
no-op pull does not rewrite `state.json`, so the hourly job does not create
empty commits.

Both commit steps use a **reset-and-retry** loop instead of
`git pull --rebase`: on a rejected push they `git fetch` + `git reset --hard
origin/main` and re-run the (idempotent) sync tool. A manual push that lands
between checkout and commit otherwise conflicts on the generated
`state.json` — observed 2026-10-18 (run 39106911340).

### 4. Bot and rights

A dedicated **`RonzzWikiSync`** account (groups `bot` + `interface-admin`)
with a bot password carrying `basic, editpage, createeditmovepage,
editsiteconfig, highvolume`. The `editsiteconfig` grant is the one that
covers `editsitecss`/`editsitejs` (needed for `MediaWiki:Common.css`).
Credentials live only in the `WIKI_SYNC_BOT_USER` / `WIKI_SYNC_BOT_PASS`
GitHub secrets. **`WIKI_SYNC_BOT_USER` must carry the bot-password login form
`RonzzWikiSync@GitHub`** — MediaWiki bot passwords authenticate as
`User@AppId`; the bare account name fails with "Incorrect username or
password" even when the password is right (verified 2026-10-18). The account
is dedicated to this workflow, and the tool refuses non-manifest titles.

`editsiteconfig` can edit site JS (XSS vector): the account is not used
interactively, the workflow only runs the allowlisted sync tool, and the
password is rotated by invalidating the bot password (runbook).

One-time setup on the server (as `ronzz`, from `/var/www/wikibase`; suppress
stdout — `createBotPassword.php` echoes the generated secret):

```bash
php maintenance/run.php createAndPromote.php --bot --interface-admin \
  RonzzWikiSync '<strong-account-password>'
php maintenance/run.php createBotPassword.php RonzzWikiSync '<32-char-secret>' \
  --appid=GitHub \
  --grants=basic,editpage,createeditmovepage,editsiteconfig,highvolume
gh secret set WIKI_SYNC_BOT_USER --repo Ron-RONZZ-org/ronzz-wikibase \
  --body 'RonzzWikiSync@GitHub'
gh secret set WIKI_SYNC_BOT_PASS --repo Ron-RONZZ-org/ronzz-wikibase \
  < /path/to/generated-pass
```

Rotate: `invalidateBotPasswords.php --user=RonzzWikiSync` **then delete the
`bot_passwords` row** (`invalidate` nulls the hash but keeps the row, so
re-creating the same `GitHub` appid fails), recreate the bot password
(capture the generated secret without printing it), update the
`WIKI_SYNC_BOT_PASS` secret, and verify with
`gh workflow run wiki-pages.yml -f mode=login`.

### 5. What is not tracked (two-way)

- `MediaWiki:Citation-property-map.json`, `Citation-type-map.json`,
  `Citation-source-property-map.json`, `Citation-source-type-map.json` —
  generated from repo manifests by `importCitationMap.php`; tracked at their
  source, not their on-wiki copies.
- User pages, talk pages, category pages and the gated `RonzzIT:`/
  `RonzzInt:` runbook pages (the latter are mirrored separately by
  `ronzz-it-runbooks`).
- Content pages — see the one-way mirror below.

### 6. Content mirror (one-way, separate repo)

The public **content** namespaces (Main, Cheatsheets, HowItWorks, FOSS,
Person, Source, Collective, Software) are mirrored into the dedicated
[`Ron-RONZZ-org/ronzzwikibase-public-mirror`](https://github.com/Ron-RONZZ-org/ronzzwikibase-public-mirror)
repository, **not** this one. The code/config repo keeps the tool
(`tools/wiki_content_mirror.py`); the snapshot repo keeps the data and runs
the workflow. This keeps the 15-minute content commits out of the code
history while preserving a git-backed, searchable snapshot.

It is deliberately **one-way**: it never writes to the wiki, so it cannot
overwrite on-wiki authoring, and the wiki remains the single source of truth.

- The mirror repo's `.github/workflows/mirror.yml` clones this repo
  (`--depth=1`), runs the tool with `--dir . --state state.json --prune`, and
  commits changes with `[skip ci]` every 15 minutes.
- Incremental: the tool lists titles, fetches current `revid`s, and downloads
  only pages whose revid/path changed. `--full` refetches everything.
- `<mirror>/state.json` records `{path, revid, sha256, synced_at}`.
- Deletions are reported as orphans; `--prune` removes the file (git history
  retains it). A mass prune over 10% is refused without `--force-prune`.
- The gated `RonzzIT:`/`RonzzInt:` namespaces are **refused** — the mirror
  repo is public and those pages belong to the private `ronzz-it-runbooks`
  mirror.
- `--dir` is required, so a local tool run cannot accidentally write into the
  code checkout.

## Consequences

- `MediaWiki:Common.css` and every custom template now have git history,
  review, and drift detection.
- A direct wiki edit is pulled into the repo within the hour; a repo edit is
  pushed on merge. Simultaneous edits are never silently overwritten — they
  surface as a red workflow + issue.
- `ci.yml` ignores `wiki_pages/**` (content, not code); the sync workflow is
  the gate for those files.
- The content mirror gives the **mirror repo** an offline, searchable
  snapshot of the public pages and a per-edit git history, without making
  those pages a write surface or polluting this repo's history.
- If the bot password leaks, rotate it; the repo itself contains no secrets.

## References

- `wiki_pages/manifest.json`, `wiki_pages/state.json`, `wiki_pages/README.md`
- `tools/wiki_pages.py`, `tools/tests/test_wiki_pages.py`
- `tools/wiki_content_mirror.py`, `tools/tests/test_wiki_content_mirror.py`
- `.github/workflows/wiki-pages.yml`
- `Ron-RONZZ-org/ronzzwikibase-public-mirror` (README, `state.json`, `mirror.yml`)
- `docs/decisions/upload-enhancements.md` (the excluded generated citation
  maps), `extensions/WikibaseCitation/maintenance/importCitationMap.php`
