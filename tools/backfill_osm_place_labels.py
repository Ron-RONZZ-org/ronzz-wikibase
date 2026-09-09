#!/usr/bin/env python3
"""tools/backfill_osm_place_labels.py — give existing person OSM place
statements their human-readable display label (osm-places follow-up).

The AddPerson/UpdatePerson flows now capture the human-readable display
name of a picked/auto-matched place and store it as a parallel string
statement ("place of birth (label)" / "place of death (label)") next to the
OSM external-id ("place of birth (OSM)" / "place of death (OSM)"), which
Template:Person renders as the row text (falling back to the raw id when no
label is stored). Items created BEFORE this change have only the OSM id.
This tool backfills those labels via the Nominatim REVERSE endpoint
(https://nominatim.openstreetmap.org/reverse — the id → display name
lookup, distinct from the search endpoint the forms use):

  for each person item carrying a place-of-birth/death OSM statement
  WITHOUT the parallel label statement:
    1. reverse-geocode the OSM id (node|way|relation/<id> → display_name,
       one request per id, paced at 1 req/s per Nominatim's usage policy);
    2. add the label statement on the label property (idempotent — skip
       when the label statement already exists).

Idempotent and self-verifying: re-running skips items that already have a
label statement; --verify re-checks the count of OSM statements still
missing a label afterwards.

Usage (mirrors the seed/E2E credential pattern):
  python3 tools/backfill_osm_place_labels.py \
      --base-url https://wikibase.ronzz.org \
      --sparql-url http://127.0.0.1:9999/bigdata/namespace/wdq/sparql \
      --user SeedBot --password-file seed/.seedbot.pass [--dry-run]

Python stdlib only (AGENTS.md: no pip dependencies).
"""

import argparse
import json
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

# The tool lives in tools/ and reuses the seed's API client.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "seed"))
from wikibase_api import WikibaseApi, WikibaseApiError  # noqa: E402

OSM_PROPERTY_LABELS = {
    "place of birth (OSM)": "place of birth (label)",
    "place of death (OSM)": "place of death (label)",
}
INSTANCE_OF_LABEL = "instance of"
PERSON_CLASS_LABEL = "person"
REVERSE_API = "https://nominatim.openstreetmap.org/reverse"
USER_AGENT = "ronzz-wikibase-backfill/1.0 (place labels)"

SPARQL_PREFIXES = (
    "PREFIX wd: <https://wikibase.ronzz.org/entity/>\n"
    "PREFIX p: <https://wikibase.ronzz.org/prop/>\n"
    "PREFIX ps: <https://wikibase.ronzz.org/prop/statement/>\n"
)


def find_entity_by_label(api: WikibaseApi, label: str, entity_type: str, language: str) -> str | None:
    """Exact-label match via wbsearchentities (same contract as the seed's find())."""
    wanted = label.strip().lower()
    for hit in api.search_entities(label, entity_type, language):
        match_text = str(hit.get("match", {}).get("text", "")).strip().lower()
        hit_label = str(hit.get("label", "")).strip().lower()
        if match_text == wanted or hit_label == wanted:
            return hit.get("id")
    return None


def sparql_http_get(endpoint: str, query: str) -> list[dict]:
    url = endpoint + ("" if endpoint.endswith("?") else "?") + urllib.parse.urlencode(
        {"query": query, "format": "json"}
    )
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=60) as resp:
        payload = json.loads(resp.read().decode("utf-8", "replace"))
    return payload.get("results", {}).get("bindings", [])


def reverse_geocode(osm_value: str) -> str | None:
    """Nominatim reverse lookup of a node|way|relation/<id> value → the
    display name (the human-readable label). None when the id cannot be
    parsed or the endpoint rejects the lookup. One request per call — pace
    at the caller's 1 req/s."""
    parts = osm_value.split("/")
    if len(parts) != 2 or parts[0] not in ("node", "way", "relation") or not parts[1].isdigit():
        return None
    params = urllib.parse.urlencode({
        "osm_type": parts[0],
        "osm_id": parts[1],
        "format": "jsonv2",
        "accept-language": "en",
    })
    request = urllib.request.Request(
        f"{REVERSE_API}?{params}", headers={"User-Agent": USER_AGENT}
    )
    with urllib.request.urlopen(request, timeout=30) as resp:
        payload = json.loads(resp.read().decode("utf-8", "replace"))
    name = str(payload.get("display_name", "")).strip()
    return name if name else None


def osm_ids_missing_labels(args, api: WikibaseApi,
                           osm_props: dict[str, str], label_props: dict[str, str],
                           instance_of_id: str, person_class_id: str) -> list[tuple[str, str, str, str]]:
    """(item_id, osm_property, osm_value, label_property) for every person
    item whose OSM place statement has no parallel label statement."""
    out: list[tuple[str, str, str, str]] = []
    for osm_label, osm_prop in osm_props.items():
        label_prop = label_props[osm_label]
        # Persons with the OSM statement but WITHOUT the label statement.
        query = SPARQL_PREFIXES + f"""
SELECT DISTINCT ?item ?osm WHERE {{
  ?item p:{instance_of_id} ?cls .
  ?cls ps:{instance_of_id} wd:{person_class_id} .
  ?item p:{osm_prop} ?st .
  ?st ps:{osm_prop} ?osm .
  FILTER NOT EXISTS {{
    ?item p:{label_prop} ?lst .
    ?lst ps:{label_prop} ?lbl .
  }}
}} ORDER BY ?item LIMIT 1000"""
        try:
            rows = sparql_http_get(args.sparql_url, query)
        except Exception as exc:  # noqa: BLE001 — network/parse failure is diagnosable
            raise SystemExit(f"SPARQL query failed for {osm_label}: {exc}") from exc
        for row in rows:
            item_id = row["item"]["value"].rstrip("/").rsplit("/", 1)[-1]
            value = row["osm"]["value"]
            if item_id.startswith("Q") and value:
                out.append((item_id, osm_label, value, label_prop))
    return out


