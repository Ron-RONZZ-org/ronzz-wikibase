# Wikibase — wikibase.ronzz.org

Self-hosted **Wikibase** (structured-data wiki, the software behind Wikidata) on
`ronzz-linux-server-2` (158.178.193.231). Re-enabled Aug 15 2026.

## Stack

| Component | Version / Detail | Location |
|-----------|------------------|----------|
| MediaWiki | 1.46.0 (php-fpm 8.3.6) | `/var/www/wikibase/` |
| Wikibase (repo + client, same wiki) | source checkout (RELEASE-NOTES up to 1.44); **client enabled Aug 19 2026** — sitelinks + parser functions (see [Semantic dynamic content](#semantic-dynamic-content-same-wiki-repoclient-aug-19-2026)) | `/var/www/wikibase/extensions/Wikibase/` |
| Skins | **Vector** (default, REL1_46 git clone) + **Timeless** (selectable) | `/var/www/wikibase/skins/{Vector,Timeless}/` |
| EmbeddableContent (D3 + issue #7) | extension (quotation/code/math embeds, `Special:AddQuotation`/`AddCodeSnippet`/`AddMath`, `Special:AddPerson`/`AddSource`/`AddCollective`) | `/var/www/wikibase/extensions/EmbeddableContent/` |
| WikibaseCitation (D4) | extension (`action=citation`, citeproc-php; cite-by-QID parser functions `{{#cite}}`/`{{#citations}}` planned — `docs/decisions/cite-by-qid.md`, issues #24/#25) | `/var/www/wikibase/extensions/WikibaseCitation/` |
| SyntaxHighlight_GeSHi (Aug 18 2026) | extension (Pygments code highlighting + built-in copy button on wiki pages; official REL1_46) | `/var/www/wikibase/extensions/SyntaxHighlight_GeSHi/` |
| Translate + UniversalLanguageSelector (Aug 18 2026) | extensions — **Translate kept installed but inert** since Aug 19 2026 (no marked pages; static LLM translations, see `docs/decisions/static-llm-translation.md`); ULS language selector active (entity terms, interface; official REL1_46) | `/var/www/wikibase/extensions/{Translate,UniversalLanguageSelector}/` |
| WDQS (Blazegraph SPARQL + updater) | service 0.3.156 | `/var/www/wdqs/service-0.3.156/` |
| Blazegraph data | journal `/var/www/wdqs/data/wikidata.jnl` (DiskRW, 200 MB max) | `/var/www/wdqs/data/` |
| MySQL DB | database `wikibase` (local MySQL, legacy) | — |

## Endpoints

| Endpoint | Purpose |
|----------|---------|
| `https://wikibase.ronzz.org/` | Main wiki UI (redirects to `/wiki/Main_Page`) |
| `https://wikibase.ronzz.org/wiki/…` | Pretty article/entity pages |
| `https://wikibase.ronzz.org/sparql` | **SPARQL query endpoint** (WDQS) |
| `https://wikibase.ronzz.org/api.php` | MediaWiki + Wikibase API |
| `http://127.0.0.1:8081/api.php` | Internal API — **only valid on the server itself** (`ronzz-linux-server-2`); used by the WDQS updater. From any other machine use `https://wikibase.ronzz.org/api.php` |
| `http://127.0.0.1:9999/bigdata/` | Blazegraph directly (server-local only) |

> **Gotcha:** `127.0.0.1:8080` is **Nextcloud** (docker-proxy, since Aug 14 2026).
> Wikibase's internal nginx block was moved to **`127.0.0.1:8081`**. Never put it
> back on 8080 — the WDQS updater would poll Nextcloud instead of MediaWiki and crash
> (JSON parse error in `fetchRecentChanges`).

## Services

| Unit | Role |
|------|------|
| `wdqs-blazegraph.service` | Blazegraph SPARQL server (port 9999) |
| `wdqs-updater.service` | Polls MediaWiki recent changes → syncs RDF into Blazegraph |
| `php8.3-fpm.service` | PHP-FPM (MediaWiki) |
| `nginx` | TLS + proxying (`wikibase.ronzz.org.conf` in sites-available/enabled) |
| `cron` (user `ronzz`) | `runJobs.php` every 5 min (job queue — see note below) |

All run as user `ronzz`. Updater polls `--wikibaseUrl http://127.0.0.1:8081`
(`--entityNamespaces 120,122`, concept URI `https://wikibase.ronzz.org`).
`127.0.0.1:8081` is the wiki's internal nginx block and is reachable **only on
`ronzz-linux-server-2` itself** — from any other host use `https://wikibase.ronzz.org/api.php`.

```bash
# Status / logs / restart
sudo systemctl status wdqs-blazegraph wdqs-updater php8.3-fpm
sudo journalctl -u wdqs-updater -f
sudo systemctl restart wdqs-updater
```

## Basic usage

### Browse & edit
Open `https://wikibase.ronzz.org/` and log in. Entity namespaces: **Item = 120**,
**Property = 122** (URL form `/wiki/Item:Q1`). Workflow: Properties first, then Items.

> **For editors**: the step-by-step instructions (creating properties/items,
> statements, API bulk editing, house rules) live **on-wiki** at
> `https://wikibase.ronzz.org/wiki/Help:Contributing` (migrated from
> `contribution-guide.md` in this repo, which now only points there).
> (incl. **`Help:Contributing/code`** for code-block content and **`Help:Contributing/languages`**
> for entity terms + page translation, both added Aug 18 2026; plain wikitext, no translation
> markup — fr/eo arrive as static `/lang` subpages, see §Translation below).
> **Dev cheatsheets** (Markdown, Quarto, Bash, Git, SPARQL) live in the
> **`Cheatsheets:` namespace** (`https://wikibase.ronzz.org/wiki/Cheatsheets:<Topic>`),
> hub at **`Cheatsheets:Main`** — see issue #20 (namespace is a LocalSettings-only
> change; plain wikitext, no translation markup, per
> `content-creation/AGENTS.md`; MediaWiki rejects a bare namespace prefix as a
> page title, hence `Main`).
> **Technology dissections** (how things actually work, no prior knowledge
> assumed) live in the **`HowItWorks:` namespace**
> (`https://wikibase.ronzz.org/wiki/HowItWorks:<Technology>`), hub at
> **`HowItWorks:Main`** → `Category:HowItWorks` — see issue #23 (same
> LocalSettings-only mechanism, NS 2002; plain wikitext, no translation
> markup; pilot page: `HowItWorks:MediaWiki`, sitelinked to item Q164).
>
> **Search** (verified Aug 20 2026): both custom namespaces are already in
> `$wgNamespacesToBeSearchedDefault` (see the ops doc `wikibase.md` namespace
> blocks) and `Special:Search` finds pages in them — "MediaWiki" →
> `HowItWorks:MediaWiki`, "Markdown" → `Cheatsheets:Markdown`, "SPARQL" →
> `Cheatsheets:SPARQL`. **Known quirk**: the API module `list=search` returns
> 0 hits *without* an explicit `srnamespace=` even when `Special:Search` works
> (MW 1.46 + SearchMySQL — tracked as a follow-up in the ops doc). If search
> ever goes empty after bulk authoring, re-run `rebuildtextindex.php` (see
> `wikibase.md`).
>
> **For admins**: see **`wikibase-cli.md`** (same folder) for server-side CLI
> operations (editing `MediaWiki:` pages, maintenance scripts, cache gotchas).

### Access control (fully closed, Aug 15 2026)

- **Anonymous: read-only.** No `edit`, no registration, no entity rights
  (`read, viewmyprivateinfo, editmyprivateinfo, editmyoptions` only). SPARQL stays public.
- **Registration locked** — only sysops can create accounts (`Special:CreateAccount`).
- **Registered users** (vetted, admin-created) keep full entity editing.
- **Admin account**: **`Rongzhou`** / `ron@ronzz.org` (bureaucrat + sysop + interface-admin, user id 4).
  Password reset: `sudo -u ronzz php /var/www/wikibase/maintenance/changePassword.php --user=Rongzhou --prompt`
  Email change: `sudo -u ronzz php /var/www/wikibase/maintenance/resetUserEmail.php --no-reset-password Rongzhou <email>`
- **Default `Admin` account removed** (Aug 15 2026, had no edits → `removeUnusedAccounts.php --delete`).
  Gotchas: the DB is `CHARSET=binary` → **usernames are case-sensitive** (`Rongzhou`, not `rongzhou`);
  `removeUnusedAccounts` only flags accounts untouched for >1 day (safe for fresh accounts).
- Config in `LocalSettings.php` (access-control block): `edit`/`createpage`/`createtalk`/
  `createaccount` revoked from `*`. **Gotcha:** Wikibase grants entity rights
  (`item-term`, `property-term`, `item-merge`, `item-redirect`, `property-create`) to `*`
  in `extensions/Wikibase/repo/extension-repo.json` — they must be revoked from `*`
  **and re-granted to `user`** (otherwise logged-in users lose entity editing too).

### File uploads (1 GiB limit, Aug 19 2026)

- **Enabled with a 1 GiB cap**: `$wgMaxUploadSize = 1073741824` in `LocalSettings.php`
  (uploads were already on — `$wgEnableUploads = true` in the Wikibase block — but the
  effective cap was 100 MB MW default, *and* nginx/PHP defaults hard-blocked anything
  above 1 MB anyway).
- **Warning threshold kept at 50 MB** — `$wgUploadSizeWarning = 52428800` (explicit,
  decision: do not suppress; note the MW 1.46 default is `false` = no warning).
- **Three layers must move together** (any one silently caps the rest):
  - *nginx* `wikibase.ronzz.org.conf` — `client_max_body_size 1G; client_body_timeout 600s;
    fastcgi_read_timeout 600s;` on **both** the `:443` and internal `127.0.0.1:8081` server
    blocks (nginx default 1M → 413).
  - *php8.3-fpm pool `www`* (`/etc/php/8.3/fpm/pool.d/www.conf`) —
    `upload_max_filesize`/`post_max_size` = `1G`, `max_execution_time`/`max_input_time` = `600`,
    `memory_limit` = `512M` (defaults 2M/8M/30s/60s/128M; pool-scoped — the SnappyMail/Hesk
    pools are untouched).
  - *MediaWiki* — `$wgMaxUploadSize` (default 100 MB).
- **Verified Aug 19 2026**: 12,586,816-byte incompressible PNG uploaded via API as SeedBot
  over `127.0.0.1:8081` → Success → stored under `images/` → page deleted, no leftovers.
- **For files >100 MB use chunked API uploads** (`action=upload` with `stash=1` + `chunk`,
  finalize with `filekey`) — PyWikiBot/MWBot do this automatically. `$wgEnableAsyncUploads`
  stays **off** (job-queue assembly; not needed).
- **File types**: images — `png, gif, jpg, jpeg, webp` (MW 1.46 default; `svg` is NOT
  included). Audio/video — `ogg, ogv, oga, webm` (added Aug 19 2026 with
  TimedMediaHandler, see "Media playback" below). Anything else needs an explicit
  `$wgFileExtensions[]` addition (e.g. `'pdf'`).
- **Disk**: 32 GiB free on `/` at deploy time — 1 GiB files accumulate fast.

### Upload from URL + licensing (Aug 19 2026)

- **Upload-from-URL enabled for logged-in users**: `Special:Upload` now has a
  "URL" source radio + URL field (before: local file only). Config in
  `LocalSettings.php`: `$wgAllowCopyUploads = true;`
  `$wgCopyUploadsFromSpecialUpload = true;`
  `$wgGroupPermissions['user']['upload_by_url'] = true;`
  `$wgCopyUploadTimeout = 600;` `$wgCopyUploadAllowOnWikiDomainConfig = true;`.
  The API path (`action=upload` + `url=`) works too. Backup:
  `/var/backups/wikibase/LocalSettings.php.pre-uploadurl-ssrf-20260819-151037`.
- **SSRF guard (mandatory with this feature)**: `$wgHooks['IsUploadAllowedFromUrl']`
  closure in `LocalSettings.php` refuses loopback/private/link-local/ULA/metadata
  IPv4+IPv6 ranges (incl. `100.64.0.0/10`, `169.254.0.0/16`, `::1`, `fc00::/7`,
  `fe80::/10`, `224.0.0.0/4`, `240.0.0.0/4`, `2001:db8::/32`) and is fail-closed
  on unresolvable hosts. `MediaWiki:Copyupload-allowed-domains` exists but is
  left **empty** (= all hosts allowed; that message is a secondary
  MediaWiki-side filter — the hook guard is the real protection).
  **Verified Aug 19 2026**: `localhost`, `localtest.me` (resolves to loopback),
  `169.254.169.254`, `10.0.0.1`, `192.168.1.1` all refused (`copyuploadbadurl`);
  a public URL (gnu.org PNG) uploads fine, both via API and via the web form.
- **Licensing dropdown fixed**: `MediaWiki:Licenses` created Aug 19 2026 with 6
  options — CC BY-SA 4.0, CC BY 4.0, CC0 1.0, Public domain, Fair use,
  No license — plus matching `Template:` pages (`cc-by-sa-4.0`, `cc-by-4.0`,
  `cc0-1.0`, `pd`, `fairuse`, `no license`) with short license notices.
  Selecting a license on upload transcludes the template into the file page
  (verified end-to-end). **Format gotcha (MW 1.46)**: `Licenses::split()` puts
  the part **before** the pipe as the option value (the template name) and the
  part **after** as the display text: `* <template>|<display text>` — no spaces
  around the pipe.
- **Cache gotcha**: see `wikibase-cli.md` — API edits to `MediaWiki:*` messages
  only *sometimes* invalidate the message cache on this box; the reliable fix is
  `sudo systemctl restart php8.3-fpm` (per-process APCu).

### Media playback: audio & video (TimedMediaHandler, Aug 19 2026)

- **Extension**: `TimedMediaHandler` v0.6.0, cloned from gerrit branch `REL1_46`
  (`git clone --depth 1 -b REL1_46 https://gerrit.wikimedia.org/r/mediawiki/extensions/TimedMediaHandler`)
  into `extensions/`, owned `ronzz:ronzz` like the other extensions.
- **Config** (`LocalSettings.php`): `wfLoadExtension( 'TimedMediaHandler' );` +
  `$wgFileExtensions = array_merge( $wgFileExtensions, [ 'ogg', 'ogv', 'oga', 'webm' ] );`.
  TMH uses `/usr/bin/ffmpeg` (its default `$wgFFmpegLocation`; ffmpeg was already on the
  box; **no ffprobe needed** in REL1_46). `update.php --quick` created the transcode
  tables; php8.3-fpm restarted. Backup:
  `/var/backups/wikibase/LocalSettings.php.pre-timedmedia-20260819-1145`.
- **Transcode storage gotcha (fixed)**: transcodes are stored under `images/transcoded/`.
  `images/` is owned by `www-data` (uploads run under php-fpm), but the runJobs cron
  runs as **ronzz** — so the first transcode jobs failed with
  `Could not create directory mwstore://local-backend/local-transcoded/...`.
  Fix: `images/transcoded` created as `ronzz:www-data`, mode `775` (writable by both
  the web server and the cron user).
- **Formats**: TMH plays **Ogg (Theora/Vorbis/Opus) and WebM (VP8/VP9)** inline.
  MP3/MP4 are NOT supported for playback (Wikimedia patent policy) and are not in
  `$wgFileExtensions` — they would upload as plain link-only files if added.
- **Transcoding**: background jobs (type `webVideoTranscodePrioritized`), picked up by
  the existing 5-minute runJobs cron. Verified Aug 19 2026: `File:Example.ogv` →
  `240p.vp9.webm` (≈120 s) and `File:Example.ogg` → `mp3` both succeeded; check status
  via `api.php?action=query&prop=transcodestatus`. Re-queue failed/stuck jobs with
  `maintenance/run.php extensions/TimedMediaHandler/maintenance/requeueTranscodes.php
  --file=File:X` (**one `--file` per invocation** — multiple break the parser).
- **Example files** (both PD NASA, uploaded via SeedBot, from Wikimedia Commons):
  `File:Example.ogg` (Apollo 11 launch-day comms relayed through Canary Station,
  Vorbis, ~123 s) and `File:Example.ogv` (Buzz Aldrin descending the LM ladder,
  Theora).
- **On-wiki docs**: `Help:Contributing/richMediaContent` — "Embedding audio" and
  "Embedding video" sections with live players (rev 716, Aug 19 2026).

### Federation with Wikidata (official pattern)

- **Recommended: federated statements** — no config; create a property with datatype
  `ExternalId`/`Url` (e.g. "Wikidata ID") whose values point at Wikidata entities
  (`https://www.wikidata.org/wiki/Q…`). This is how Wikidata itself links to other
  Wikibases. See https://www.mediawiki.org/wiki/Wikibase/Federation
- **Not recommended: federated properties** (`$wgWBRepoSettings['federatedPropertiesEnabled'] = true;`)
  — MVP, development stopped; forbids local properties, incompatible with a custom schema.
- **Interwiki `d` prefix for `[[d:Q42]]` links is NOT yet added** (verified Aug 15 2026:
  49 prefixes, `d`/`wd` missing). Add via `maintenance/updateInterwiki.php` or SQL INSERT
  into `interwiki` (`d` → `https://www.wikidata.org/wiki/$1`).

### Semantic dynamic content: same-wiki repo+client (Aug 19 2026)

The wiki is now its own Wikibase **client** as well as the repo. Wiki pages can pull
item data at view time via the client parser functions; items can be linked to wiki
pages via sitelinks.

- **Config** (`LocalSettings.php`, backup `/var/backups/wikibase/LocalSettings.php.pre-sitelinks-client-20260819-*`):
  `$wgEnableWikibaseClient = true` (was `false`),
  `$wgWBRepoSettings['siteLinkGroups'] = [ 'ronzz' ]`,
  `$wgWBClientSettings['siteGlobalID'] = 'wikibase'`,
  `$wgWBClientSettings['siteLinkGroups'] = [ 'ronzz' ]`,
  `$wgWBClientSettings['repoDatabase'] = false; $wgWBClientSettings['changesDatabase'] = false`.
- **Site entry** (`maintenance/addSite.php`): global id `wikibase`, group `ronzz`,
  interwiki `wb`, page path `https://wikibase.ronzz.org/wiki/$1`, file path
  `https://wikibase.ronzz.org/$1`. **The file path is the script path** — the client
  builds the API URL as `getFileUrl('api.php')`; pointing it at `/images/` broke
  `wbsetsitelink` with `no-external-page` (fixed Aug 19 2026).
- **Validated Aug 19 2026**: `{{#statements:}}` (rich: links + language spans) and
  `{{#property:}}` (escaped plain text) render live, with `from=<id>` and
  label-as-property (`instance of` → P1); page↔item mapping works at parse time
  (`wikibase_item` page property, persisted on page save); item pages show a
  Sitelinks section. Live pairs: Q55 ↔ `Cheatsheets:Markdown`, Q77 ↔
  `Cheatsheets:SPARQL`.
- **On-wiki docs**: `Help:Contributing/semanticDynamicContent`.
- **Not available**: Scribunto is not installed — no `{{#invoke:Wikibase}}` Lua
  modules (parser functions only). Data bridge not enabled.
- **Parity**: dev/CI (WBS image) already loads repo + client by default; production
  now matches.

### Query SPARQL (curl)

```bash
# All triples (limit 5)
curl -sG "https://wikibase.ronzz.org/sparql" \
  --data-urlencode 'query=SELECT * WHERE { ?s ?p ?o } LIMIT 5'

# Count of triples
curl -sG "https://wikibase.ronzz.org/sparql" \
  --data-urlencode 'query=SELECT (COUNT(?s) AS ?cnt) WHERE { ?s ?p ?o }'

# Count items (wd: prefix resolves to the concept URI)
curl -sG "https://wikibase.ronzz.org/sparql" \
  --data-urlencode 'query=SELECT (COUNT(?item) AS ?n) WHERE { ?item wdt:P31 ?type }'
```

JSON output: append `&format=json` (adds `application/sparql-results+json` content
type). Default is XML.

### MediaWiki / Wikibase API

> The internal URL `http://127.0.0.1:8081/api.php` works **only on the server
> itself** (run these curl examples there). From any other machine, replace it
> with `https://wikibase.ronzz.org/api.php`.

```bash
# Site info (run on ronzz-linux-server-2, or use https://wikibase.ronzz.org/api.php elsewhere)
curl -s "http://127.0.0.1:8081/api.php?action=query&meta=siteinfo&format=json"

# List items in namespace 120 (Items)
curl -s "http://127.0.0.1:8081/api.php?action=query&list=allpages&apnamespace=120&format=json"
```

As of the **Aug 17 2026 v1 deployment**: seeded vocabulary — **31 properties** (incl. 8 ExternalId authority properties with formatter URLs: Wikidata ID, ORCID, VIAF, ISNI, DOI, ISBN-13, OpenAlex Work ID, PubMed ID; plus citation metadata: given/family name, published in, publisher, page(s), volume, issue; plus **image (P32, url datatype — attaches files/media to items, aligned to Wikidata P18 + schema.org, added Aug 19 2026)**) and **13 classes** (quotation content, code snippet, mathematical expression, programming language, person, organization, group of humans, book, scholarly article, website, song, film, video), **80 Pygments language items**, and 5 dogfood entities (person/book/quotation/code/math). The seed's self-verification is green; `Special:AddPerson` / `AddSource` / `AddCollective` import entities from external authorities (Wikidata hub + dblp SPARQL, OpenAlex, Crossref, Open Library, ORCID). P27 is a gap on the instance (deleted during early seeding). As of **Aug 20 2026**: cite-by-QID is designed and accepted (`docs/decisions/cite-by-qid.md`, issues #24/#25) — `{{#cite:Q42}}` in `<ref>` + `{{#citations}}` will render citations from the item graph (footnotes via the stock Cite extension); not yet implemented.

### Skins (installed Aug 15 2026)

- **Vector** (default — same look as Wikidata) + **Timeless** (responsive alternative).
- Skin dirs were empty placeholders from the source tarball; cloned REL1_46 branches
  from `https://gerrit.wikimedia.org/r/mediawiki/skins/{Vector,Timeless}`.
- `LocalSettings.php`: `wfLoadSkin( "Vector" ); wfLoadSkin( "Timeless" );`,
  `$wgDefaultSkin = "vector";` — note: the old `"vector-2022"` skin name was removed
  in Vector 1.2 (MW 1.40+); use `"vector"` (modern Vector 2022 look is the default,
  legacy look is a feature flag, not a skin).
- Switch manually: `?useskin=timeless` (logged-in users can pick in Preferences → Appearance).

## Official documentation & tutorials

- **MediaWiki** — https://www.mediawiki.org/wiki/MediaWiki · Manual: https://www.mediawiki.org/wiki/Manual:Contents
- **Wikibase** — https://www.mediawiki.org/wiki/Wikibase · API docs: https://doc.wikimedia.org/Wikibase/master/php/
- **Wikidata intro** (how a Wikibase instance is meant to be used) — https://www.wikidata.org/wiki/Wikidata:Introduction
- **SPARQL 1.1 spec** — https://www.w3.org/TR/sparql11-query/
- **Wikidata SPARQL tutorial** (the well-known one, works verbatim against any Wikibase) — https://www.wikidata.org/wiki/Wikidata:SPARQL_tutorial
- **WDQS (Wikidata Query Service)** — https://www.mediawiki.org/wiki/Wikidata_Query_Service
- **MediaWiki API** — https://www.mediawiki.org/wiki/API:Main_page
- **Wikibase API** (entity CRUD: `wbgetentities`, `wbeditentity`, …) — https://www.wikidata.org/w/api.php
- **wikibase-docker** (reference containerized deployment, same components) — https://github.com/wmde/wikibase-docker

## Notes

- **Decisions** (architecture/design choices, ADR-style): see `decisions/`
  — `opaque-id.md` (entity IDs stay opaque Q/P), `ontology-alignment.md` (mirror
  properties + equivalence mappings, rejected storage-injection), `raw-rdf-in-blazegraph.md`
  (two-worlds: curated entities + native RDF).
- **Overall plan → GitHub issue #6**: the v1 plan (fresh-instance bootstrap +
  EmbeddableContent + WikibaseCitation) lives in
  [**Ron-RONZZ-org/ronzz-wikibase#6**](https://github.com/Ron-RONZZ-org/ronzz-wikibase/issues/6)
  and supersedes the earlier separate proposals/designs (#1–#5, closed Aug 16 2026).
  Local `proposals/` was removed Aug 16 2026 after migration to GitHub; decisions
  stay here in `decisions/`.
- RAM: WDQS (Blazegraph Java) uses ~1.3 GiB RSS — this was the ~1.6 GiB freed when
  stopped Aug 2026.
- Backups: covered by the OCI boot-volume policy (`daily-5d`) — crash-consistent
  only; MySQL `wikibase` DB may want a `mysqldump` cron for strict consistency.
- SPARQL queries via nginx have a 300 s proxy timeout (`proxy_read_timeout`).

## Translation & multilingual content (Aug 19 2026)

**Model: static LLM-maintained copies — no translation markup.** Decision and rationale:
`docs/decisions/static-llm-translation.md` (Aug 19 2026).

- **Pages are plain wikitext.** No `<languages/>`, `<translate>`, `<tvar>` or `<!--T:n-->`
  markers anywhere. Translation markup was tried on the migrated `Help:Contributing` pages
  (Aug 18-19) and abandoned: the unit/tvar noise made routine editing impractical on pages
  still being authored (`Help:Contributing/code` alone got 15 edits in 19 days, unit numbers
  drifted within days).
- **fr/eo versions are static subpages** (`Help:Contributing/code/fr`), produced by LLM
  translation of the clean wikitext against the glossary
  (`content-creation/glossary.md`). Each copy carries a
  `{{Translation|lang=fr|based-on=<revid>|date=…}}` banner; source pages link their copies
  with a `{{Languages}}` bar.
- **Drift signaling**: the `based-on` revision on each copy + the convention that editing an
  EN page regenerates its fr/eo copies in the same session (`content-creation/AGENTS-translation.md`).
  The old unit-level "outdated" flags are gone — see the ADR.
- **Reader side**: browser machine translation covers fr in all browsers; **eo is not
  auto-translatable in Firefox/Safari** (Chrome only), so the static eo copies are the only
  reliable eo path.
- **Entity terms are untouched**: labels/descriptions/aliases stay Wikibase-native
  multilingual; ULS remains active for language selection.
- **Translate extension state**: installed, **inert** (zero marked pages; `Special:PageTranslation`
  empty). Historical quirks apply only if it is re-enabled (re-enable = re-mark pages via
  `action=markfortranslation`, `pagetranslation` right, sysop):
  - Render-job quirk (fixed Aug 18 2026): unit saves push `RenderTranslationPageJob`s that can
    hang in a claimed state. Fix: `$wgJobRunRate = 0;` + a cron `runJobs.php` every 5 min
    (still in place; inert without marked pages). Stuck rows:
    `UPDATE job SET job_token='' WHERE job_cmd='RenderTranslationPageJob';`
- **Cleanup record (Aug 19 2026)**: all 10 marked pages unmarked and stripped to plain
  wikitext; 212 `Translations:` unit pages + auto-generated `/en` subpages deleted;
  `Help:Contributing/languages/translationAdmin` folded into
  `Help:Contributing/languages/translator`.
- **Bot password for MCP/automation**: `SeedBot@MCP` (created Aug 18 2026 via
  `maintenance/createBotPassword.php`; grants updated Aug 19 2026 to
  `basic,createeditmovepage,editpage,uploadeditmovefile` — `delete` and `pagetranslation` are
  **not** covered, so page deletion and page-translation management must run as the **full
  SeedBot account** server-side; see the ops doc).
  Note: bot passwords have a restricted charset (`[0-9a-w]`, base-32-ish) — a
  `secrets.token_urlsafe()` password is *invalid* and login silently fails.
  Credentials: ops doc (server) / MCP config file (see below).

## MCP server for the wiki (Aug 18 2026)

- **ProfessionalWiki MediaWiki-MCP-Server** (`@professional-wiki/mediawiki-mcp-server`,
  v0.17) exposes read tools (`get-page`, `search-page`, `whoami`,
  `wikibase-search-entities`, `wikibase-get-entity`) and write tools
  (`create-page`, `update-page`, `wikibase-edit-entity`, `wikibase-add-statement`, …).
- Config + bot credentials live **outside this repo** at
  `~/.config/mediawiki-mcp/ronzz-wikibase.json` on the workstation;
  opencode entry in `~/.config/opencode/opencode.jsonc` (`mcp.mediawiki-mcp-server`,
  `type: local`, `CONFIG` env var).
- **Known limitation**: `wbsearchentities` label search returns nothing for
  non-ID queries — the Wikibase `domains/search` path needs Elasticsearch /
  CirrusSearch, which is **not installed** (pre-existing; ID search like `Q1`
  works).
