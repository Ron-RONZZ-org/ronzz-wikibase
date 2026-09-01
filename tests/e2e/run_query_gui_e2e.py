#!/usr/bin/env python3
"""E2E acceptance for the query.ronzz.org frontend stack (WDQS query GUI).

HTTP-level checks for the SPARQL query UX against a running instance. The
browser-level UX suite (ctrl+space autocomplete, run-and-render) lives in
``run_query_gui_ux_e2e.mjs`` (Playwright) — this suite covers everything
curl-able: the "user can build queries, run them, and get correct results
from the instance's entities and properties" contract:

* SPARQL correctness — bare ``wd:``/``wdt:`` prefixes (the store's
  ``prefixes.conf``) and explicit ``PREFIX`` clauses both return the
  instance's entity URIs (not wikidata.org's);
* the read-only ``/sparql`` proxy guard (``?update=`` / sparql-update -> 403);
* the autocomplete API contract — ``wbsearchentities`` with ``origin=*``
  answers CORS ``*`` from the GUI origin (what the editor autocomplete
  depends on);
* GUI static serving + the runtime config merge (``custom-config.json``
  values: ``api.wikibase.uri``, ``prefixes.wd``, ``api.sparql.uri``);
* the public ``SPARQL examples`` page (the Examples dialog) and the Query
  Builder at ``/querybuilder/``.

Checks self-skip when the endpoint they need wasn't provided, so the suite
runs both against production (everything) and in CI against the dev stack
(SPARQL + API checks only). Read-only, no credentials.

Usage::

    # production (full)
    python3 tests/e2e/run_query_gui_e2e.py check \
        --gui-base-url https://query.ronzz.org \
        --sparql-url https://query.ronzz.org/sparql \
        --api-url https://wikibase.ronzz.org/w/api.php \
        --gui-origin https://query.ronzz.org

    # dev stack in CI (SPARQL + API contract only)
    python3 tests/e2e/run_query_gui_e2e.py check \
        --sparql-url http://127.0.0.1:9999/bigdata/namespace/wdq/sparql \
        --api-url http://127.0.0.1:8082/api.php \
        --gui-origin http://127.0.0.1:8082 \
        --entity-base http://wikibase/entity/ \
        --instance-of P1 --person-class Q87

Exit code 0 = all provided checks passed.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.parse
import urllib.request


class CheckFailed(Exception):
    pass


def http_get(url: str, headers: dict | None = None, timeout: int = 60) -> tuple[int, bytes, dict]:
    """GET url; return (status, body, response headers)."""
    request = urllib.request.Request(url, headers=headers or {"User-Agent": "ronzz-wikibase-e2e/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as resp:
            return resp.status, resp.read(), dict(resp.headers.items())
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read(), dict(exc.headers.items())
    except urllib.error.URLError as exc:
        raise CheckFailed(f"cannot reach {url}: {exc.reason}") from exc


def http_post(url: str, body: bytes, content_type: str, timeout: int = 60) -> tuple[int, bytes, dict]:
    """POST url with a raw body; return (status, body, response headers)."""
    request = urllib.request.Request(
        url,
        data=body,
        headers={"Content-Type": content_type, "User-Agent": "ronzz-wikibase-e2e/1.0"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as resp:
            return resp.status, resp.read(), dict(resp.headers.items())
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read(), dict(exc.headers.items())
    except urllib.error.URLError as exc:
        raise CheckFailed(f"cannot reach {url}: {exc.reason}") from exc


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise CheckFailed(message)


def sparql_query(sparql_url: str, query: str) -> dict:
    """Run a SPARQL SELECT via GET; return the parsed JSON (or raise)."""
    url = sparql_url + "?" + urllib.parse.urlencode({"query": query, "format": "json"})
    status, body, _ = http_get(url)
    expect(status == 200, f"sparql returned HTTP {status} for {query!r}")
    try:
        data = json.loads(body.decode("utf-8"))
    except (ValueError, UnicodeDecodeError) as exc:
        raise CheckFailed(f"sparql response is not JSON: {body[:200]!r}") from exc
    expect("results" in data and "bindings" in data["results"], f"unexpected sparql envelope: {list(data)[:4]}")
    return data


def check_sparql_bare_prefixes(args: argparse.Namespace) -> None:
    """Bare wd:/wdt: prefixes (resolved by the store's prefixes.conf) return
    the instance's entity URIs — the "get correct results" contract."""
    query = f"SELECT ?item WHERE {{ ?item wdt:{args.instance_of} wd:{args.person_class} }} LIMIT 5"
    data = sparql_query(args.sparql_url, query)
    bindings = data["results"]["bindings"]
    expect(len(bindings) > 0, f"bare-prefix query returned 0 rows: {query}")
    for binding in bindings:
        uri = binding["item"]["value"]
        expect(
            uri.startswith(args.entity_base),
            f"bare-prefix result URI {uri} does not start with {args.entity_base}",
        )


def check_sparql_explicit_prefixes(args: argparse.Namespace) -> None:
    """Explicit PREFIX clauses resolve identically (belt-and-braces on top of
    the store's prefixes.conf)."""
    query = (
        f"PREFIX wd: <{args.entity_base}>\n"
        f"PREFIX wdt: <{args.prop_direct}>\n"
        f"SELECT ?item WHERE {{ ?item wdt:{args.instance_of} wd:{args.person_class} }} LIMIT 5"
    )
    data = sparql_query(args.sparql_url, query)
    bindings = data["results"]["bindings"]
    expect(len(bindings) > 0, f"explicit-prefix query returned 0 rows")
    for binding in bindings:
        uri = binding["item"]["value"]
        expect(
            uri.startswith(args.entity_base),
            f"explicit-prefix result URI {uri} does not start with {args.entity_base}",
        )


def check_readonly_guard(args: argparse.Namespace) -> None:
    """The /sparql proxy refuses writes: ?update= (GET) and
    application/sparql-update (POST) must both be 403."""
    status, _, _ = http_get(args.sparql_url + "?" + urllib.parse.urlencode({"update": "DELETE WHERE { ?s ?p ?o }"}))
    expect(status == 403, f"?update= returned HTTP {status}, expected 403 (read-only guard)")
    status, _, _ = http_post(
        args.sparql_url,
        body=b"DELETE WHERE { ?s ?p ?o }",
        content_type="application/sparql-update",
    )
    expect(status == 403, f"sparql-update POST returned HTTP {status}, expected 403 (read-only guard)")


def check_autocomplete_api(args: argparse.Namespace) -> None:
    """The editor autocomplete depends on wbsearchentities answering CORS '*'
    for the GUI origin (the GUI appends origin=* itself). Mirrors the GUI's
    request shape: action/language/type + origin=*."""
    url = (
        args.api_url
        + "?"
        + urllib.parse.urlencode(
            {
                "action": "wbsearchentities",
                "search": "person",
                "type": "item",
                "language": "en",
                "uselang": "en",
                "format": "json",
                "origin": "*",
            }
        )
    )
    status, body, headers = http_get(url, headers={"Origin": args.gui_origin})
    expect(status == 200, f"wbsearchentities returned HTTP {status}")
    acao = headers.get("Access-Control-Allow-Origin")
    expect(acao == "*", f"wbsearchentities CORS: Access-Control-Allow-Origin={acao!r}, expected '*'")
    try:
        data = json.loads(body.decode("utf-8"))
    except (ValueError, UnicodeDecodeError) as exc:
        raise CheckFailed(f"wbsearchentities response is not JSON: {body[:200]!r}") from exc
    expect("search" in data, "wbsearchentities response has no 'search' member")


def check_gui_static(args: argparse.Namespace) -> None:
    """The GUI is served and the runtime config merge carries the instance
    wiring (api.wikibase.uri, prefixes.wd, api.sparql.uri)."""
    for path in ("/", "/default-config.json", "/custom-config.json"):
        status, _, _ = http_get(args.gui_base_url + path)
        expect(status == 200, f"GET {path} returned HTTP {status}")

    status, body, _ = http_get(args.gui_base_url + "/custom-config.json")
    expect(status == 200, "custom-config.json not served")
    try:
        config = json.loads(body.decode("utf-8"))
    except (ValueError, UnicodeDecodeError) as exc:
        raise CheckFailed(f"custom-config.json is not JSON: {body[:200]!r}") from exc
    expect(
        config.get("api", {}).get("wikibase", {}).get("uri", "").rstrip("/") == args.api_url.rstrip("/"),
        f"custom-config api.wikibase.uri {config.get('api', {}).get('wikibase', {}).get('uri')!r} != {args.api_url!r}",
    )
    expect(
        config.get("api", {}).get("sparql", {}).get("uri") == "/sparql",
        f"custom-config api.sparql.uri {config.get('api', {}).get('sparql', {}).get('uri')!r} != '/sparql'",
    )
    expect(
        config.get("prefixes", {}).get("wd") == args.entity_base,
        f"custom-config prefixes.wd {config.get('prefixes', {}).get('wd')!r} != {args.entity_base!r}",
    )


def check_examples_page(args: argparse.Namespace) -> None:
    """The Examples dialog fetches the public SPARQL examples page
    anonymously — it must parse without login. The page is production
    content (created on the instance); on a dev stack without it, skip
    instead of failing (missing content, not a code defect)."""
    url = args.api_url + "?" + urllib.parse.urlencode({"action": "parse", "page": "SPARQL examples", "format": "json"})
    status, body, _ = http_get(url)
    expect(status == 200, f"action=parse of 'SPARQL examples' returned HTTP {status}")
    try:
        data = json.loads(body.decode("utf-8"))
    except (ValueError, UnicodeDecodeError) as exc:
        raise CheckFailed(f"examples parse response is not JSON: {body[:200]!r}") from exc
    if "error" in data and data["error"].get("code") == "missingtitle":
        print("      (skip: the dev/CI stack has no 'SPARQL examples' page — production content)")
        return
    expect("parse" in data and "text" in data["parse"], "examples parse response lacks parse.text")


def check_query_builder(args: argparse.Namespace) -> None:
    """The visual Query Builder is served at /querybuilder/."""
    status, body, _ = http_get(args.gui_base_url + "/querybuilder/")
    expect(status == 200, f"GET /querybuilder/ returned HTTP {status}")
    expect(b"<div id=\"app\"" in body or b"<title>" in body, "/querybuilder/ did not return app HTML")


def check(args: argparse.Namespace) -> int:
    failures: list[str] = []

    def run(name: str, fn) -> None:
        try:
            fn()
            print(f"  [ok] {name}")
        except CheckFailed as exc:
            failures.append(name)
            print(f"  [FAIL] {name}: {exc}")

    if args.sparql_url:
        if args.gui_base_url:
            # production: sparql-url is the read-only /sparql proxy — guard applies.
            run("read-only /sparql guard (update= and sparql-update -> 403)", lambda: check_readonly_guard(args))
        run(
            "bare wd:/wdt: prefixes return instance entity URIs",
            lambda: check_sparql_bare_prefixes(args),
        )
        run(
            "explicit PREFIX clauses resolve identically",
            lambda: check_sparql_explicit_prefixes(args),
        )
    if args.api_url and args.gui_origin:
        run("wbsearchentities CORS contract for the GUI origin", lambda: check_autocomplete_api(args))
    if args.api_url:
        run("SPARQL examples page parses anonymously", lambda: check_examples_page(args))
    if args.gui_base_url:
        run("GUI static + runtime config merge", lambda: check_gui_static(args))
        run("Query Builder served at /querybuilder/", lambda: check_query_builder(args))

    if failures:
        print(f"\n{len(failures)} check(s) failed: {', '.join(failures)}")
        return 1
    print("\nAll query GUI checks passed.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", choices=["check"], help="run the checks")
    parser.add_argument("--gui-base-url", help="GUI base URL (https://query.ronzz.org) — enables the GUI-serving checks")
    parser.add_argument("--sparql-url", help="SPARQL endpoint (the /sparql proxy on production, the dev WDQS in CI)")
    parser.add_argument("--api-url", help="Wikibase api.php URL")
    parser.add_argument("--gui-origin", help="Origin the GUI runs from (for the CORS contract check)")
    parser.add_argument("--entity-base", default="https://wikibase.ronzz.org/entity/", help="expected entity URI prefix")
    parser.add_argument("--prop-direct", default="https://wikibase.ronzz.org/prop/direct/", help="prop/direct namespace URI")
    parser.add_argument("--instance-of", default="P1", help="'instance of' property id")
    parser.add_argument("--person-class", default="Q6", help="'person' class item id")
    args = parser.parse_args()
    try:
        return check(args)
    except CheckFailed as exc:
        print(f"Fatal: {exc}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
