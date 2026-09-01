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

## Build & deploy (server ronzz-linux-server-2, as ronzz)

```bash
cd /var/www/wdqs/query-gui
git fetch -q origin && git reset -q --hard origin/master   # pin: dd58b26 (2025-02-24)
# apply the local patch (upstream App.js logs expected sparqljs parse failures
# at error level — see "Known limitations"); re-apply after every git reset:
curl -sfLo /tmp/query-helper-noise.patch \
  https://raw.githubusercontent.com/Ron-RONZZ-org/ronzz-wikibase/main/dev/query-gui/patches/0001-query-helper-parse-noise.patch
git apply /tmp/query-helper-noise.patch
# rsync this dir's custom-config.json over the clone's (or keep the server copy)

# node 22: puppeteer's postinstall fails on arm64 (no Chromium build) — skip scripts:
rm -rf node_modules build
HOME=/tmp npm ci --no-audit --no-fund --ignore-scripts

# IMPORTANT: use `grunt only_build`, NOT `grunt build`:
# `build` runs `auto_install` = `npm install --production`, which prunes the
# devDependencies mid-build and breaks grunt-usemin ("Cannot find module
# ../lib/flow"). only_build skips it and leaves node_modules intact.
./node_modules/.bin/grunt only_build
cp custom-config.json build/custom-config.json   # copy:release ships only default-config.json

sudo systemctl reload nginx   # after editing /etc/nginx/sites-available/query.ronzz.org.conf
```

Verify: `curl -s -o /dev/null -w '%{http_code}' https://query.ronzz.org/` (200);
`curl -sG --data-urlencode 'query=SELECT * WHERE { ?s ?p ?o } LIMIT 1' --data 'format=json' https://query.ronzz.org/sparql`
(JSON); `curl -s -o /dev/null -w '%{http_code}' 'https://query.ronzz.org/sparql?update=DELETE%20WHERE%20%7B%3Fs%20%3Fp%20%3Fo%7D'` (403).

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
- **Server resources**: the GUI build runs on the 11 GiB production box next
  to Blazegraph (`-Xmx4g` + large native buffers), the WDQS updater and
  MariaDB. Check `free -h` before rebuilding — a rebuild during a memory
  stall wedged the box on 2026-09-01 (all userspace unresponsive; hard
  restart from the OCI console). Keep rebuilds short and quiet.
- **Query Builder link**: the navbar "Query Builder" button + banner point at
  query.wikidata.org's builder (default-config). Deploying
  wikimedia/WikidataQueryBuilder against this instance would make it usable;
  until then it is outbound-only.
- **Upstream**: no releases — master only, pinned commit above; rebuild after
  upstream merges (mirrors the mediawiki-mcp-server convention).
