"""Self-verification stage of the seed (issue #6, §9.1).

Curls the embed surface, the citation API, and the SPARQL endpoint against
the seeded dogfood entities. Doubles as the v1 acceptance harness; the
dedicated E2E suite (tests/e2e, D5) covers the same ground more exhaustively.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Callable, Optional


class VerifyError(Exception):
    """Raised when a self-verification check fails."""


def _get(url: str, timeout: int = 60) -> tuple[int, bytes, str]:
    request = urllib.request.Request(url, headers={"User-Agent": "ronzz-wikibase-seed/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as resp:
            return resp.status, resp.read(), resp.headers.get("Content-Type", "")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read(), exc.headers.get("Content-Type", "")


def _check(name: str, fn: Callable[[], None]) -> bool:
    try:
        fn()
        print(f"  [ok] {name}")
        return True
    except VerifyError as exc:
        print(f"  [FAIL] {name}: {exc}")
        return False


def self_verify(
    api_url: str,
    base_url: str,
    sparql_url: str,
    quote_id: str,
    quotation_class_id: str,
    instance_of_id: str,
    timeout: int = 60,
    sparql_wait: int = 0,
) -> bool:
    """Runs the acceptance checks; returns True when all pass.

    `sparql_wait` (seconds) retries the SPARQL check to allow the WDQS
    updater to sync recent changes (needed on fresh instances/CI).
    """
    print(f"Self-verification against {api_url} …")
    ok = True

    def check_embed_json() -> None:
        status, body, _ = _get(
            f"{api_url}?action=embed&entity={quote_id}&format=json", timeout
        )
        if status != 200:
            raise VerifyError(f"HTTP {status}")
        try:
            payload = json.loads(body)
        except json.JSONDecodeError as exc:
            raise VerifyError(f"invalid JSON: {exc}") from exc
        html = payload.get("embed", {}).get("html", "")
        if "Analytical Engine" not in html:
            raise VerifyError("fragment HTML missing the quotation text")

    def check_embed_page() -> None:
        # Special:Embed — the canonical /embed/QN surface is an nginx rewrite
        # of this page (ops-level, validated on production).
        status, _, _ = _get(f"{base_url}/wiki/Special:Embed/{quote_id}", timeout)
        if status != 200:
            raise VerifyError(f"HTTP {status}")

    def check_citation_json() -> None:
        status, body, _ = _get(
            f"{api_url}?action=citation&entity={quote_id}&style=json&format=json", timeout
        )
        if status != 200:
            raise VerifyError(f"HTTP {status}")
        try:
            payload = json.loads(body)
        except json.JSONDecodeError as exc:
            raise VerifyError(f"invalid JSON: {exc}") from exc
        if not payload.get("citation"):
            raise VerifyError("empty citation payload")

    def check_citation_apa() -> None:
        status, body, _ = _get(
            f"{api_url}?action=citation&entity={quote_id}&style=apa&format=text", timeout
        )
        if status != 200 or not body.strip():
            raise VerifyError(f"HTTP {status}, empty body")

    def check_sparql_once() -> bool:
        query = (
            f"SELECT ?item WHERE {{ ?item wdt:{instance_of_id} wd:{quotation_class_id} }} LIMIT 5"
        )
        status, body, content_type = _get(
            f"{sparql_url}?query={urllib.parse.quote(query)}&format=json", timeout
        )
        if status != 200:
            return False
        if "sparql-results" not in content_type and "json" not in content_type:
            return False
        return quote_id in body.decode("utf-8", "replace")

    def check_sparql() -> None:
        deadline = time.time() + sparql_wait
        while True:
            if check_sparql_once():
                return
            if time.time() >= deadline:
                raise VerifyError(
                    f"dogfood quotation {quote_id} not found via SPARQL within {sparql_wait}s"
                )
            time.sleep(10)

    ok = _check("embed api (json fragment)", check_embed_json) and ok
    ok = _check("embed canonical page (/embed/QN)", check_embed_page) and ok
    ok = _check("citation api (style=json)", check_citation_json) and ok
    ok = _check("citation api (style=apa)", check_citation_apa) and ok
    ok = _check("sparql (instance-of quotation)", check_sparql) and ok
    return ok


def main(argv: Optional[list[str]] = None) -> int:
    import argparse

    parser = argparse.ArgumentParser(description="Seed self-verification (standalone)")
    parser.add_argument("--api-url", required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--sparql-url", required=True)
    parser.add_argument("--quote", required=True)
    parser.add_argument("--quotation-class", required=True)
    parser.add_argument("--instance-of", required=True)
    parser.add_argument("--sparql-wait", type=int, default=0, help="retry SPARQL check for N seconds (WDQS updater sync)")
    args = parser.parse_args(argv)
    ok = self_verify(
        args.api_url,
        args.base_url,
        args.sparql_url,
        args.quote,
        args.quotation_class,
        args.instance_of,
        sparql_wait=args.sparql_wait,
    )
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
