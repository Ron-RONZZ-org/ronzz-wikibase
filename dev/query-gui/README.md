# query.ronzz.org — WDQS query GUI

The [Wikidata Query Service GUI](https://github.com/wikimedia/wikidata-query-gui)
(query.wikidata.org's frontend) pointed at the instance's WDQS. Deployed
2026-09-01 (see `../logs/wikibase.md` and `RonzzIT:Deployment/Wikibase` §WDQS).

## Layout

- `custom-config.json` — the GUI's runtime config (fetched by the app as
  `./custom-config.json`, merged over `default-config.json`). `api.sparql.uri`
  is relative (`/sparql`, same-origin); `api.wikibase.uri` points at the wiki's
  `w/api.php` (the GUI appends `origin=*` itself, anon-read OK); `api.examples`
  reads the public wiki page `SPARQL examples`; `prefixes` feeds the editor's
  autocomplete (it inserts the PREFIX declarations into the query).
- `query.ronzz.org.conf` — nginx site: static `build/` + a **read-only**
  `/sparql` proxy to `127.0.0.1:9999/bigdata/namespace/wdq/sparql`
  (blocks `?update=` and `Content-Type: application/sparql-update`, rate
  limited 10 r/s, CORS `*`).

## Build & deploy — zero-touch via CI

Both frontends are built and deployed by the GitHub Actions workflow
[`.github/workflows/frontends-deploy.yml`](../../.github/workflows/frontends-deploy.yml):

- **Trigger**: push to `main` touching `custom-config.json`,
  `query-builder.env.production`, `patches/**`, `deploy-rsync-gate.sh` or the
  workflow itself — or `workflow_dispatch` for a manual rebuild.
- **Build** happens on GitHub runners (node 22, x86_64): GUI job checks out
  `wikimedia/wikidata-query-gui@dd58b26`, applies the patches, `npm ci
  --ignore-scripts` + `grunt only_build`, copies `custom-config.json` into
  `build/`; builder job checks out `wikimedia/wikidata-query-builder@d2b960a`,
  writes `.env.production` from `query-builder.env.production`, `npm ci
  --ignore-scripts` + `vite build --base=/querybuilder/`.
- **Deploy** job rsyncs both artifacts over SSH as the least-privileged
  `deploy` user to `/var/www/wdqs/query-gui/build/` and
  `/var/www/wdqs/query-builder/dist/` (`-rlptDz --delete`; no chown attempts).
  Static files only — no nginx reload needed.
- **Post-deploy gate**: the deploy job then runs the Playwright UX E2E
  (`tests/e2e/run_query_gui_ux_e2e.mjs`, read-only) against
  `https://query.ronzz.org` — ctrl+space autocomplete, run-a-query with
  instance results, examples/query-builder links, zero console errors. Red =
  roll back from the nightly snapshot
  (`/var/backups/wdqs-frontends/{gui,builder}/<YYYYMMDD>/`).

The server never builds anything anymore. The old on-server GUI build steps
(section below) are retained for emergency offline rebuilds only.

### Local patches (applied by CI, in this order)

1. `patches/0001-query-helper-parse-noise.patch` — downgrades the Query
   Helper's expected parse failures from `console.error` to `console.debug`
   (mid-typing re-parses throw; the noise hid real errors in devtools).
2. `patches/0002-entity-autocomplete.patch` — **entity/property autocomplete
   for non-wikidata instances**. Upstream `RdfNamespaces.ENTITY_TYPES` is
   hardcoded to wikidata.org URIs, so a configured instance's prefixes (this
   repo's `custom-config.json` `prefixes`) were filtered out of the entity
   search map and ctrl+space silently produced nothing. The patch adds
   `RdfNamespaces.addEntityTypes()`/`getEntityTypeForUrl()` (path-shape
   classifier, origin-agnostic), wires it from `init.js` after
   `addPrefixes()`, and makes the toolbar "Add prefixes" button emit the
   standard names resolved through the config-merged `ALL_PREFIXES` (it used
   to insert hardcoded wikidata.org `PREFIX` lines — a query that returns
   nothing on this instance).

**After every `git reset` re-apply BOTH patches** (see the emergency block).

### The `deploy` user and the rsync gate

- **User**: `deploy` on ronzz-linux-server-2 — locked password, no sudo,
  owns *only* the two dist dirs + `/var/backups/wdqs-frontends`.
- **Key**: ed25519, held only in the GitHub secret `DEPLOY_SSH_KEY` (never
  committed; `gh secret set DEPLOY_SSH_KEY --repo Ron-RONZZ-org/ronzz-wikibase
  --body-file <keyfile>` to set/rotate). The server pins the key's
  `authorized_keys` entry to `restrict,command="/usr/local/sbin/deploy-rsync-gate"`.
- **Gate** (`deploy-rsync-gate.sh`, mirrored here): allows rsync PUSH only
  into the three allowed paths; rejects interactive shells, arbitrary
  commands, rsync pulls (`--sender`), non-absolute destinations and `..`
  traversal. Verified live 2026-09-01 (push-to-/tmp, pull, traversal, shell —
  all refused with exit 12; allowed push OK).
- **sshd**: `/etc/ssh/sshd_config.d/99-deploy.conf` (Match User deploy: no
  password/tty/forwarding).
- **Blast radius if the key leaks**: write access to the two served dirs
  (defacement/XSS on query.ronzz.org) + the backup dir — nothing else (no
  wiki, no DB, no root). GitHub secrets are never exposed to fork PRs.
- **Rollback**: nightly snapshot cron (03:05 UTC) mirrors both live dirs into
  `/var/backups/wdqs-frontends/{gui,builder}/<YYYYMMDD>/` (14-day retention);
  manual rollback as ronzz: `rsync -a --delete
  /var/backups/wdqs-frontends/gui/<date>/ /var/www/wdqs/query-gui/build/`.

### Emergency offline rebuild (on the server, as ronzz)

```bash
cd /var/www/wdqs/query-gui
git fetch -q origin && git reset -q --hard origin/master   # pin: dd58b26 (2025-02-24)
for p in 0001-query-helper-parse-noise.patch 0002-entity-autocomplete.patch; do
  curl -sfLo /tmp/$p \
    https://raw.githubusercontent.com/Ron-RONZZ-org/ronzz-wikibase/main/dev/query-gui/patches/$p
  git apply /tmp/$p
done
rm -rf node_modules build
HOME=/tmp npm ci --no-audit --no-fund --ignore-scripts   # arm64: skip scripts (puppeteer)
./node_modules/.bin/grunt only_build   # NOT `grunt build` (auto_install prunes devDeps)
cp custom-config.json build/custom-config.json
```

Verify after any deploy: `curl -s -o /dev/null -w '%{http_code}' https://query.ronzz.org/` (200);
`curl -sG --data-urlencode 'query=SELECT * WHERE { ?s ?p ?o } LIMIT 1' --data 'format=json' https://query.ronzz.org/sparql`
(JSON); `curl -s -o /dev/null -w '%{http_code}' 'https://query.ronzz.org/sparql?update=DELETE%20WHERE%20%7B%3Fs%20%3Fp%20%3Fo%7D'` (403).
The frontends-deploy workflow runs these automatically as the post-deploy
gate (the Playwright UX E2E, see `tests/e2e/run_query_gui_ux_e2e.mjs`).

## E2E

- **`tests/e2e/run_query_gui_e2e.py`** (Python stdlib, read-only): SPARQL
  correctness with bare + explicit prefixes, the `/sparql` read-only guard,
  the `wbsearchentities` CORS contract, the runtime config merge, the
  examples page, the Query Builder. Production: everything; CI: the SPARQL +
  API parts against the dev stack.
- **`tests/e2e/run_query_gui_ux_e2e.mjs`** (Playwright, read-only): the
  browser UX — ctrl+space entity/property autocomplete, keyword hints,
  run-a-query with instance results, examples/query-builder links, zero
  console errors. This is the frontends-deploy post-deploy gate; run it
  locally too whenever these frontends are touched:

  ```bash
  # from a directory where `playwright` is installed (npm scratch dir / ~/node_modules):
  node /path/to/ronzz-wikibase/tests/e2e/run_query_gui_ux_e2e.mjs --base-url https://query.ronzz.org
  # a local GUI build (served anywhere) can be smoked the same way with --base-url.
  ```

## Query Builder (wikimedia/wikidata-query-builder)

Visual, form-based query building (no SPARQL needed) for users of the
instance — Wikidata's own Query Builder, pointed at RonzzWiki. Served at
`https://query.ronzz.org/querybuilder/` (nginx `location /querybuilder/` →
`/var/www/wdqs/query-builder/dist/`; the GUI's `api.query-builder.server`
config links to it).

- **Config**: Vite 2 + Vue 3 app; all values are build-time env vars in
  `.env.production` (NOT runtime — rebuild to change):
  `VUE_APP_WIKIBASE_API_URL=https://wikibase.ronzz.org/w/api.php`,
  `VUE_APP_QUERY_SERVICE_URL=https://query.ronzz.org/`,
  `VUE_APP_QUERY_SERVICE_EMBED_URL=https://query.ronzz.org/embed.html`,
  `VUE_APP_SUBCLASS_PROPERTY_MAP={"default": "P31"}` (our subclass-of property —
  upstream defaults to P279). Omit the statsv/shortener/privacy vars (features
  hide themselves).
- **Why no source patch**: `QueryBuilderSparqlGenerator.getString()` strips all
  `PREFIX` lines from the generated query, so it ships bare `wd:`/`wdt:`/`p:`
  prefixes that resolve via the store's `prefixes.conf` (the 2026-09-01 prefix
  fix) — no wikidata.org URIs leak into queries. `wikibase:label` is the
  fixed `wikiba.se` URI, identical everywhere. Verified live: generated
  `?item p:P1 ?s. ?s (ps:P1/(wdt:P31*)) wd:Q6` → 28 people.
