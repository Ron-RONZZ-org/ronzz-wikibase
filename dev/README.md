# ronzz-wikibase dev/CI stack

Reference-deployment dev instance (issue #6, D6) for validating the
integration layer: extension loading, seed, D1 importers, E2E + XSS suites.
Runs the same component versions as production where the wmde images allow
(MW 1.46, WDQS 0.3.156). **WDQS/Blazegraph is ON** — this is for CI
(16 GB runners) or machines with RAM to spare (~2.5 GiB total).

## Quick start (local)

```bash
mkdir -p seed/generated
docker compose -f dev/docker-compose.ci.yml up -d
# wait until the wiki answers:
for i in $(seq 1 120); do curl -sf -o /dev/null http://127.0.0.1:8082/api.php && break; sleep 2; done

# 1. D1 importers (vocabulary via maintenance scripts)
docker compose -f dev/docker-compose.ci.yml exec -T mediawiki \
  php maintenance/run.php extensions/EmbeddableContent/maintenance/importVocabulary.php --type=property
docker compose -f dev/docker-compose.ci.yml exec -T mediawiki \
  php maintenance/run.php extensions/EmbeddableContent/maintenance/importVocabulary.php --type=class
docker compose -f dev/docker-compose.ci.yml exec -T mediawiki \
  php maintenance/run.php extensions/EmbeddableContent/maintenance/importVocabulary.php --type=language

# 2. Seed: dogfood entities + config emission into the mounted LocalSettings.d
python3 -m seed.seed_instance \
  --user CIAdmin --password ci-admin-pass-2026 \
  --api-url http://127.0.0.1:8082/api.php \
  --base-url http://127.0.0.1:8082 \
  --sparql-url http://127.0.0.1:9999/bigdata/namespace/wdq/sparql \
  --config-out seed/generated/ronzz-wikibase.php \
  --ids-out seed/generated/ids.json \
  --only=properties,classes,languages,dogfood,config

# 3. Restart the wiki so the emitted config map is loaded
docker compose -f dev/docker-compose.ci.yml restart mediawiki
# wait for api.php again

# 4. E2E acceptance + XSS
python3 tests/e2e/run_e2e.py check --api-url http://127.0.0.1:8082/api.php \
  --base-url http://127.0.0.1:8082 --sparql-url http://127.0.0.1:9999/bigdata/namespace/wdq/sparql \
  --quote "$(jq -r .dogfood.quotation seed/generated/ids.json)" \
  --code "$(jq -r .dogfood.code seed/generated/ids.json)" \
  --math "$(jq -r .dogfood.math seed/generated/ids.json)" \
  --instance-of "$(jq -r '."properties"."instance of"' seed/generated/ids.json)" \
  --quotation-class "$(jq -r '."classes"."quotation content"' seed/generated/ids.json)"
python3 tests/e2e/run_e2e.py xss --api-url http://127.0.0.1:8082/api.php \
  --base-url http://127.0.0.1:8082 --user CIAdmin --password ci-admin-pass-2026

# Clean reset (keeps nothing):
docker compose -f dev/docker-compose.ci.yml down -v
```

## Notes

- The extensions are bind-mounted read-only from the repo — code edits are
  picked up by `docker compose restart mediawiki` (no image rebuild).
- `seed/generated/` is gitignored; `mkdir -p` it before `up -d` so the
  `LocalSettings.d` mount is writable by your user.
- The `extra-install.sh` hook appends the extension loads to the image's
  generated `LocalSettings.php` on first boot; the config require is guarded
  so the wiki boots before the seed runs.
- The SPARQL acceptance check needs the WDQS updater to sync — allow a couple
  of minutes (the E2E/verify scripts retry).
- RAM: the full stack (incl. Blazegraph) needs ~2.5 GiB. On a tight machine,
  run only `mediawiki` + `mysql` (drop the `wdqs*` services) and skip the
  SPARQL check.
