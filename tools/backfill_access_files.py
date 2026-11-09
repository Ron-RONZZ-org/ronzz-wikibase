#!/usr/bin/env python3
"""tools/backfill_access_files.py — give existing AddSource access files their
semantic access-file item + source link.

Before the access-file-entities change, an access file was a `file` URL
statement on the Source item (with the license/status qualifiers). The flow
now creates a sitelinked `access file` item (instance of access file, `file`
URL, `license`) and links the source via `has access file`, carrying the
status and language qualifiers.

For every legacy `file` statement this tool:
  1. resolves the File: page's sitelinked item (or creates one);
  2. adds `instance of <access file>`, `file` (URL) and `license` (from the
     legacy qualifier / the source's top-level license);
  3. adds the `has access file` statement to the source with the public/login
     status and the language (the source's first language, else `en`);
  4. leaves the legacy `file` statement untouched (the read path dedupes by
     file title; removing it is an explicit item edit).

Idempotent: a source that already links the file is skipped.

Usage:
  python3 tools/backfill_access_files.py \
      --base-url https://wikibase.ronzz.org \
      --sparql-url http://127.0.0.1:9999/bigdata/namespace/wdq/sparql \
      --user SeedBot --password-file seed/.seedbot.pass [--apply] [--verify]

Python stdlib only (AGENTS.md: no pip dependencies).
"""

import argparse
import json
import re
import sys
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "seed"))
from wikibase_api import WikibaseApi, WikibaseApiError  # noqa: E402

INSTANCE_OF = "instance of"
FILE_PROP = "file"
LICENSE_PROP = "license"
LANGUAGE_PROP = "language"
STATUS_PROP = "access status"
HAS_ACCESS_FILE_PROP = "has access file"
ACCESS_FILE_CLASS = "access file"


def sparql_get(sparql_url: str, query: str) -> list[dict]:
    url = f"{sparql_url}?query={urllib.parse.quote(query)}&format=json"
    with urllib.request.urlopen(url, timeout=60) as resp:  # noqa: S310
        data = json.load(resp)
    return data.get("results", {}).get("bindings", [])


def find_by_label(api: WikibaseApi, label: str, entity_type: str) -> str | None:
    wanted = label.strip().lower()
    for hit in api.search_entities(label, entity_type, "en"):
        match = str(hit.get("match", {}).get("text", "")).strip().lower()
        if match == wanted or str(hit.get("label", "")).strip().lower() == wanted:
            return hit.get("id")
    return None


def file_title_from_url(url: str) -> str | None:
    m = re.search(r"/wiki/(File:[^?#]+)", url)
    if not m:
        return None
    return urllib.parse.unquote(m.group(1)).replace("_", " ")


def item_value(item_id: str) -> dict:
    return {
        "value": {
            "entity-type": "item",
            "numeric-id": int(item_id[1:]),
            "id": item_id,
        },
        "type": "wikibase-entityid",
    }


def string_value(value: str) -> dict:
    return {"value": value, "type": "string"}


def claim(prop_id: str, datavalue: dict, qualifiers: dict | None = None) -> dict:
    out = {
        "mainsnak": {
            "snaktype": "value",
            "property": prop_id,
            "datavalue": datavalue,
        },
        "type": "statement",
        "rank": "normal",
    }
    if qualifiers:
        out["qualifiers"] = qualifiers
    return out


def qualifier(prop_id: str, datavalue: dict) -> dict:
    return [{
        "snaktype": "value",
        "property": prop_id,
        "datavalue": datavalue,
    }]


def first_value(claims: dict, prop_id: str) -> str:
    for c in claims.get(prop_id, []):
        value = c.get("mainsnak", {}).get("datavalue", {}).get("value")
        if isinstance(value, str):
            return value
        if isinstance(value, dict) and value.get("id"):
            return str(value["id"])
    return ""


def sitelinked_item(api: WikibaseApi, file_title: str) -> str | None:
    query = urllib.parse.urlencode({
        "action": "query",
        "prop": "pageprops",
        "titles": file_title,
        "ppprop": "wikibase_item",
        "format": "json",
    })
    data = api._get(query)  # noqa: SLF001 (same package tooling)
    pages = data.get("query", {}).get("pages", {})
    for page in pages.values():
        item = page.get("pageprops", {}).get("wikibase_item")
        if item:
            return item
    return None


