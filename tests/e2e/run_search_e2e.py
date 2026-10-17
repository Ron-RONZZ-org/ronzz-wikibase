#!/usr/bin/env python3
"""E2E for the Special:Search "Items" section (EmbeddableContent).

Core SearchMySQL indexes page TEXT; Item: pages carry no text, so an item
label can never appear in the normal results. `Hooks::onSpecialSearchResultsAppend`
appends a CONTAINS-matched "Items" section (the same term-store search the
entity comboboxes use), which must find a label by a mid-label fragment.

The fixture item's label deliberately starts with text that is NOT the search
term, so wbsearchentities' prefix matching cannot be what finds it.

Usage::

    python3 tests/e2e/run_search_e2e.py \\
        --base-url https://wikibase.ronzz.org \\
        --user SeedBot --password-file seed/.seedbot.pass [--keep]

Self-cleaning (the scratch item is deleted). Exit 0 = pass.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-search-e2e/1.0"


class SearchError(Exception):
    """Raised when a search check fails."""


class HostRewritingRedirect(urllib.request.HTTPRedirectHandler):
    """Keep redirects on the runner's base host (the docker stack's
    canonical $wgServer is an internal container hostname)."""

    def __init__(self, base_url: str) -> None:
        self.base = urllib.parse.urlparse(base_url)

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        u = urllib.parse.urlparse(newurl)
        if u.hostname and u.hostname != self.base.hostname:
            newurl = urllib.parse.urlunparse(
                (self.base.scheme, self.base.netloc, u.path, u.params, u.query, u.fragment))
        return urllib.request.Request(newurl, data=req.data, headers=req.headers,
                                      method=req.get_method())


def make_opener(base_url: str) -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
        HostRewritingRedirect(base_url),
    )


def api_call(op, api: str, params: dict, post: bool = False) -> dict:
    if post:
        req = urllib.request.Request(
            api, data=urllib.parse.urlencode(params).encode(),
            headers={"User-Agent": UA})
    else:
        req = urllib.request.Request(
            api + "?" + urllib.parse.urlencode(params),
            headers={"User-Agent": UA})
    with op.open(req, timeout=90) as resp:
        return json.load(resp)


def login(op, api: str, user: str, password: str) -> None:
    token = api_call(op, api, {
        "action": "query", "meta": "tokens", "type": "login", "format": "json",
    })["query"]["tokens"]["logintoken"]
    result = api_call(op, api, {
        "action": "login", "lgname": user, "lgpassword": password,
        "lgtoken": token, "format": "json",
    }, post=True).get("login", {})
    if result.get("result") != "Success":
        raise SearchError(f"login failed: {result}")


def csrf(op, api: str) -> str:
    return api_call(op, api, {
        "action": "query", "meta": "tokens", "type": "csrf", "format": "json",
    })["query"]["tokens"]["csrftoken"]


def create_item(op, api: str, token: str, label: str) -> str:
    data = {
        "labels": {"en": {"language": "en", "value": label}},
        "descriptions": {"en": {"language": "en", "value": "Search E2E fixture"}},
    }
    result = api_call(op, api, {
        "action": "wbeditentity", "new": "item", "token": token,
        "data": json.dumps(data), "summary": "search E2E fixture",
        "format": "json",
    }, post=True)
    qid = result.get("entity", {}).get("id")
    if not qid:
        raise SearchError(f"wbeditentity did not create an item: {result}")
    return qid


def delete_item(op, api: str, token: str, qid: str) -> None:
    api_call(op, api, {
        "action": "delete", "title": f"Item:{qid}", "token": token,
        "reason": "search E2E cleanup", "format": "json",
    }, post=True)


def search_html(op, base: str, term: str) -> str:
    url = base + "/wiki/Special:Search?" + urllib.parse.urlencode({
        "search": term, "fulltext": "1",
    })
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with op.open(req, timeout=120) as resp:
        return resp.read().decode("utf-8", "replace")


def diagnose_search(html: str, qid: str) -> str:
    """Compact failure context: profile, section markers, result count."""
    import re
    profile = re.search(r'name="profile"[^>]*value="([^"]*)"', html)
    if not profile:
        profile = re.search(r'value="([^"]*)"[^>]*name="profile"', html)
    info = re.search(r'data-mw-num-results-total="([^"]*)"', html)
    profile_value = profile.group(1) if profile else "?"
    total = info.group(1) if info else "?"
    flags = {
        "wb-search-items": "wb-search-items" in html,
        "entity-link": f"Special:EntityPage/{qid}" in html,
        "item-title": f"Item:{qid}" in html,
        "nonefound": "mw-search-nonefound" in html,
    }
    return f"profile={profile_value!r} total={total} flags={flags}"


def run(args: argparse.Namespace) -> None:
    if not args.password_file:
        raise SearchError("--password-file is required")
    password = open(args.password_file, encoding="utf-8").read().strip()
    op = make_opener(args.base_url)
    login(op, args.api_url, args.user, password)

    stamp = int(time.time())
    label = f"Wiki search E2E {stamp} Ringtone"
    term = "Ringtone"
    qid = create_item(op, args.api_url, csrf(op, args.api_url), label)
    print(f"[ok] scratch item {qid} created ({label!r})")
    try:
        # First prove the term-store search the hook uses can see the new
        # item; a miss here isolates the failure to the search index/term
        # store rather than the Special:Search hook.
        es = api_call(op, args.api_url, {
            "action": "entitysearch", "search": term, "language": "en",
            "limit": "20", "format": "json",
        })
        es_ids = [entry.get("id") for entry in es.get("search", [])]
        if qid not in es_ids:
            raise SearchError(
                f"action=entitysearch did not find {qid} for {term!r} "
                f"(got {es_ids})")
        print(f"[ok] action=entitysearch finds {qid} for {term!r}")

        html = search_html(op, args.base_url, term)
        link = f"Special:EntityPage/{qid}"
        if link not in html:
            # The href may be URL-encoded; accept the canonical Item: title too.
            if f"Item:{qid}" not in html:
                raise SearchError(
                    f"Special:Search?search={term} did not surface {qid} "
                    f"(item label {label!r}); "
                    f"{diagnose_search(html, qid)}")
        if "wb-search-items" not in html:
            raise SearchError(
                f"the 'Items' section markup (wb-search-items) is missing; "
                f"{diagnose_search(html, qid)}")
        if label not in html:
            raise SearchError(f"the item label {label!r} did not render in the search results")
        print(f"[ok] Special:Search surfaced {qid} for the mid-label term {term!r}")
    finally:
        if args.keep:
            print(f"[keep] leaving {qid}")
        else:
            try:
                delete_item(op, args.api_url, csrf(op, args.api_url), qid)
                print(f"[ok] cleanup: {qid} deleted")
            except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                print(f"[warn] cleanup of {qid} failed: {exc}")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="E2E: Special:Search Items section")
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    parser.add_argument("--api-url", default=None)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password-file", required=True)
    parser.add_argument("--keep", action="store_true")
    args = parser.parse_args(argv)
    if args.api_url is None:
        args.api_url = args.base_url.rstrip("/") + "/api.php"
    try:
        run(args)
    except (SearchError, urllib.error.URLError) as exc:
        print(f"[fail] {exc}")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
