#!/usr/bin/env python3
"""E2E acceptance + XSS suite for the v1 instance (issue #6, D5).

Runs against a live (dev) instance with D1–D4 deployed and seeded. Three
surfaces, five citation styles, SPARQL, plus the mandatory XSS suite for
EmbeddableContent (§9.4): payload injections (<script>, onerror=,
javascript:) must never survive into rendered fragments.

Usage::

    python3 tests/e2e/run_e2e.py check --base-url https://wikibase.ronzz.org \\
        --quote Q5 --code Q6 --math Q7 --instance-of P31 --quotation-class Q1

    python3 tests/e2e/run_e2e.py xss --api-url ... --user 'User@bot' --password '***'

Exit code 0 = all checks passed.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent.parent))
from seed.wikibase_api import WikibaseApi  # noqa: E402

XSS_INJECTIONS = [
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    'javascript:alert(1)',
    '<svg onload=alert(1)>',
    '"><script>alert(1)</script>',
]
XSS_LABEL = 'XSS injection test item'


class CheckFailed(Exception):
    pass


def http_get(url: str, timeout: int = 60) -> tuple[int, bytes, str]:
    request = urllib.request.Request(url, headers={"User-Agent": "ronzz-wikibase-e2e/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as resp:
            return resp.status, resp.read(), resp.headers.get("Content-Type", "")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read(), exc.headers.get("Content-Type", "")


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise CheckFailed(message)


def check(args: argparse.Namespace) -> int:
    api = args.api_url
    base = args.base_url
    sparql = args.sparql_url
    failures = []

    def run(name: str, fn) -> None:
        try:
            fn()
            print(f"  [ok] {name}")
        except CheckFailed as exc:
            failures.append(name)
            print(f"  [FAIL] {name}: {exc}")
    def embed_html(entity: str, **extra) -> str:
        params = {"action": "embed", "entity": entity, "format": "html", **extra}
        status, body, _ = http_get(f"{api}?{urllib.parse.urlencode(params)}")
        expect(status == 200, f"embed {entity}: HTTP {status}")
        return body.decode("utf-8", "replace")

    def check_embed_surfaces() -> None:
        for entity in (args.quote, args.code, args.math):
            html = embed_html(entity)
            expect(html.strip() != "", f"empty fragment for {entity}")
            # Special:Embed — the canonical /embed/QN path is an nginx rewrite
            # of this page (ops-level, validated on production).
            status, body, ctype = http_get(f"{base}/wiki/Special:Embed/{entity}")
            expect(status == 200, f"Special:Embed/{entity}: HTTP {status}")
            status, _, ctype = http_get(
                f"{base}/wiki/Special:Embed/oembed?url={urllib.parse.quote(f'{base}/wiki/Item:{entity}')}"
            )
            expect(status == 200 and "json" in ctype, f"oembed for {entity}: HTTP {status}")

    def check_embed_negotiation() -> None:
        html_fr = embed_html(args.quote, lang="fr")
        html_eo = embed_html(args.quote, lang="eo")
        expect(html_fr != html_eo or "lang=" in html_fr, "language negotiation did not vary output")

    def check_citation_styles() -> None:
        for style in ("json", "apa", "vancouver", "bibtex", "ris"):
            params = {"action": "citation", "entity": args.quote, "style": style, "format": "text"}
            status, body, ctype = http_get(f"{api}?{urllib.parse.urlencode(params)}")
            expect(status == 200, f"citation {style}: HTTP {status}")
            text = body.decode("utf-8", "replace")
            if style == "json":
                try:
                    payload = json.loads(text)
                except json.JSONDecodeError as exc:
                    raise CheckFailed(
                        f"citation json: not JSON (HTTP {status}): {text[:300]!r}"
                    ) from exc
                expect(payload.get("citation", {}).get("type"), "json citation missing type")
            else:
                expect(text.strip() != "", f"citation {style}: empty output")

    def check_sparql() -> None:
        query = (
            "SELECT ?item WHERE { ?item wdt:" + args.instance_of + " wd:" + args.quotation_class + " } LIMIT 10"
        )

        def once() -> bool:
            status, body, ctype = http_get(f"{sparql}?query={urllib.parse.quote(query)}&format=json")
            if status != 200 or "json" not in ctype:
                return False
            return args.quote in body.decode("utf-8", "replace")

        deadline = time.time() + args.sparql_wait
        while not once():
            if time.time() >= deadline:
                raise CheckFailed(
                    f"dogfood quotation missing from SPARQL within {args.sparql_wait}s "
                    "(WDQS updater sync?)"
                )
            time.sleep(10)

    run("embed surfaces (api + /embed/ + oEmbed)", check_embed_surfaces)
    run("language negotiation (?lang=)", check_embed_negotiation)
    run("citation styles (json/apa/vancouver/bibtex/ris)", check_citation_styles)
    run("sparql instance-of check", check_sparql)

    if failures:
        print(f"\nE2E FAILED: {len(failures)} check(s): {', '.join(failures)}")
        return 1
    print("\nE2E: all checks passed.")
    return 0


def xss(args: argparse.Namespace) -> int:
    api = WikibaseApi(args.api_url, args.user, args.password)
    api.login()

    # Create (or reuse) an XSS test item — one quotation whose payload carries
    # every injection. Idempotent by label (skip-existing-label).
    existing = api.search_entities(XSS_LABEL, "item", "en")
    if existing:
        item_id = existing[0]["id"]
        print(f"reusing XSS item {item_id}")
    else:
        payload = " | ".join(XSS_INJECTIONS)
        item_id = api.create_item(
            {lang: XSS_LABEL for lang in ("en", "fr", "eo")},
            {lang: "XSS test item" for lang in ("en", "fr", "eo")},
            "E2E XSS suite: create test item",
        )
        # instance-of + payload — resolve property ids by label via the map.
        content_text = api.search_entities("content text", "property", "en")[0]["id"]
        instance_of = api.search_entities("instance of", "property", "en")[0]["id"]
        quotation_class = api.search_entities("quotation content", "item", "en")[0]["id"]
        api.add_claims(
            item_id,
            {
                instance_of: [
                    {
                        "mainsnak": {
                            "snaktype": "value",
                            "property": instance_of,
                            "datavalue": {
                                "value": {
                                    "entity-type": "item",
                                    "numeric-id": int(quotation_class[1:]),
                                    "id": quotation_class,
                                },
                                "type": "wikibase-entityid",
                            },
                        },
                        "type": "statement",
                        "rank": "normal",
                    }
                ],
                content_text: [
                    {
                        "mainsnak": {
                            "snaktype": "value",
                            "property": content_text,
                            "datavalue": {
                                "value": {"text": payload, "language": "en"},
                                "type": "monolingualtext",
                            },
                        },
                        "type": "statement",
                        "rank": "normal",
                    }
                ],
            },
            "E2E XSS suite: populate payload",
        )
        print(f"created XSS item {item_id}")

    # Fetch the rendered fragment through both surfaces.
    failures = []
    for label, url in [
        ("api", f"{args.api_url}?action=embed&entity={item_id}&format=html"),
        ("page", f"{args.base_url}/wiki/Special:Embed/{item_id}"),
    ]:
        status, body, _ = http_get(url)
        if status != 200:
            failures.append(f"{label}: HTTP {status}")
            continue
        html = body.decode("utf-8", "replace")
        for injection in XSS_INJECTIONS:
            if injection in html:
                failures.append(f"{label}: raw injection survived: {injection!r}")

    if failures:
        print(f"XSS FAILED ({len(failures)}):")
        for failure in failures:
            print(f"  - {failure}")
        print(f"  (test item kept for inspection: {item_id})")
        return 1

    print(f"XSS: all {len(XSS_INJECTIONS)} injections escaped on both surfaces (item {item_id}).")
    return 0


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="v1 E2E acceptance + XSS suite")
    sub = parser.add_subparsers(dest="command", required=True)

    check_p = sub.add_parser("check", help="acceptance checks against a seeded instance")
    check_p.add_argument("--api-url", required=True)
    check_p.add_argument("--base-url", required=True)
    check_p.add_argument("--sparql-url", required=True)
    check_p.add_argument("--quote", required=True)
    check_p.add_argument("--code", required=True)
    check_p.add_argument("--math", required=True)
    check_p.add_argument("--instance-of", required=True)
    check_p.add_argument("--quotation-class", required=True)
    check_p.add_argument(
        "--sparql-wait", type=int, default=0,
        help="retry the SPARQL check for N seconds (WDQS updater sync)",
    )
    check_p.set_defaults(handler=check)

    xss_p = sub.add_parser("xss", help="XSS suite (creates an injection test item)")
    xss_p.add_argument("--api-url", required=True)
    xss_p.add_argument("--base-url", required=True)
    xss_p.add_argument("--user", required=True)
    xss_p.add_argument("--password", required=True)
    xss_p.set_defaults(handler=xss)

    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    return args.handler(args)


if __name__ == "__main__":
    sys.exit(main())
