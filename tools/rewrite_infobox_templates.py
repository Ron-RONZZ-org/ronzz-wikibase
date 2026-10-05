#!/usr/bin/env python3
"""tools/rewrite_infobox_templates.py — migrate the classic-page infobox
templates to the conditional `{{#statement-row:}}` parser function, so an
empty statement no longer renders a labelled empty row.

Why this exists: ParserFunctions' `#if` is not installed on the instance, so
the empty-row hiding lives in a parser function (the `{{#quotations-of:}}`
pattern). `{{#statement-row:Label|property}}` emits a COMPLETE table row only
when the sitelinked item carries data for the property, and nothing
otherwise. The infobox templates are on-wiki content, so this tool rewrites
them through the MediaWiki API (the `tools/backfill_*` / `migrate_*` pattern).

Transformations (pure, unit-tested):

  |-
  | Date of birth || {{#statements:date of birth}}
      -->
  {{#statement-row:Date of birth|date of birth}}

  |-
  | Place of birth || {{#osm-place:birth}}
      -->
  {{#statement-row:Place of birth|osm-birth}}

`Template:Person` additionally gains the `{{#quotations-by:}}` row (the
Person: counterpart of the Source: pages' `{{#quotations-of:}}` row).

Rows that are deliberately left untouched: the image cell
(`{{#item-image:}}`, a colspan row the template's `logo`/`portrait` parameter
may override), the Access cell (`{{#source-access:}}`, which renders a
localized "N/A" rather than an empty cell), and the already-conditional
`{{#quotations-of:}}` / `{{#child-items-of:}}` rows.

The `{{#statement-row:}}` parser function must be DEPLOYED before this tool
runs (an unknown function is a wikitext error), so it is a post-deploy step.

Usage:
  python3 tools/rewrite_infobox_templates.py \
      --api-url https://wikibase.ronzz.org/api.php \
      --user SeedBot --password-file seed/.seedbot.pass
  (dry-run by default; pass --apply to write)

Python stdlib only (AGENTS.md: no pip dependencies).
"""

from __future__ import annotations

import argparse
import difflib
import re
import sys
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from seed.wikibase_api import WikibaseApi, WikibaseApiError  # noqa: E402

# Templates carrying a `{{#statements:}}` infobox.
DEFAULT_TEMPLATES = [
    "Template:Person",
    "Template:Collective",
    "Template:FOSS/Infobox",
    "Template:Software/Infobox",
    "Template:Book",
    "Template:ScholarlyArticle",
    "Template:Website",
    "Template:Song",
    "Template:Film",
    "Template:Video",
    "Template:YouTubeChannel",
    "Template:YouTubeVideo",
    "Template:Webpage",
    "Template:NewspaperArticle",
    "Template:MagazineArticle",
    "Template:ConferencePaper",
    "Template:Report",
    "Template:Document",
    "Template:Thesis",
    "Template:Manuscript",
    "Template:Patent",
    "Template:LegalCase",
    "Template:Legislation",
    "Template:Bill",
    "Template:Treaty",
    "Template:Interview",
    "Template:Map",
    "Template:Presentation",
    "Template:Dataset",
    "Template:Text",
]

# `|-` + `| Label || {{#statements:property}}` (the two-line table row).
_STATEMENT_ROW = re.compile(
    r"\|\-\n\| (?P<label>[^\n|]+?) \|\| \{\{#statements:(?P<prop>[^\n}]+)\}\}"
)
# `|-` + `| Label || {{#osm-place:birth|death|jurisdiction}}`.
_OSM_ROW = re.compile(
    r"\|\-\n\| (?P<label>[^\n|]+?) \|\| \{\{#osm-place:(?P<which>birth|death|jurisdiction)\}\}"
)

_QUOTATIONS_MARKER = "{{#quotations-by:"


def rewrite_template(wikitext: str) -> str:
    """Rewrites the statement/OSM rows into `{{#statement-row:}}` calls."""
    text = _STATEMENT_ROW.sub(
        lambda m: "{{#statement-row:" + m.group("label") + "|" + m.group("prop") + "}}",
        wikitext,
    )
    text = _OSM_ROW.sub(
        lambda m: "{{#statement-row:" + m.group("label") + "|osm-" + m.group("which") + "}}",
        text,
    )
    return text


def add_quotations_by_row(wikitext: str) -> str:
    """Adds the `{{#quotations-by:}}` row to Template:Person (idempotent)."""
    if _QUOTATIONS_MARKER in wikitext:
        return wikitext
    marker = "\n|}"
    index = wikitext.find(marker)
    if index == -1:
        # No table end found: leave the template alone (a malformed template
        # must never be half-rewritten).
        return wikitext
    return wikitext[:index] + "\n{{#quotations-by:}}" + wikitext[index:]


def fetch_wikitext(api: WikibaseApi, title: str) -> str:
    """The current wikitext of a page (raises when it does not exist)."""
    query = (
        "action=query&prop=revisions&rvslots=main&rvprop=content&titles="
        + urllib.parse.quote(title)
    )
    data = api._get(query)
    pages = data.get("query", {}).get("pages", {})
    page = next(iter(pages.values()), {})
    if "revisions" not in page:
        raise WikibaseApiError(f"page not found: {title}")
    return page["revisions"][0]["slots"]["main"]["*"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--api-url", default="https://wikibase.ronzz.org/api.php")
    parser.add_argument("--user")
    parser.add_argument("--password-file")
    parser.add_argument("--templates", nargs="*", default=DEFAULT_TEMPLATES,
                        help="page titles to rewrite (default: the infobox set)")
    parser.add_argument("--apply", action="store_true",
                        help="write the changes (default: dry-run)")
    args = parser.parse_args(argv)

    if args.apply and (not args.user or not args.password_file):
        print("error: --apply requires --user and --password-file", file=sys.stderr)
        return 2

    password = ""
    if args.password_file:
        with open(args.password_file, encoding="utf-8") as handle:
            password = handle.read().strip()

    api = WikibaseApi(args.api_url, args.user, password)
    if args.apply:
        try:
            api.login()
        except WikibaseApiError as exc:
            print(f"error: login failed: {exc}", file=sys.stderr)
            return 1

    changed = 0
    for title in args.templates:
        try:
            original = fetch_wikitext(api, title)
        except WikibaseApiError as exc:
            print(f"  [warn] {title}: {exc}", file=sys.stderr)
            continue
        updated = rewrite_template(original)
        if title == "Template:Person":
            updated = add_quotations_by_row(updated)
        if updated == original:
            print(f"  [skip] {title}: already migrated")
            continue
        changed += 1
        print(f"  [change] {title}")
        for line in difflib.unified_diff(
            original.splitlines(), updated.splitlines(),
            fromfile=f"{title} (current)", tofile=f"{title} (new)", lineterm="",
        ):
            print("    " + line)
        if args.apply:
            api.edit_page(title, updated, "infobox: hide empty rows ({{#statement-row:}})")

    if args.apply:
        print(f"\nrewrite complete: {changed} template(s) updated")
    else:
        print(f"\n{changed} template(s) would change (dry-run — nothing written); "
              "pass --apply to write")
    return 0


if __name__ == "__main__":
    sys.exit(main())
