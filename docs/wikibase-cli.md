# Wikibase CLI operations — server-side administration

How to perform admin tasks on `wikibase.ronzz.org` directly from the server,
without logging in through the web UI. Requires **SSH + sudo on
`ronzz-linux-server-2`** (`158.178.193.231`, user `ubuntu`, passwordless sudo).
For content editing see the on-wiki `Help:Contributing` family
(`docs/contribution-guide.md` points there); for the stack see `README.md`.

## Access model

```bash
# From any machine with the ssh alias (e.g. libres):
ssh ronzz-linux-server-2
cd /var/www/wikibase

# Every maintenance command runs as the wiki's owner:
sudo -u ronzz php maintenance/run.php <script> [options]
```

| What | Command |
|------|---------|
| Edit/create any wiki page (incl. `MediaWiki:` namespace) | `maintenance/run.php edit.php` (below) |
| Rebuild message/interface cache | `maintenance/run.php rebuildLocalisationCache.php --force` |
| Purge message blob store | `maintenance/run.php purgeMessageBlobStore.php` |
| Purge parser cache | `maintenance/run.php purgeParserCache.php` |
| Reset admin password | `maintenance/changePassword.php --user=Rongzhou --prompt` |
| Change admin email | `maintenance/resetUserEmail.php --no-reset-password Rongzhou <email>` |

`maintenance/run.php` is the wrapper around individual scripts (MW 1.46).
Scripts must be invoked **as user `ronzz`** (`sudo -u ronzz php …`) so DB
credentials from `LocalSettings.php` resolve correctly.

## Editing a wiki page from the CLI

`edit.php` reads the page content from **stdin** and takes the page title as an
argument. It bypasses web-UI permissions, so it can touch protected
`MediaWiki:` pages — pass `--user=Rongzhou` so edits are attributed correctly:

```bash
printf "Some new content\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou -s "Edit summary" "MediaWiki:Sidebar"
```

Flags of note: `--user/-u` (attribution), `--summary/-s`, `--minor/-m`,
`--bot/-b`, `--createonly`, `--nocreate`, `--no-rc/-r` (hide from recent changes).

## Sidebar / interface-message edits (worked example, Aug 16 2026)

The sidebar is defined by the `MediaWiki:Sidebar` page. Section headings and
link labels are **message keys** resolved via `MediaWiki:<key>` pages — create
those too or the raw key is displayed. Workflow:

```bash
# 1) Replicate the current default + your changes into MediaWiki:Sidebar.
#    (The MW 1.46 default is in languages/i18n/nontranslatable/en.json → "sidebar".)
printf "%s" "
* navigation
** mainpage|mainpage-description
** recentchanges-url|recentchanges
** randompage-url|randompage
** helppage|help-mediawiki
** specialpages-url|specialpages
* SEARCH
* TOOLBOX
* semantic-tools
** special:newitem|newitem
** special:newproperty|newproperty
** special:addperson|addperson
** special:addsource|addsource
** special:addcollective|addcollective
** special:addquotation|addquotation
** special:addcodesnippet|addcodesnippet
** special:addmath|addmath
" | sudo -u ronzz php maintenance/run.php edit.php --user=Rongzhou -s "Sidebar" "MediaWiki:Sidebar"

# 2) Create the section heading + link-label messages:
printf "Semantic tools\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou "MediaWiki:semantic-tools"
printf "Create new property\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou "MediaWiki:newproperty"
printf "Add person\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou "MediaWiki:addperson"
printf "Add source\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou "MediaWiki:addsource"
printf "Add collective\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou "MediaWiki:addcollective"
printf "Add quotation\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou "MediaWiki:addquotation"
printf "Add code snippet\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou "MediaWiki:addcodesnippet"
printf "Add mathematical expression\n" | sudo -u ronzz php maintenance/run.php edit.php \
  --user=Rongzhou "MediaWiki:addmath"
# (repeat for every label key used in the sidebar)

# 3) Restart php-fpm — REQUIRED, see Gotcha below:
sudo systemctl restart php8.3-fpm
```

Resulting sidebar sections (current state): **Navigation** → **Search** →
**Page tools** (renamed from "Tools" via `MediaWiki:Toolbox`) →
**Semantic tools** (NewItem, NewProperty, ListProperties, ListDatatypes,
ItemDisambiguation, EntityData, plus the EmbeddableContent pages — AddPerson,
AddSource, AddCollective, AddQuotation, AddCodeSnippet, AddMath).

## ⚠️ Gotcha: CACHE_ACCEL (APCu) is per-process — CLI caches are not the live cache

`LocalSettings.php` sets `$wgMainCacheType = CACHE_ACCEL` (APCu). APCu is
**per PHP process**, and the CLI SAPI runs with `apc.enable_cli = Off`:

- `rebuildLocalisationCache.php` / `purgeMessageBlobStore.php` run from the
  shell write their result into the **CLI's throwaway APCu**, which PHP-FPM
  never reads.
- The live site therefore keeps serving the **stale** interface messages until
  the FPM pool restarts.

**Fix:** after any CLI change to `MediaWiki:*` pages or interface messages,
restart the pool:

```bash
sudo systemctl restart php8.3-fpm
```

(`systemctl reload` is not sufficient — APCu lives per worker process.)

**Symptoms if you skip it:** the edited page's raw content is correct via
`?action=raw` and in the DB, but the rendered site shows the old text/sidebar.
This is what happened during the Aug 16 2026 sidebar edit — the pages were
saved correctly on the first try; only the cache made it look like nothing
changed.

## Verifying changes

```bash
# Raw page content (what's in the DB):
curl -s "https://wikibase.ronzz.org/wiki/MediaWiki:Sidebar?action=raw"

# Rendered sidebar (what users see) — check for your section/labels:
curl -s -L "https://wikibase.ronzz.org/wiki/Item:Q1" -A "Mozilla/5.0" \
  | grep -oE "(Semantic tools|Page tools|Create new property)"
```

## API access vs CLI (when to use which)

The deciding factor is **where the tool runs, not the task**. With SSH access
to the server, *everything* is doable there — maintenance scripts, `edit.php`,
or any bulk tool installed on the server. The public API only matters when the
script must run on a machine **without** server access (e.g. a contributor's
laptop running Pywikibot), where you need login + CSRF tokens instead.

| Task | CLI / on-server (SSH to `ronzz-linux-server-2`) | Public web API |
|------|--------------|---------|
| Edit `MediaWiki:`/interface pages | ✅ `edit.php`, no login | needs interface-admin login |
| Create items/properties/statements | ✅ `edit.php`, or API scripts against `http://127.0.0.1:8081/api.php` | ✅ `action=wbeditentity` etc. |
| Bulk / scripted edits (Pywikibot, WikibaseIntegrator, …) | ✅ install & run the tool on the server (point it at the local or public URL) | ✅ from anywhere, with login |
| Reset passwords / user admin | ✅ maintenance scripts | ❌ |

For bulk edits, running the tool **on the server** is usually the better
choice anyway: no TLS overhead, no CSRF dance (or a local one), and tools like
Pywikibot can target `http://127.0.0.1:8081/api.php` directly. Just install the
tool there once (e.g. `pip install wikibaseintegrator` as `ronzz`).

CLI/on-server = admin & ops work, full power. Public API = content work from
machines outside the server, using `https://wikibase.ronzz.org/api.php`
(the internal `http://127.0.0.1:8081/api.php` mirror is reachable **only on
the server itself**).