def backfill(args) -> int:
    language = args.lang or "en"
    api = WikibaseApi(args.base_url, user=args.user, password=args.password)
    api.login()

    instance_of_id = find_entity_by_label(api, INSTANCE_OF_LABEL, "property", language)
    person_class_id = find_entity_by_label(api, PERSON_CLASS_LABEL, "item", language)
    if not instance_of_id or not person_class_id:
        raise SystemExit("could not resolve instance-of / person class — vocabulary imported?")

    osm_props: dict[str, str] = {}
    label_props: dict[str, str] = {}
    for osm_label, label_label in OSM_PROPERTY_LABELS.items():
        osm_prop = find_entity_by_label(api, osm_label, "property", language)
        label_prop = find_entity_by_label(api, label_label, "property", language)
        if not osm_prop or not label_prop:
            print(f"⚠️ vocabulary incomplete for {osm_label} (osm={osm_prop}, label={label_prop}) — skipping")
            continue
        osm_props[osm_label] = osm_prop
        label_props[osm_label] = label_prop
    if not osm_props:
        raise SystemExit("no OSM place property pair resolvable — is the vocabulary imported?")

    missing = osm_ids_missing_labels(
        args, api, osm_props, label_props, instance_of_id, person_class_id
    )
    if not missing:
        print("no person OSM place statement missing its label — nothing to backfill")
        return 0
    print(f"{len(missing)} OSM place statement(s) missing their display label:")

    changed = 0
    last_request = 0.0
    for item_id, osm_label, osm_value, label_prop in missing:
        print(f"  {item_id}: {osm_label} = {osm_value!r}")
        if args.dry_run:
            print(f"    [dry-run] would reverse-geocode and add the label statement")
            continue
        # Pace: Nominatim's usage policy — max 1 request/second.
        wait = 1.0 - (time.monotonic() - last_request)
        if wait > 0:
            time.sleep(wait)
        last_request = time.monotonic()
        try:
            display_name = reverse_geocode(osm_value)
        except Exception as exc:  # noqa: BLE001
            print(f"    ⚠️ reverse lookup failed for {osm_value}: {exc}")
            continue
        if display_name is None:
            print(f"    ⚠️ no display name for {osm_value} — skipped (id may be stale)")
            continue
        # Idempotent: add_claims is additive, and the SPARQL filter already
        # excluded items carrying the label.
        api.add_claims(
            item_id,
            {label_prop: [{
                "mainsnak": {
                    "snaktype": "value",
                    "property": label_prop,
                    "datavalue": {"value": display_name, "type": "string"},
                },
                "type": "statement",
                "rank": "normal",
            }]},
            args.summary_prefix + f"backfill {osm_label} display label",
        )
        changed += 1
        print(f"    added label {display_name!r}")

    print(f"\nbackfilled {changed} item(s)" + (" (dry-run — no writes)" if args.dry_run else ""))
    if args.dry_run:
        print("re-run without --dry-run to apply")
    return 0


def verify(args) -> int:
    """SPARQL recount of OSM place statements still missing a label."""
    api = WikibaseApi(args.base_url, user=args.user, password=args.password)
    api.login()
    instance_of_id = find_entity_by_label(api, INSTANCE_OF_LABEL, "property", args.lang or "en")
    person_class_id = find_entity_by_label(api, PERSON_CLASS_LABEL, "item", args.lang or "en")
    if not instance_of_id or not person_class_id:
        raise SystemExit("could not resolve instance-of / person class — vocabulary imported?")

    total = 0
    for osm_label, label_label in OSM_PROPERTY_LABELS.items():
        osm_prop = find_entity_by_label(api, osm_label, "property", args.lang or "en")
        label_prop = find_entity_by_label(api, label_label, "property", args.lang or "en")
        if not osm_prop or not label_prop:
            continue
        query = SPARQL_PREFIXES + f"""
SELECT (COUNT(DISTINCT ?st) AS ?n) WHERE {{
  ?item p:{instance_of_id} ?cls .
  ?cls ps:{instance_of_id} wd:{person_class_id} .
  ?item p:{osm_prop} ?st .
  ?st ps:{osm_prop} ?osm .
  FILTER NOT EXISTS {{
    ?item p:{label_prop} ?lst .
    ?lst ps:{label_prop} ?lbl .
  }}
}}"""
        rows = sparql_http_get(args.sparql_url, query)
        n = int(rows[0]["n"]["value"]) if rows and "n" in rows[0] else 0
        print(f"OSM place statements missing their label ({osm_label}): {n}")
        total += n
    return 0 if total == 0 else 1


def main() -> int:
    parser = argparse.ArgumentParser(description="Backfill person OSM place display labels")
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    parser.add_argument("--sparql-url", required=True)
    parser.add_argument("--user", default="SeedBot")
    parser.add_argument("--password-file", help="file containing the bot password (0600)")
    parser.add_argument("--lang", default="en")
    parser.add_argument("--summary-prefix", default="Tool: ")
    parser.add_argument("--dry-run", action="store_true", help="plan only, no writes")
    parser.add_argument("--verify", action="store_true", help="only recount OSM statements missing labels")
    args = parser.parse_args()
    if not args.password_file or not Path(args.password_file).exists():
        raise SystemExit(f"password file not found: {args.password_file}")
    with open(args.password_file, encoding="utf-8") as f:
        args.password = f.read().strip()
    if not args.password:
        raise SystemExit("empty password file")
    if args.verify:
        return verify(args)
    return backfill(args)


if __name__ == "__main__":
    raise SystemExit(main())
