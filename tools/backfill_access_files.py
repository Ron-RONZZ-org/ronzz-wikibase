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
    """The item sitelinked to the File: page via the Wikibase sitelink store.

    Uses wbgetentities (not page_props): a fresh item's page_props
    `wikibase_item` fills in only after a job run, so a just-created item
    would be missed and the backfill would try to recreate it (duplicate
    label/description). The sitelink store is updated synchronously.
    """
    query = urllib.parse.urlencode({
        "action": "wbgetentities",
        "sites": "wikibase",
        "titles": file_title,
        "props": "info",
        "format": "json",
    })
    data = api._get(query)  # noqa: SLF001 (same package tooling)
    for entity_id, entity in data.get("entities", {}).items():
        if entity_id.startswith("Q") and "missing" not in entity:
            return entity_id
    return None


def ensure_sitelink(api: WikibaseApi, file_title: str, item_id: str) -> None:
    """Idempotently point the item's wikibase sitelink at the File: page."""
    api._post(  # noqa: SLF001
        "action=wbeditentity",
        token=api.require_csrf(),
        id=item_id,
        data=json.dumps({
            "sitelinks": {"wikibase": {"site": "wikibase", "title": file_title}},
        }, ensure_ascii=False),
        summary="Backfill: link the access-file page",
    )


def ensure_file_page_facts(api: WikibaseApi, file_title: str) -> None:
    """Append {{#image-facts:}} to a File page that predates the item model."""
    query = urllib.parse.urlencode({
        "action": "query",
        "prop": "revisions",
        "rvprop": "content",
        "rvslots": "main",
        "titles": file_title,
        "format": "json",
    })
    data = api._get(query)  # noqa: SLF001
    pages = data.get("query", {}).get("pages", {})
    for page in pages.values():
        if "missing" in page:
            return
        revisions = page.get("revisions", [])
        if not revisions:
            return
        text = (
            revisions[0].get("slots", {}).get("main", {}).get("*")
            or revisions[0].get("*", "")
        )
        if "{{#image-facts:" in text:
            return
        api.edit_page(
            file_title,
            text.rstrip() + "\n\n{{#image-facts:}}\n",
            "Backfill: render the access-file facts",
        )
        return


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
        # A concurrent/previous run may have created the item already; the
        # sitelink store is the authoritative identity.
        existing = sitelinked_item(api, file_title)
        if existing:
            return existing
        raise WikibaseApiError(
            f"could not create the access-file item for {file_title}: "
            f"{result.get('error', result)}"
        )
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
        # The query matches every item carrying a `file` statement — which
        # includes the access-file items themselves. They are not sources:
        # skip them, and clean up a self-link created by an earlier pass.
        if first_value(claims, props.get("instanceOf", "")) == class_id:
            if claims.get(props["hasAccessFile"]):
                if args.dry_run:
                    print(f"  [dry-run] {source}: remove the self access-file link")
                else:
                    entity = api.get_entity(source)
                    remaining = {
                        k: v for k, v in claims.items()
                        if k != props["hasAccessFile"]
                    }
                    # clear=1 replaces the entity with exactly this data, so
                    # the statement is actually removed (a plain merge keeps
                    # the existing claim list).
                    api._post(  # noqa: SLF001
                        "action=wbeditentity",
                        token=api.require_csrf(),
                        id=source,
                        clear="1",
                        data=json.dumps({
                            "labels": entity.get("labels", {}),
                            "descriptions": entity.get("descriptions", {}),
                            "aliases": entity.get("aliases", {}),
                            "sitelinks": entity.get("sitelinks", {}),
                            "claims": remaining,
                        }, ensure_ascii=False),
                        summary="Backfill: remove the self access-file link",
                    )
                    print(f"  {source}: removed the self access-file link")
            if not args.dry_run:
                ensure_sitelink(api, file_title, source)
                ensure_file_page_facts(api, file_title)
            continue
        if not license_id:
            # Legacy single-access items store the license as a MAIN statement
            # on the source, not as a qualifier on `file`.
            license_id = first_value(claims, props.get("license", ""))
        existing = claims.get(props["hasAccessFile"], [])
        existing_id = next((
            c.get("mainsnak", {}).get("datavalue", {}).get("value", {}).get("id")
            for c in existing
            if c.get("mainsnak", {}).get("datavalue", {}).get("value", {}).get("id")
        ), "")
        if existing_id and license_id:
            # Repair a previously-created file item that lacks the license
            # (the first backfill pass could miss the source-level license).
            if args.dry_run:
                print(f"  [dry-run] {source}: repair license on {existing_id}")
            else:
                api.add_claims(existing_id, {
                    props["license"]: [claim(props["license"], item_value(license_id))],
                }, "Backfill: access-file license")
                ensure_sitelink(api, file_title, existing_id)
                ensure_file_page_facts(api, file_title)
            continue
        if existing_id:
            if not args.dry_run:
                ensure_sitelink(api, file_title, existing_id)
                ensure_file_page_facts(api, file_title)
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
        ensure_sitelink(api, file_title, file_item)
        ensure_file_page_facts(api, file_title)
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
