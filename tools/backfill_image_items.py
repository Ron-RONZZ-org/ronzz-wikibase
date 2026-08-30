#!/usr/bin/env python3
"""tools/backfill_image_items.py — give existing Add* image files their
semantic image item + File-page attribution (image-facts-semantics batch).

The Add* portrait/logo flows used to write the image facts (license, image
author, additional license information) as statements on the CONSUMER entity
(collective/person/software) while the File: page showed only "Logo of X."
The flows now create a sitelinked `image`-class item per upload + write the
attribution on the File page (ImageUploadHelper/ImageItemCreator). This tool
backfills the files uploaded BEFORE that change:

  for each File: page referenced by an item's `image` statement:
    1. skip when the file already has a sitelinked image item (the new
       flows / Special:Upload created one);
    2. else collect the image facts from the referencing CONSUMER entity
       (license / image author / additional license information) and the
       Source line from the File page text;
    3. create the image item (label = file name sans extension, instance of
       the image class, the facts + source URL when available) and sitelink
       the File: page;
    4. append the == License == / == Attribution == blocks to the File page
       text (idempotent — existing blocks are left alone).

The consumer's image-fact statements are KEPT (copy, not move): for
AddSoftware items the `license` statement mixes the software's own license
with the old logo license and cannot be split safely; removing old
statements is an explicit item-page edit.

Idempotent and self-verifying: re-running skips files that already have an
image item; --verify re-checks the count of orphaned files afterwards.

Usage (mirrors the migrate_string_publishers pattern):
  python3 tools/backfill_image_items.py \
      --base-url https://wikibase.ronzz.org \
      --sparql-url http://127.0.0.1:9999/bigdata/namespace/wdq/sparql \
      --user SeedBot --password-file seed/.seedbot.pass [--dry-run]

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

INSTANCE_OF_LABEL = "instance of"
IMAGE_CLASS_LABEL = "image"
IMAGE_PROP_LABEL = "image"
LICENSE_PROP_LABEL = "license"
IMAGE_AUTHOR_PROP_LABEL = "image author"
LICENSE_INFO_PROP_LABEL = "additional license information"
SOURCE_URL_PROP_LABEL = "source URL"

SPARQL_PREFIXES = (
    "PREFIX wd: <https://wikibase.ronzz.org/entity/>\n"
    "PREFIX wdt: <https://wikibase.ronzz.org/prop/direct/>\n"
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


def file_title_from_url(url: str) -> str | None:
    """Extracts the "File:<name>" title from an image-statement URL
    (https://wikibase.ronzz.org/wiki/File:European_Space_Agency-logo.png)."""
    m = re.search(r"/wiki/(File:[^?#]+)", url)
    return urllib.parse.unquote(m.group(1)).replace("_", " ") if m else None


def string_values(claims: dict, prop_id: str) -> list[str]:
    """String/URL statement values of a property on a claims map."""
    out = []
    for claim in claims.get(prop_id, []):
        value = claim.get("mainsnak", {}).get("datavalue", {}).get("value")
        if isinstance(value, str):
            out.append(value)
    return out


def entity_values(claims: dict, prop_id: str) -> list[str]:
    """Item-typed statement values (serialized ids) of a property."""
    out = []
    for claim in claims.get(prop_id, []):
        value = claim.get("mainsnak", {}).get("datavalue", {}).get("value", {})
        if isinstance(value, dict) and value.get("entity-type") == "item":
            out.append(value.get("id"))
    return out


def first_string(claims: dict, prop_id: str) -> str:
    values = string_values(claims, prop_id)
    return values[0] if values else ""


def page_source_line(page_text: str) -> str:
    """The "Source: <url>" line of an Add* File page (the image provenance)."""
    m = re.search(r"^Source:\s*(\S+)\s*$", page_text, re.M)
    return m.group(1) if m else ""


def build_attribution(page_text: str, license_label: str, author: str,
                      license_info: str, source_url: str) -> str:
    """Appends the == License == / == Attribution == blocks when the page
    does not already carry them (idempotent)."""
    if "== Attribution ==" in page_text or "== License ==" in page_text:
        return page_text
    text = page_text.rstrip() + "\n"
    if license_label:
        text += f"\n== License ==\n{license_label}\n"
    lines = []
    if author:
        lines.append(f"Author: {author}")
    if license_info:
        lines.append(f"Additional license information: {license_info}")
    if source_url:
        lines.append(f"Source: {source_url}")
    if lines:
        text += "\n== Attribution ==\n" + "\n".join(lines) + "\n"
    return text


def license_label(api: WikibaseApi, license_id: str, language: str) -> str:
    """English (or language) label of a license item, or the id on failure."""
    if not license_id:
        return ""
    try:
        entity = api.get_entity(license_id)
        labels = entity.get("labels", {})
        for lang in (language, "en"):
            if lang in labels:
                return labels[lang].get("value", license_id)
    except WikibaseApiError:
        pass
    return license_id


def backfill(args) -> int:
    language = args.lang or "en"
    api = WikibaseApi(args.base_url, user=args.user, password=args.password)
    api.login()

    instance_of_id = find_entity_by_label(api, INSTANCE_OF_LABEL, "property", language)
    image_class_id = find_entity_by_label(api, IMAGE_CLASS_LABEL, "item", language)
    image_prop_id = find_entity_by_label(api, IMAGE_PROP_LABEL, "property", language)
    license_prop_id = find_entity_by_label(api, LICENSE_PROP_LABEL, "property", language)
    author_prop_id = find_entity_by_label(api, IMAGE_AUTHOR_PROP_LABEL, "property", language)
    info_prop_id = find_entity_by_label(api, LICENSE_INFO_PROP_LABEL, "property", language)
    source_url_prop_id = find_entity_by_label(api, SOURCE_URL_PROP_LABEL, "property", language)
    if not all([instance_of_id, image_class_id, image_prop_id, license_prop_id,
                author_prop_id, info_prop_id]):
        raise SystemExit("vocabulary not resolvable (seed the instance first)")

    # Every item whose image statement references a File: page on this wiki.
    query = (
        SPARQL_PREFIXES
        + f"SELECT ?item ?file WHERE {{ ?item wdt:{image_prop_id} ?file . "
        + 'FILTER(CONTAINS(STR(?file), "/wiki/File:")) . }\n'
    )
    print(f"querying {args.sparql_url} for items with image statements …")
    rows = sparql_http_get(args.sparql_url, query)
    by_file: dict[str, list[str]] = {}
    for row in rows:
        file_url = row.get("file", {}).get("value", "")
        title = file_title_from_url(file_url)
        item = row.get("item", {}).get("value", "").rsplit("/", 1)[-1]
        if title and item:
            by_file.setdefault(title, []).append(item)
    print(f"  {len(by_file)} distinct File: pages referenced by image statements")

    done = 0
    skipped = 0
    for file_title in sorted(by_file):
        consumers = by_file[file_title]
        # The file's own image item already exists → the new flows /
        # Special:Upload created it (pageprops wikibase_item on the File page).
        page = api._get("action=query&prop=pageprops&format=json&titles="
                        + urllib.parse.quote(file_title))
        page_props = {}
        for p in page.get("query", {}).get("pages", {}).values():
            page_props = p.get("pageprops", {})
        existing_item = page_props.get("wikibase_item", "")
        if existing_item:
            skipped += 1
            continue

        # Collect the image facts from the referencing consumer entity (the
        # first one that carries them — they are the same for a shared file).
        license_id = ""
        author = ""
        license_info = ""
        for consumer in consumers:
            claims = api.get_claims(consumer)
            license_ids = entity_values(claims, license_prop_id)
            if not license_id and license_ids:
                license_id = license_ids[0]
            if not author:
                author = first_string(claims, author_prop_id)
            if not license_info:
                license_info = first_string(claims, info_prop_id)
            if license_id and author and license_info:
                break
        # The image source URL lives on the File page text ("Source: <url>").
        try:
            raw_page = api._get("action=query&prop=revisions&rvprop=content&format=json&titles="
                                + urllib.parse.quote(file_title))
            page_text = ""
            for p in raw_page.get("query", {}).get("pages", {}).values():
                revs = p.get("revisions", [])
                page_text = revs[0].get("*", "") if revs else ""
        except WikibaseApiError:
            page_text = ""
        source_url = page_source_line(page_text)

        label = re.sub(r"\.[^.]+$", "", file_title[len("File:"):])
        summary = "backfill: semantic image item for the Add* upload (image-facts-semantics)"
        print(f"\n  {file_title}: consumers={consumers}")
        print(f"    label={label!r} license={license_id or '(none)'} "
              f"author={author or '(none)'} info={license_info or '(none)'} "
              f"source={source_url or '(none)'}")
        if args.dry_run:
            print("    [dry-run] would create the image item + sitelink + File-page attribution")
            continue

        # Create the image item (label, en description, the facts).
        claims: dict = {
            instance_of_id: [{
                "mainsnak": {
                    "snaktype": "value",
                    "property": instance_of_id,
                    "datavalue": {
                        "value": {
                            "entity-type": "item",
                            "numeric-id": int(image_class_id[1:]),
                            "id": image_class_id,
                        },
                        "type": "wikibase-entityid",
                    },
                },
                "type": "statement",
                "rank": "normal",
            }],
        }
        if license_id:
            claims[license_prop_id] = [{
                "mainsnak": {
                    "snaktype": "value",
                    "property": license_prop_id,
                    "datavalue": {
                        "value": {
                            "entity-type": "item",
                            "numeric-id": int(license_id[1:]),
                            "id": license_id,
                        },
                        "type": "wikibase-entityid",
                    },
                },
                "type": "statement",
                "rank": "normal",
            }]
        if author:
            claims[author_prop_id] = [{
                "mainsnak": {"snaktype": "value", "property": author_prop_id,
                             "datavalue": {"value": author, "type": "string"}},
                "type": "statement", "rank": "normal",
            }]
        if license_info:
            claims[info_prop_id] = [{
                "mainsnak": {"snaktype": "value", "property": info_prop_id,
                             "datavalue": {"value": license_info, "type": "string"}},
                "type": "statement", "rank": "normal",
            }]
        if source_url and source_url_prop_id:
            claims[source_url_prop_id] = [{
                "mainsnak": {"snaktype": "value", "property": source_url_prop_id,
                             "datavalue": {"value": source_url, "type": "string"}},
                "type": "statement", "rank": "normal",
            }]
        item_id = api.create_item(
            {language: label},
            {language: f"An image file uploaded to the instance ({file_title})"},
            summary,
        )
        api.add_claims(item_id, claims, summary)
        # Sitelink the File: page ↔ image item (the Add* pattern).
        result = api._post(
            "action=wbsetsitelink", token=api.require_csrf(),
            id=item_id, linksite="wikibase", linktitle=file_title, summary=summary,
        )
        if "success" not in result:
            raise WikibaseApiError(f"wbsetsitelink failed for {item_id}: {result}")
        # File page attribution (idempotent).
        license_display = license_label(api, license_id, language) if license_id else ""
        new_text = build_attribution(page_text, license_display, author, license_info, source_url)
        if new_text != page_text:
            api.edit_page(file_title, new_text, summary)
        print(f"    created image item {item_id} + sitelink + attribution")
        done += 1

    print(f"\nbackfill complete: {done} image items created, {skipped} files already have one"
          + (" (dry-run — nothing written)" if args.dry_run else ""))
    if args.dry_run:
        print("re-run with --apply to write")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    parser.add_argument("--sparql-url", default="https://wikibase.ronzz.org/sparql")
    parser.add_argument("--user", default="SeedBot")
    parser.add_argument("--password-file", required=True)
    parser.add_argument("--lang", default="en")
    parser.add_argument("--dry-run", action="store_true", default=True,
                        help="plan only (default); pass --apply to write")
    parser.add_argument("--apply", action="store_true", help="actually write (dry-run is default)")
    args = parser.parse_args()
    if args.apply:
        args.dry_run = False
    try:
        return backfill(args)
    except (WikibaseApiError, urllib.error.URLError) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
