# Wikibase — wikibase.ronzz.org

Self-hosted **Wikibase** (structured-data wiki, the software behind Wikidata) on
`ronzz-linux-server-2` (158.178.193.231). Re-enabled Aug 15 2026.

## Stack

| Component | Version / Detail | Location |
|-----------|------------------|----------|
| MediaWiki | 1.46.0 (php-fpm 8.3.6) | `/var/www/wikibase/` |
| Wikibase (repo) | source checkout (RELEASE-NOTES up to 1.44) | `/var/www/wikibase/extensions/Wikibase/` |
| Skins | **Vector** (default, REL1_46 git clone) + **Timeless** (selectable) | `/var/www/wikibase/skins/{Vector,Timeless}/` |
| EmbeddableContent (D3 + issue #7) | extension (quotation/code/math embeds, `Special:AddQuotation`/`AddCodeSnippet`/`AddMath`, `Special:AddPerson`/`AddSource`/`AddCollective`) | `/var/www/wikibase/extensions/EmbeddableContent/` |
| WikibaseCitation (D4) | extension (`action=citation`, citeproc-php) | `/var/www/wikibase/extensions/WikibaseCitation/` |
| SyntaxHighlight_GeSHi (Aug 18 2026) | extension (Pygments code highlighting + built-in copy button on wiki pages; official REL1_46) | `/var/www/wikibase/extensions/SyntaxHighlight_GeSHi/` |
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

> **For editors**: see the **`contribution-guide.md`** (same folder) for
> step-by-step instructions on creating properties/items, statements, API bulk editing,
> and house rules. On-wiki help lives at `https://wikibase.ronzz.org/wiki/Help:Contributing`
> (incl. **`Help:Contributing/code`** for code-block content, added Aug 18 2026).
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

As of the **Aug 17 2026 v1 deployment**: seeded vocabulary — **27 properties** (incl. 8 ExternalId authority properties with formatter URLs: Wikidata ID, ORCID, VIAF, ISNI, DOI, ISBN-13, OpenAlex Work ID, PubMed ID; plus citation metadata: given/family name, published in, publisher, page(s), volume, issue) and **13 classes** (quotation content, code snippet, mathematical expression, programming language, person, organization, group of humans, book, scholarly article, website, song, film, video), **80 Pygments language items**, and 5 dogfood entities (person/book/quotation/code/math). The seed's self-verification is green; `Special:AddPerson` / `AddSource` / `AddCollective` import entities from external authorities (Wikidata hub + dblp SPARQL, OpenAlex, Crossref, Open Library, ORCID).

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
