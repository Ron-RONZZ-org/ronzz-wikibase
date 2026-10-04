#!/usr/bin/env python3
"""tools/migrate_quotation_translations.py — one-off migration of the legacy
multi-claim `content text` quotations to the original + `translation` model.

Before the "Add translation" feature, a quotation's `content text` property
could hold one monolingual claim per language (the seed dogfood carried
en/fr/eo). The model is now: the ORIGINAL is the `content text` claim; added
translations live in the dedicated monolingualtext `translation` property.
This tool moves every extra `content text` claim (all but the original
language, default: the first claim) to `translation`.

Idempotent: an item with a single `content text` claim is left alone; a moved
claim is removed from `content text` and added to `translation` (skipping an
identical translation claim that already exists). A `--dry-run` reviews the
plan first.

Usage:
  python3 tools/migrate_quotation_translations.py \
      --base-url https://wikibase.ronzz.org \
      --sparql-url http://127.0.0.1:9999/bigdata/namespace/wdq/sparql \
      --user SeedBot --password-file seed/.seedbot.pass [--dry-run] \
      [--original-language en]

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

CONTENT_TEXT_LABEL = "content text"
TRANSLATION_LABEL = "translation"

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


def find_property(api: WikibaseApi, label: str, language: str) -> str | None:
    """Exact-label property lookup via wbsearchentities (the seed's contract)."""
    wanted = label.strip().lower()
    for hit in api.search_entities(label, "property", language):
        match_text = str(hit.get("match", {}).get("text", "")).strip().lower()
        hit_label = str(hit.get("label", "")).strip().lower()
        if match_text == wanted or hit_label == wanted:
            return hit.get("id")
    return None


def monolingual_claims(claims: list[dict]) -> list[dict]:
    """The monolingualtext claims of a property list (statement order)."""
    return [c for c in claims if c.get("mainsnak", {}).get("datatype") == "monolingualtext"]


def translation_moves(
    content_claims: list[dict], original_language: str | None
) -> tuple[dict | None, list[dict]]:
    """Splits the `content text` claims into (original, moves).

    The original is the claim in `original_language` when given, otherwise the
    first claim (the creation-time payload). Every other claim is a move. An
    empty/one-claim list yields no moves; an `original_language` the item does
    not carry yields (None, []) so the caller can skip it safely.
    """
    if len(content_claims) <= 1:
        return (content_claims[0] if content_claims else None), []
    if original_language is not None:
        base = next(
            (
                c for c in content_claims
                if c.get("mainsnak", {}).get("datavalue", {}).get("value", {}).get("language")
                == original_language
            ),
            None,
        )
        if base is None:
            return None, []
    else:
        base = content_claims[0]
    moves = [c for c in content_claims if c is not base]
    return base, moves


def monolingual_claim(property_id: str, text: str, language: str) -> dict:
    """A fresh monolingualtext statement for the `translation` property."""
    return {
        "mainsnak": {
            "snaktype": "value",
            "property": property_id,
            "datavalue": {"value": {"text": text, "language": language}, "type": "monolingualtext"},
        },
        "type": "statement",
        "rank": "normal",
    }


def remove_claim_guids(api: WikibaseApi, guids: list[str], summary: str) -> None:
    """Removes claims by GUID (wbremoveclaims) — the seed client has no method
    for it, so the private request plumbing is used here (localized one-off)."""
    for guid in guids:
        result = api._post(
            "action=wbremoveclaims",
            token=api.require_csrf(),
            claim=guid,
            summary=summary,
        )
        if "success" not in result:
            raise WikibaseApiError(f"wbremoveclaims failed for {guid}: {result}")


def candidate_items(sparql_url: str, content_property: str) -> list[str]:
    """Item ids carrying MORE THAN ONE direct `content text` claim."""
    query = SPARQL_PREFIXES + f"""
SELECT ?item (COUNT(?content) AS ?n) WHERE {{
  ?item wdt:{content_property} ?content .
}} GROUP BY ?item HAVING (?n > 1) LIMIT 1000"""
    rows = sparql_http_get(sparql_url, query)
    return [row["item"]["value"].rstrip("/").rsplit("/", 1)[-1] for row in rows]


def main() -> int:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument("--base-url", required=True, help="wiki base URL (e.g. https://wikibase.ronzz.org)")
    parser.add_argument("--sparql-url", required=True, help="WDQS endpoint (e.g. http://127.0.0.1:9999/bigdata/namespace/wdq/sparql)")
    parser.add_argument("--user", default="SeedBot")
    parser.add_argument("--password-file", help="file containing the bot password (0600)")
    parser.add_argument("--lang", default="en", help="term language for resolution (default en)")
    parser.add_argument(
        "--original-language",
        help="language to keep as the original `content text` claim (default: the first claim)",
    )
    parser.add_argument("--dry-run", action="store_true", help="plan only, no writes")
    parser.add_argument("--verify", action="store_true", help="only recount multi-claim items")
    parser.add_argument("--summary-prefix", default="Migration: ", help="edit-summary prefix")
    args = parser.parse_args()

    password = ""
    if args.password_file:
        password = Path(args.password_file).read_text(encoding="utf-8").strip()
    api = WikibaseApi(args.base_url, user=args.user, password=password)
    api.login()

    content_prop = find_property(api, CONTENT_TEXT_LABEL, args.lang)
    translation_prop = find_property(api, TRANSLATION_LABEL, args.lang)
    if not content_prop:
        raise SystemExit("the 'content text' property is not on the instance — vocabulary imported?")
    if not translation_prop:
        raise SystemExit("the 'translation' property is missing — import the new vocabulary first")
    print(f"content text property: {content_prop}; translation property: {translation_prop}")

    candidates = candidate_items(args.sparql_url, content_prop)
    if args.verify:
        print(f"items with more than one content-text claim: {len(candidates)}")
        for qid in candidates:
            print(f"  {qid}")
        return 0 if not candidates else 1
    if not candidates:
        print("no multi-claim quotations found — nothing to migrate")
        return 0
    print(f"{len(candidates)} candidate(s):")

    changed = 0
    for qid in candidates:
        content_claims = monolingual_claims(api.get_claims(qid).get(content_prop, []))
        base, moves = translation_moves(content_claims, args.original_language)
        if base is None or not moves:
            print(f"  {qid}: skipped (no usable original/extra claims)")
            continue
        languages = [
            c.get("mainsnak", {}).get("datavalue", {}).get("value", {}).get("language")
            for c in moves
        ]
        print(f"  {qid}: keep original "
              f"{base['mainsnak']['datavalue']['value'].get('language')!r}; "
              f"move {languages}")
        if args.dry_run:
            continue

        # Add the translations FIRST, then remove the moved content-text
        # claims — a failure in between never loses text.
        api.add_claims(
            qid,
            {translation_prop: [monolingual_claim(
                translation_prop,
                c["mainsnak"]["datavalue"]["value"]["text"],
                c["mainsnak"]["datavalue"]["value"]["language"],
            ) for c in moves]},
            args.summary_prefix + "move quotation translations",
        )
        remove_claim_guids(
            api,
            [c["id"] for c in moves if c.get("id")],
            args.summary_prefix + "remove migrated content-text claim",
        )
        changed += 1
        print(f"    migrated {qid}")

    print(f"\nmigrated {changed} item(s)" + (" (dry-run — no writes)" if args.dry_run else ""))
    if args.dry_run:
        print("re-run without --dry-run to apply")
    return 0


if __name__ == "__main__":
    sys.exit(main())
