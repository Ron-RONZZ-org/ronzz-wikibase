#!/usr/bin/env python3
"""tools/backfill_classic_pages.py — create the missing classic page +
sitelink for items that the Add* flow created WITHOUT one (the Q1232 case).

Why this exists: harvested external titles can carry HTML markup (<i>…</i>
— OpenAlex italics). Before the LabelSanitizer fix the markup reached the
stored item label AND made the classic-page title invalid (MediaWiki rejects
< > in titles), so afterCreate silently fell back to the item redirect — the
item exists, its class declares a classic page, but no page and no sitelink
were ever created (e.g. Item:Q1232 "Planck 2018 results (Scholarly article)").

This tool heals those orphans:

  for each given item id:
    1. skip when the item already has a wikibase sitelink (nothing to heal);
    2. read the en label + description, and the instance-of class id;
    3. look the class up in the --ns-map (instance class id → page
       namespace + template) — skip with a warning when unknown;
    4. sanitize the label (strip HTML markup — the LabelSanitizer contract)
       and skip when it is unusable as a page title;
    5. create the page with the class template + the == Overview == lead
       (the item description, when present), THEN wbsetsitelink (the repo
       API rejects sitelinks to non-existent pages — "no-external-page"),
       THEN touch-edit the page so its parse runs with the committed
       sitelink and sets the wikibase_item page property immediately (the
       extension's complete/<id> finalize pattern).

Idempotent and self-verifying: re-running skips healed items; --verify
re-checks the sitelink + page existence afterwards.

Usage:
  python3 tools/backfill_classic_pages.py Q1232 \
      --base-url https://wikibase.ronzz.org \
      --user SeedBot --password-file seed/.seedbot.pass \
      --ns-map '{"Q10": {"ns": "Source", "template": "ScholarlyArticle"}}'
  (pass --apply to write; dry-run is the default)

The ns-map keys are the instance's class item ids (resolve them on-wiki or
from the seed output): person → Person:+Template:Person, collective →
Collective:+Template:Collective, the source classes → Source:+per-class
templates (Book/ScholarlyArticle/Website/Song/Film/Video/YouTubeChannel/
YouTubeVideo/Webpage), software → FOSS:+FOSS-Infobox. Since the FOSS: vs
Software: split (the license decides the page kind), a software entry may
use the special form {"software": true} — the tool then resolves the
namespace per item from the license statements: pass --license-property
(the license property id) + --software-license-ids (comma-separated Q-ids
of the free/open-source licenses) and the page lands in FOSS: for a FOSS
license, Software: otherwise (fallback: FOSS:, with a warning, when the
flags are absent).

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

# Title characters MediaWiki forbids (the LabelSanitizer+pageTitleForRecord
# contract): the tool skips such labels instead of writing a broken title.
TITLE_FORBIDDEN = re.compile(r"[#<>\[\]{}|]")


def strip_markup(text: str) -> str:
    """Mirror of the PHP LabelSanitizer::stripMarkup: decode entities, drop
    tags, collapse whitespace."""
    text = re.sub(r"&(?:lt|gt|amp|quot|#\d+);", "", text)  # decode common entities
    text = re.sub(r"<[^>]*>", "", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def fetch_entity(api: WikibaseApi, qid: str) -> dict:
    """wbgetentities including the sitelinks (the seed's get_entity omits
    them — the tool must see an existing sitelink to skip healed items)."""
    r = api._get(
        f"action=wbgetentities&ids={qid}&props=labels|descriptions|claims|sitelinks")
    try:
        return r["entities"][qid]
    except KeyError as exc:
        raise WikibaseApiError(f"wbgetentities returned no entity {qid}") from exc


def resolve_mapping(entity: dict, mapping: dict, license_property: str,
                    software_license_ids: set[str]) -> dict:
    """Resolves a class mapping to (ns, template) for one item. A plain
    mapping is used as-is; a {"software": true} mapping (the FOSS: vs
    Software: split on Special:AddSoftware) decides from the item's license
    statements: any license value in software_license_ids → FOSS:, else
    Software:. Falls back to FOSS: with a warning when the license flags
    were not given."""
    if not mapping.get("software"):
        return mapping
    if not license_property or not software_license_ids:
        print("    warning: software mapping without --license-property/"
              "--software-license-ids — defaulting to FOSS:", file=sys.stderr)
        return {"ns": "FOSS", "template": "FOSS"}
    for st in entity.get("claims", {}).get(license_property, []):
        dv = st.get("mainsnak", {}).get("datavalue", {})
        if dv.get("type") == "wikibase-entityid" and dv["value"].get("id") in software_license_ids:
            return {"ns": "FOSS", "template": "FOSS"}
    return {"ns": "Software", "template": "Software"}


def heal(api: WikibaseApi, qid: str, ns_map: dict, site_id: str, lang: str,
         dry_run: bool, license_property: str = "",
         software_license_ids: set[str] | None = None) -> tuple[str, bool]:
    """Heals one orphan item; returns (status, healed)."""
    entity = fetch_entity(api, qid)
    if "sitelinks" in entity and entity["sitelinks"].get(site_id):
        return f"{qid}: already has the {site_id} sitelink — skip", False

    labels = entity.get("labels", {})
    label = labels.get(lang, {}).get("value", "") or labels.get("en", {}).get("value", "")
    label = strip_markup(label)
    if not label:
        return f"{qid}: no usable {lang} label — skip", False
    if TITLE_FORBIDDEN.search(label):
        return f"{qid}: label {label!r} is unusable as a page title — skip", False

    desc = (entity.get("descriptions", {}).get(lang, {}) or {}).get("value", "") \
        or (entity.get("descriptions", {}).get("en", {}) or {}).get("value", "")

    classes = []
    claims = entity.get("claims", {})
    for prop, statements in claims.items():
        for st in statements:
            main = st.get("mainsnak", {})
            dv = main.get("datavalue", {})
            if dv.get("type") == "wikibase-entityid":
                classes.append(dv["value"].get("id"))
    class_id = next((c for c in classes if c in ns_map), None)
    if class_id is None:
        return f"{qid}: no ns-map entry for instance-of {classes} — skip", False
    mapping = resolve_mapping(entity, ns_map[class_id], license_property,
                              software_license_ids or set())
    page_title = f"{mapping['ns']}:{label}"
    template = mapping.get("template", "")

    if not dry_run:
        try:
            # Create the page FIRST: the repo's wbsetsitelink validates that
            # the target page exists on the client site ("no-external-page"
            # otherwise — the internal entity-store path the Add* flow uses
            # accepts red links, the API does not).
            text = f"{{{{{template}}}}}\n\n" if template else ""
            if desc:
                text += f"== Overview ==\n\n{desc}\n\n"
            api.edit_page(
                page_title, text,
                "backfill: create the classic page for the item (backfill_classic_pages.py)",
            )
            result = api._post(
                "action=wbsetsitelink", token=api.require_csrf(),
                id=qid, linksite=site_id, linktitle=page_title,
                summary="backfill: heal the missing classic-page sitelink (backfill_classic_pages.py)",
            )
            if "success" not in result:
                raise WikibaseApiError(f"wbsetsitelink failed for {qid}: {result}")
            # Touch-edit the page so its parse runs with the COMMITTED
            # sitelink — this sets the wikibase_item page property now (the
            # extension's complete/<id> finalize pattern; otherwise it would
            # wait for the next cron/job-driven re-parse).
            api.edit_page(
                page_title, text,
                "backfill: re-parse the page with the committed sitelink (backfill_classic_pages.py)",
            )
        except (WikibaseApiError, urllib.error.URLError) as exc:
            return f"{qid}: error: {exc} — re-run to retry", False
    return f"{qid}: -> {page_title} (template {template or '(none)'}, class {class_id})", True


def verify(api: WikibaseApi, qids: list[str], ns_map: dict, site_id: str,
           lang: str) -> int:
    ok = 0
    for qid in qids:
        entity = fetch_entity(api, qid)
        linked = entity.get("sitelinks", {}).get(site_id)
        if not linked:
            print(f"  ! {qid}: STILL no {site_id} sitelink")
            continue
        page_title = linked.get("title", "")
        r = api._get(f"action=query&titles={urllib.parse.quote(page_title)}&format=json")
        pages = list(r.get("query", {}).get("pages", {}).values())
        if pages and "missing" not in pages[0]:
            props = pages[0].get("pageprops", {})
            if props.get("wikibase_item") == qid:
                ok += 1
                print(f"  [ok] {qid} -> {page_title} (wikibase_item set)")
            else:
                print(f"  ! {qid} -> {page_title}: wikibase_item={props.get('wikibase_item')!r} "
                      f"(may lag one parse; re-edit the page to set it)")
        else:
            print(f"  ! {qid}: page {page_title} missing")
    return ok


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("qids", nargs="+", help="item ids to heal (e.g. Q1232)")
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    parser.add_argument("--user", default="SeedBot")
    parser.add_argument("--password-file", required=True)
    parser.add_argument("--lang", default="en")
    parser.add_argument("--site-id", default="wikibase")
    parser.add_argument(
        "--ns-map", required=True,
        help='JSON: instance-of class id -> {"ns": "Source", "template": "ScholarlyArticle"} '
             'or {"software": true} for license-resolved software pages')
    parser.add_argument("--license-property", default="",
                        help="the license property id (P34) — required to resolve a "
                             '{"software": true} mapping (the FOSS:/Software: split)')
    parser.add_argument("--software-license-ids", default="",
                        help="comma-separated Q-ids of the free/open-source licenses — "
                             "required to resolve a {\"software\": true} mapping")
    parser.add_argument("--dry-run", action="store_true", default=True,
                        help="plan only (default); pass --apply to write")
    parser.add_argument("--apply", action="store_true", help="actually write (dry-run is default)")
    parser.add_argument("--verify", action="store_true",
                        help="re-check the sitelink + page + wikibase_item afterwards")
    args = parser.parse_args()
    if args.apply:
        args.dry_run = False
    try:
        ns_map = json.loads(args.ns_map)
    except json.JSONDecodeError as exc:
        print(f"error: --ns-map is not valid JSON: {exc}", file=sys.stderr)
        return 1
    software_license_ids = {
        q.strip().upper() for q in args.software_license_ids.split(",") if q.strip()
    }
    with open(args.password_file, encoding="utf-8") as fh:
        password = fh.read().strip()
    api = WikibaseApi(args.base_url, args.user, password)
    try:
        api.login()
    except WikibaseApiError as exc:
        print(f"error: login failed: {exc}", file=sys.stderr)
        return 1

    healed = 0
    for qid in args.qids:
        status, done = heal(api, qid, ns_map, args.site_id, args.lang, args.dry_run,
                            args.license_property, software_license_ids)
        print(f"  {status}")
        healed += done
    print(f"\nbackfill complete: {healed} items healed"
          + (" (dry-run — nothing written)" if args.dry_run else ""))
    if args.dry_run:
        print("re-run with --apply to write")
    if args.verify:
        ok = verify(api, args.qids, ns_map, args.site_id, args.lang)
        print(f"verify: {ok}/{len(args.qids)} items fully linked")
    return 0


if __name__ == "__main__":
    sys.exit(main())