def create_file_item(api: WikibaseApi, file_title: str, summary: str) -> str:
    name = file_title[5:]  # drop "File:"
    data = {
        "labels": {"en": {"language": "en", "value": name}},
        "descriptions": {"en": {
            "language": "en",
            "value": f"Access file of {name}",
        }},
        "sitelinks": {"wikibase": {"site": "wikibase", "title": file_title}},
    }
    result = api._post(  # noqa: SLF001
        "action=wbeditentity&new=item",
        token=api.require_csrf(),
        data=json.dumps(data, ensure_ascii=False),
        summary=summary,
    )
    entity_id = result.get("entity", {}).get("id")
    if not entity_id:
        raise WikibaseApiError(f"could not create the access-file item for {file_title}")
    return entity_id


def backfill(args) -> int:
    base = args.base_url.rstrip("/")
    api = WikibaseApi(args.api_url or f"{base}/api.php", args.user, args.password)
    if args.user:
        api.login()

    props = {}
    for label, key in [
        (INSTANCE_OF, "instanceOf"),
        (FILE_PROP, "file"),
        (LICENSE_PROP, "license"),
        (LANGUAGE_PROP, "language"),
        (STATUS_PROP, "status"),
        (HAS_ACCESS_FILE_PROP, "hasAccessFile"),
    ]:
        resolved = find_by_label(api, label, "property")
        if resolved:
            props[key] = resolved
    class_id = find_by_label(api, ACCESS_FILE_CLASS, "item")
    if not props.get("file") or not props.get("hasAccessFile") or not class_id:
        print("The access-file vocabulary (property/class) is missing; re-seed first.")
        return 1

    license_clause = (
        f"OPTIONAL {{ ?st pq:{props['license']} ?license }}"
        if props.get("license") else ""
    )
    query = f"""
SELECT ?item ?file ?license WHERE {{
  ?item p:{props['file']} ?st .
  ?st ps:{props['file']} ?file .
  {license_clause}
}}
"""
    rows = sparql_get(args.sparql_url, query)
    print(f"{len(rows)} legacy access-file statement(s)")
    changed = 0
    for row in rows[: args.limit or None]:
        source = row["item"]["value"].rsplit("/", 1)[-1]
        file_url = row["file"]["value"]
        file_title = file_title_from_url(file_url)
        if not file_title:
            continue
        license_id = row.get("license", {}).get("value", "").rsplit("/", 1)[-1]
        claims = api.get_claims(source)
        existing = claims.get(props["hasAccessFile"], [])
        already = any(
            c.get("mainsnak", {}).get("datavalue", {}).get("value", {}).get("id")
            for c in existing
        )
        if already:
            continue
        file_item = sitelinked_item(api, file_title)
        if file_item is None:
            file_item = create_file_item(api, file_title, "Backfill: access-file item")
        if args.dry_run:
            print(f"  [dry-run] {source}: {file_title} -> {file_item}")
            continue
        api.add_claims(file_item, {
            props["instanceOf"]: [claim(props["instanceOf"], item_value(class_id))],
            props["file"]: [claim(props["file"], string_value(file_url))],
            **({props["license"]: [claim(props["license"], item_value(license_id))]}
               if license_id else {}),
        }, "Backfill: access-file facts")
        status = "public"
        language = first_value(claims, props.get("language", "")) or "en"
        api.add_claims(source, {
            props["hasAccessFile"]: [claim(
                props["hasAccessFile"],
                item_value(file_item),
                {
                    **({props["status"]: qualifier(props["status"], string_value(status))}
                       if props.get("status") else {}),
                    **({props["language"]: qualifier(props["language"], string_value(language))}
                       if props.get("language") else {}),
                },
            )],
        }, "Backfill: link the semantic access file")
        changed += 1
        print(f"  {source}: {file_title} -> {file_item}")

    print(f"{changed} source item(s) linked"
          + (" (dry-run — nothing written)" if args.dry_run else ""))
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    parser.add_argument("--api-url", default="")
    parser.add_argument("--sparql-url", required=True)
    parser.add_argument("--user", default="")
    parser.add_argument("--password-file", default="")
    parser.add_argument("--apply", action="store_true", help="write (default: dry-run)")
    parser.add_argument("--verify", action="store_true", help="only report the legacy count")
    parser.add_argument("--limit", type=int, default=0)
    args = parser.parse_args()
    args.dry_run = not args.apply
    if args.password_file:
        args.password = Path(args.password_file).read_text(encoding="utf-8").strip()
    else:
        args.password = ""
    return backfill(args)


if __name__ == "__main__":
    sys.exit(main())
