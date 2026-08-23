#!/usr/bin/env python3
"""tools/migrate_string_publishers.py — one-off migration of the legacy
STRING publisher statements to entity statements (issue #35).

The instance's publisher is entity-only since issue #35: the Add* forms
write publisher (entity) (wikibase-item), never the legacy string
"publisher" property. This tool converts existing string publisher
statements:

  for each item carrying a string publisher statement:
    1. find-or-create the publisher ITEM (exact label match, else create,
       classified instance of organization);
    2. add an entity publisher statement on the entity-typed property
       (skip when one already exists);
    3. remove the legacy string statement(s).

Idempotent and self-verifying: after the run, a SPARQL recount of string
publisher statements must be zero (run --verify to re-check later).

Usage (mirrors the seed/E2E credential pattern):
  python3 tools/migrate_string_publishers.py \
      --base-url https://wikibase.ronzz.org \
      --sparql-url http://127.0.0.1:9999/bigdata/namespace/wdq/sparql \
      --user SeedBot --password-file seed/.seedbot.pass [--dry-run]

Python stdlib only (AGENTS.md: no pip dependencies).
"""

import argparse
import json
import sys
import urllib.parse
import urllib.request
from pathlib import Path

# The tool lives in tools/ and reuses the seed's API client.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "seed"))
from wikibase_api import WikibaseApi, WikibaseApiError  # noqa: E402

LEGACY_PROPERTY_LABEL = "publisher"  # string datatype (P23 on the instance)
ENTITY_PROPERTY_LABEL = "publisher (entity)"  # wikibase-item datatype
INSTANCE_OF_LABEL = "instance of"
ORGANIZATION_CLASS_LABEL = "organization"

SPARQL_PREFIXES = (
    "PREFIX wd: <https://wikibase.ronzz.org/entity/>\n"
    "PREFIX wdt: <https://wikibase.ronzz.org/prop/direct/>\n"
    "PREFIX p: <https://wikibase.ronzz.org/prop/>\n"
    "PREFIX ps: <https://wikibase.ronzz.org/prop/statement/>\n"
)


def sparql_http_get(sparql_url: str, query: str) -> list[dict]:
    """Runs a SPARQL query (GET, format=json) and returns the bindings."""
    url = f"{sparql_url}?query={urllib.parse.quote(query)}&format=json"
    with urllib.request.urlopen(url, timeout=60) as resp:  # noqa: S310 (allowlisted endpoint)
        data = json.load(resp)
    return data.get("results", {}).get("bindings", [])


def find_entity_by_label(api: WikibaseApi, label: str, entity_type: str, language: str) -> str | None:
    """Exact-label match via wbsearchentities (same contract as the seed's find())."""
    wanted = label.strip().lower()
    for hit in api.search_entities(label, entity_type, language):
        match_text = str(hit.get("match", {}).get("text", "")).strip().lower()
        hit_label = str(hit.get("label", "")).strip().lower()
        if match_text == wanted or hit_label == wanted:
            return hit.get("id")
    return None


def find_or_create_publisher(api: WikibaseApi, value: str, language: str, instance_of_id: str,
                             organization_class_id: str, summary_prefix: str) -> str:
    """The publisher ITEM for a string publisher value: existing by exact
    label, else created (labels en/fr) and classified instance of
    organization. Returns the item id."""
    existing = find_entity_by_label(api, value, "item", language)
    if existing:
        print(f"    publisher item exists: {value} ({existing})")
        return existing
    item_id = api.create_item(
        {language: value, "fr": value},
        {language: f"The publisher of the work (imported from a string publisher statement)"},
        summary_prefix + "create publisher item",
    )
    api.add_claims(
        item_id,
        {instance_of_id: [{
            "mainsnak": {
                "snaktype": "value",
                "property": instance_of_id,
                "datavalue": {
                    "value": {
                        "entity-type": "item",
                        "numeric-id": int(organization_class_id[1:]),
                        "id": organization_class_id,
                    },
                    "type": "wikibase-entityid",
                },
            },
            "type": "statement",
            "rank": "normal",
        }]},
        summary_prefix + "classify publisher item",
    )
    print(f"    created publisher item: {value} ({item_id})")
    return item_id


def remove_claim_guids(api: WikibaseApi, guids: list[str], summary: str) -> None:
    """Removes claims by GUID (wbremoveclaims).

    Uses the shared client's request plumbing (session cookies + CSRF) via
    its private _post: the seed client has no wbremoveclaims method, and
    adding one to the tested seed API surface for a one-off tool is not
    worth it — the private call is localized here.
    """
    for guid in guids:
        result = api._post(
            "action=wbremoveclaims",
            token=api.require_csrf(),
            claim=guid,
            summary=summary,
        )
        if "success" not in result:
            raise WikibaseApiError(f"wbremoveclaims failed for {guid}: {result}")


