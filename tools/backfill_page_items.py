#!/usr/bin/env python3
"""tools/backfill_page_items.py — create the missing sitelinked item for
Main-namespace pages that predate the page→item hook (the reverse of
tools/backfill_classic_pages.py).

Why this exists: before the `Flow/PageItemCreator` hook, a page written
directly by an editor (the classic wikitext workflow) had NO Wikibase item.
The hook now auto-creates (or reuses) the item for every NEW Main-namespace
page; this tool heals the pages created before it — the "gaps on prod".

For each candidate Main-namespace page (not a redirect, not excluded):

  1. skip when the page is already sitelinked to an item;
  2. reuse an existing item whose label matches the page title exactly
     (never duplicate, never steal — the hook's rule, applied here too);
  3. otherwise create a new item (label = the page title, content language)
     and sitelink it to the page.

Idempotent and self-verifying: re-running skips linked pages; --verify
re-checks the sitelink afterwards.

Usage:
  python3 tools/backfill_page_items.py \
      --base-url https://wikibase.ronzz.org \
      --user SeedBot --password-file seed/.seedbot.pass
  (pass --apply to write; dry-run is the default)

Only "content pages" are considered by default: redirects are skipped, and
a --exclude regex (repeatable; default: the housekeeping/pseudo pages) filters
the rest. REVIEW the dry-run list before --apply — the wiki's Main namespace
also holds project/sandbox pages that should not become items.

Python stdlib only (AGENTS.md: no pip dependencies).
"""

import argparse
import re
import sys
import urllib.error
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "seed"))
from wikibase_api import WikibaseApi, WikibaseApiError  # noqa: E402

# Housekeeping/pseudo pages that live in the Main namespace but are not
# content (the default --exclude; --exclude replaces it).
DEFAULT_EXCLUDE = r"^(Main Page|Sandbox(?::|$))"

SUMMARY_CREATE = "backfill: create the item for the wiki page (backfill_page_items.py)"
SUMMARY_LINK = "backfill: link the wiki page to its item (backfill_page_items.py)"


def list_main_pages(api: WikibaseApi) -> list[str]:
    """All Main-namespace non-redirect page titles (paginated)."""
    titles: list[str] = []
    cont = ""
    while True:
        q = ("action=query&list=allpages&apnamespace=0&apfilterredir=nonredirects"
             "&aplimit=500")
        if cont:
            q += "&apcontinue=" + urllib.parse.quote(cont)
        r = api._get(q)
        titles.extend(p["title"] for p in r.get("query", {}).get("allpages", []))
        cont = r.get("continue", {}).get("apcontinue", "")
        if not cont:
            return titles


def linked_titles(api: WikibaseApi, titles: list[str], site_id: str) -> set[str]:
    """The subset of `titles` already sitelinked to an item (batched)."""
    linked: set[str] = set()
    for i in range(0, len(titles), 50):
        batch = titles[i:i + 50]
        r = api._get(
            "action=wbgetentities&sites=%s&titles=%s&props=sitelinks"
            % (urllib.parse.quote(site_id), urllib.parse.quote("|".join(batch)))
        )
        for entity in r.get("entities", {}).values():
            if entity.get("missing"):
                continue
            title = (entity.get("sitelinks", {}).get(site_id) or {}).get("title")
            if title:
                linked.add(title)
    return linked


def find_item_by_label(api: WikibaseApi, label: str, lang: str) -> str | None:
    """The item id whose label matches exactly (case-insensitive), or None."""
    for hit in api.search_entities(label, "item", lang):
        if (hit.get("label") or "").strip().casefold() == label.strip().casefold():
            return hit["id"]
    return None


def sitelink(api: WikibaseApi, qid: str, title: str, site_id: str) -> None:
    result = api._post(
        "action=wbsetsitelink", token=api.require_csrf(),
        id=qid, linksite=site_id, linktitle=title, summary=SUMMARY_LINK,
    )
    if "success" not in result:
        raise WikibaseApiError(f"wbsetsitelink failed for {qid}: {result}")


def heal(api: WikibaseApi, title: str, site_id: str, lang: str,
         dry_run: bool) -> tuple[str, bool]:
    """Heals one page; returns (status, changed)."""
    existing = find_item_by_label(api, title, lang)
    if existing is not None:
        if dry_run:
            return f"{title!r}: reuse existing item {existing}", True
        sitelink(api, existing, title, site_id)
        return f"{title!r}: -> reused {existing}", True

    if dry_run:
        return f"{title!r}: create a new item", True
    qid = api.create_item({lang: title}, {}, SUMMARY_CREATE)
    sitelink(api, qid, title, site_id)
    return f"{title!r}: -> created {qid}", True


def verify(api: WikibaseApi, titles: list[str], site_id: str) -> int:
    linked = linked_titles(api, titles, site_id)
    ok = 0
    for title in titles:
        if title in linked:
            ok += 1
        else:
            print(f"  ! {title!r}: STILL no {site_id} sitelink")
    return ok


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    parser.add_argument("--user", default="SeedBot")
    parser.add_argument("--password-file", required=True)
    parser.add_argument("--lang", default="en")
    parser.add_argument("--site-id", default="wikibase")
    parser.add_argument("--exclude", default=DEFAULT_EXCLUDE,
                        help="regex of page titles to skip (default: %(default)s)")
    parser.add_argument("--limit", type=int, default=0,
                        help="only consider the first N candidates (0 = all)")
    parser.add_argument("--dry-run", action="store_true", default=True,
                        help="plan only (default); pass --apply to write")
    parser.add_argument("--apply", action="store_true", help="actually write (dry-run is default)")
    parser.add_argument("--verify", action="store_true",
                        help="re-check the sitelinks afterwards")
    args = parser.parse_args()
    if args.apply:
        args.dry_run = False

    exclude = re.compile(args.exclude) if args.exclude else None

    with open(args.password_file, encoding="utf-8") as fh:
        password = fh.read().strip()
    api = WikibaseApi(args.base_url, args.user, password)
    try:
        api.login()
    except WikibaseApiError as exc:
        print(f"error: login failed: {exc}", file=sys.stderr)
        return 1

    pages = list_main_pages(api)
    if exclude:
        pages = [t for t in pages if not exclude.search(t)]
    linked = linked_titles(api, pages, args.site_id)
    candidates = [t for t in pages if t not in linked]
    if args.limit:
        candidates = candidates[:args.limit]
    print(f"Main-namespace pages scanned: {len(pages)}; "
          f"unlinked candidates: {len(candidates)}")
    if not candidates:
        print("nothing to do")
        return 0

    changed = 0
    for title in candidates:
        try:
            status, done = heal(api, title, args.site_id, args.lang, args.dry_run)
        except (WikibaseApiError, urllib.error.URLError) as exc:
            print(f"  ! {title!r}: error: {exc} — re-run to retry")
            continue
        print(f"  {status}")
        changed += done
    print(f"\nbackfill complete: {changed} pages processed"
          + (" (dry-run — nothing written)" if args.dry_run else ""))
    if args.dry_run:
        print("re-run with --apply to write")
    if args.verify:
        ok = verify(api, candidates, args.site_id)
        print(f"verify: {ok}/{len(candidates)} pages sitelinked")
    return 0


if __name__ == "__main__":
    sys.exit(main())