- **Build & deploy**: via CI (`frontends-deploy.yml`) — the workflow writes
  `.env.production` from `query-builder.env.production` and builds with
  `vite build --base=/querybuilder/`; no builds on the production box.
  ⚠️ `alias` + `try_files` are incompatible in nginx — the location uses bare
  `alias` + `index` (the builder is a single-view app with no router, so no
  SPA fallback is needed; the first attempt with `try_files` 404'd every
  asset).

## Known limitations / follow-ups

- **Read-only residual gap**: nginx cannot inspect POST form-urlencoded bodies,
  so `POST ... update=...` (form) is not blocked — mitigated by the rate limit.
  Blast radius: the derived, re-syncable graph (not the canonical store).
  Options if ever needed: `com.bigdata.rdf.sail.update=false` in
  `RWStore.properties` (would also break the incident-5 LOAD procedure), or an
  nginx module with body inspection.
- **Query Helper noise — FIXED (local patch)**: upstream `App.js`'s
  `_drawQueryHelper` catch logs every sparqljs parse failure via
  `window.console.error` — but parse failures are an *expected* part of
  editing (the helper re-parses the query debounced 1.5 s after edits, so any
  half-typed text throws). The patch (`patches/0001-query-helper-parse-noise.patch`)
  downgrades parse errors to `console.debug`, keeping `console.error` for real
  failures. Non-functional either way (the error is caught; the only effect is
  the intended "standardize format" button disabling while the query doesn't
  parse) — but the spam hid genuine errors in devtools. Re-apply after every
  `git reset` (see build steps). Verified live 2026-09-01: 0 console errors
  while typing partial queries; the helper still draws for parseable ones.
- **Entity autocomplete — FIXED (local patch)**: ctrl+space produced no hint
  popup at all on this instance (upstream `RdfNamespaces.ENTITY_TYPES` only
  knows wikidata.org URIs, so the configured ronzz.org prefixes were filtered
  out of the entity search map and the Rdf hint rejected). Fixed by
  `patches/0002-entity-autocomplete.patch` (config-merged entity types + a
  config-aware "Add prefixes" toolbar button). Verified with the Playwright
  UX E2E: `wd:` + ctrl+space lists the instance's entities, `wdt:` its
  properties.
- **Builds run on CI** (2026-09-01): the GUI build previously ran on the
  production box; that load is gone (the 2026-09-01 memory-stall wedge that
  needed an OCI hard restart was the trigger for moving builds off the
  server). Emergency on-server rebuild steps are retained above.
- **Query Builder — DEPLOYED (2026-09-01)**: the navbar "Query Builder" button
  + the GUI config now point at the instance's own builder at
  `https://query.ronzz.org/querybuilder/` (previously outbound to
  query.wikidata.org). See the [Query Builder](#query-builder) section below.
- **Upstream**: no releases — master only, pinned commit above; rebuild after
  upstream merges (mirrors the mediawiki-mcp-server convention).