def migrate(args) -> int:
    language = args.lang or "en"
    api = WikibaseApi(args.base_url, user=args.user, password=args.password)
    api.login()

    # Resolve the property ids by label.
    legacy_prop = find_entity_by_label(api, LEGACY_PROPERTY_LABEL, "property", language)
    entity_prop = find_entity_by_label(api, ENTITY_PROPERTY_LABEL, "property", language)
    instance_of_id = find_entity_by_label(api, INSTANCE_OF_LABEL, "property", language)
    organization_class = find_entity_by_label(api, ORGANIZATION_CLASS_LABEL, "item", language)
    if not legacy_prop or not entity_prop:
        raise SystemExit(
            f"could not resolve both publisher properties (legacy={legacy_prop}, entity={entity_prop}) — "
            "is the vocabulary imported?"
        )
    if not instance_of_id or not organization_class:
        raise SystemExit("could not resolve instance-of/organization — is the vocabulary imported?")
    print(f"legacy string publisher property: {legacy_prop}; entity publisher property: {entity_prop}")

    # Find items carrying string publisher statements.
    query = SPARQL_PREFIXES + f"""
SELECT DISTINCT ?item ?publisher WHERE {{
  ?item p:{legacy_prop} ?st .
  ?st ps:{legacy_prop} ?publisher .
}} LIMIT 1000"""
    rows = sparql_http_get(args.sparql_url, query)
    if not rows:
        print("no string publisher statements found — nothing to migrate")
        return 0
    print(f"{len(rows)} string publisher statement(s) to convert:")

    changed = 0
    for row in rows:
        item_id = row["item"]["value"].rstrip("/").rsplit("/", 1)[-1]
        value = row["publisher"]["value"]
        print(f"  {item_id}: publisher = {value!r}")
        claims = api.get_claims(item_id)
        legacy_claims = claims.get(legacy_prop, [])
        if any(claim.get("mainsnak", {}).get("datatype") != "string" for claim in legacy_claims):
            # Only convert plain-string statements; skip anything odd.
            continue
        if args.dry_run:
            print(f"    [dry-run] would add entity publisher {value!r} and remove "
                  f"{len(legacy_claims)} string statement(s)")
            continue

        # 1. find-or-create the publisher item.
        publisher_item = find_or_create_publisher(
            api, value, language, instance_of_id, organization_class,
            args.summary_prefix,
        )
        # 2. add the entity publisher statement (skip when already present).
        entity_claims = claims.get(entity_prop, [])
        entity_ok = any(
            claim.get("mainsnak", {}).get("datavalue", {}).get("value", {}).get("id") == publisher_item
            for claim in entity_claims
        )
        if not entity_ok:
            api.add_claims(
                item_id,
                {entity_prop: [{
                    "mainsnak": {
                        "snaktype": "value",
                        "property": entity_prop,
                        "datavalue": {
                            "value": {
                                "entity-type": "item",
                                "numeric-id": int(publisher_item[1:]),
                                "id": publisher_item,
                            },
                            "type": "wikibase-entityid",
                        },
                    },
                    "type": "statement",
                    "rank": "normal",
                }]},
                args.summary_prefix + "convert string publisher to entity",
            )
        # 3. remove the legacy string statements.
        guids = [c["id"] for c in legacy_claims if c.get("mainsnak", {}).get("datatype") == "string"]
        remove_claim_guids(api, guids, args.summary_prefix + "remove legacy string publisher statement")
        changed += 1
        print(f"    converted {item_id}: entity publisher {publisher_item} + "
              f"{len(guids)} string statement(s) removed")

    print(f"\nmigrated {changed} item(s)" + (" (dry-run — no writes)" if args.dry_run else ""))
    if args.dry_run:
        print("re-run without --dry-run to apply")
    return 0


def verify(args) -> int:
    """SPARQL recount of remaining string publisher statements."""
    api = WikibaseApi(args.base_url, user=args.user, password=args.password)
    api.login()
    legacy_prop = find_entity_by_label(api, LEGACY_PROPERTY_LABEL, "property", args.lang or "en")
    if not legacy_prop:
        raise SystemExit("legacy publisher property not found — vocabulary imported?")
    query = SPARQL_PREFIXES + f"""
SELECT (COUNT(DISTINCT ?st) AS ?n) WHERE {{
  ?item p:{legacy_prop} ?st .
  ?st ps:{legacy_prop} ?publisher .
}}"""
    rows = sparql_http_get(args.sparql_url, query)
    n = int(rows[0]["n"]["value"]) if rows and "n" in rows[0] else 0
    print(f"remaining string publisher statements: {n}")
    return 0 if n == 0 else 1


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", required=True, help="wiki base URL (e.g. https://wikibase.ronzz.org)")
    parser.add_argument("--sparql-url", required=True, help="WDQS endpoint (e.g. http://127.0.0.1:9999/bigdata/namespace/wdq/sparql)")
    parser.add_argument("--user", default="SeedBot")
    parser.add_argument("--password-file", help="file containing the bot password (0600)")
    parser.add_argument("--lang", default="en", help="term language for resolution (default en)")
    parser.add_argument("--dry-run", action="store_true", help="plan only, no writes")
    parser.add_argument("--verify", action="store_true", help="only recount remaining string publisher statements")
    parser.add_argument("--summary-prefix", default="Migration: ", help="edit-summary prefix")
    args = parser.parse_args()

    if args.password_file:
        with open(args.password_file, encoding="utf-8") as f:
            args.password = f.read().strip()
    else:
        args.password = None

    if args.verify:
        return verify(args)
    return migrate(args)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:  # noqa: BLE001 — surface any failure loudly (never silent)
        print(f"error: {exc}", file=sys.stderr)
        raise SystemExit(1)
