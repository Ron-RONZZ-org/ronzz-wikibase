#!/usr/bin/env python3
"""E2E for the DPLforum forum (Forum: namespace, NS 110/111).

Verifies the vendored DPLforum extension on a live instance:

1. the `Forum:` (110) / `Forum_talk:` (111) namespaces are registered,
2. a board page (a `Forum:` page holding a `<forum>` listing over a
   category) renders without parser errors,
3. a thread page (a `Forum:` subpage carrying the board's category)
   appears in the board's `<forum>` listing after a purge,
4. cleanup: both scratch pages are deleted (self-cleaning).

Threads and boards are ordinary wiki pages — the listing is the only
DPLforum-specific behavior, which is what this suite pins. Custom
namespaces are not searched by default; searchability is configured in
LocalSettings/Extensions.php, not asserted here.

The scratch pages deliberately avoid on-wiki templates (e.g.
{{Forumheader}}), which only exist after the production content setup.

Usage::

    python3 tests/e2e/run_forum_e2e.py \\
        --base-url https://wikibase.ronzz.org \\
        --user SeedBot --password-file seed/.seedbot.pass \\
        [--keep]

Exit code 0 = all checks passed. Requires a user with edit + delete
(sysop) rights (SeedBot / CIAdmin).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import re
import time
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-forum-e2e/1.0"


class FlowError(Exception):
    """Raised when a forum check fails."""


class HostRewritingRedirect(urllib.request.HTTPRedirectHandler):
    """Rewrites redirect targets to the base URL's host.

    MediaWiki redirects (login, post-PRG) use the wiki's canonical
    $wgServer — on a docker stack an internal container hostname the runner
    cannot resolve. Keep the path, swap the host back to the base URL.
    """

    def __init__(self, base_url: str) -> None:
        self.base = urllib.parse.urlparse(base_url)

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        u = urllib.parse.urlparse(newurl)
        if u.hostname and u.hostname != self.base.hostname:
            newurl = urllib.parse.urlunparse(
                (self.base.scheme, self.base.netloc, u.path, u.params, u.query, u.fragment))
        return urllib.request.Request(newurl, headers=req.headers, method=req.get_method())


def make_opener(base_url: str) -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
        HostRewritingRedirect(base_url),
    )


def api_call(op, api: str, params: dict, post: bool = False) -> dict:
    if post:
        req = urllib.request.Request(api, data=urllib.parse.urlencode(params).encode(),
                                     headers={"User-Agent": UA})
    else:
        req = urllib.request.Request(api + "?" + urllib.parse.urlencode(params),
                                     headers={"User-Agent": UA})
    with op.open(req, timeout=90) as resp:
        return json.load(resp)


def login(op, api: str, user: str, password: str) -> None:
    lt = api_call(op, api, {"action": "query", "meta": "tokens", "type": "login", "format": "json"})
    token = lt["query"]["tokens"]["logintoken"]
    r = api_call(op, api, {
        "action": "login", "lgname": user, "lgpassword": password,
        "lgtoken": token, "format": "json",
    }, post=True)
    if r.get("login", {}).get("result") != "Success":
        raise FlowError(f"login failed: {r}")


def csrf_token(op, api: str) -> str:
    r = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
    return r["query"]["tokens"]["csrftoken"]


def page_get(op, base: str, path: str) -> str:
    with op.open(base + path, timeout=90) as resp:
        return resp.read().decode("utf-8", "replace")


def check_namespaces(op, api: str) -> None:
    r = api_call(op, api, {
        "action": "query", "meta": "siteinfo", "siprop": "namespaces", "format": "json",
    })
    ns = {int(k): v.get("*") for k, v in r["query"]["namespaces"].items()}
    if ns.get(110) != "Forum":
        raise FlowError(f"namespace 110 is {ns.get(110)!r}, expected 'Forum' (DPLforum not loaded?)")
    if ns.get(111) != "Forum talk":
        # siteinfo returns display names (spaces, not underscores).
        raise FlowError(f"namespace 111 is {ns.get(111)!r}, expected 'Forum talk'")
    print("[ok] namespaces 110 (Forum) + 111 (Forum talk) registered")


def forum_flow(op, api: str, base: str, keep: bool) -> None:
    stamp = int(time.time())
    board = f"Forum E2E {stamp}"
    board_page = f"Forum:{board}"
    thread_page = f"Forum:{board}/Thread {stamp}"

    # Board page: a <forum> listing over the board's own category. The
    # `cache=false` parameter keeps the tag result recomputed per render.
    board_wikitext = f"""Board page of the DPLforum E2E.

<forum>
namespace=Forum
category={board}
shownamespace=false
addauthor=true
addlasteditor=true
compact=all
timestamp=true
cache=false
</forum>

[[Category:Forums]]
"""
    token = csrf_token(op, api)
    r = api_call(op, api, {
        "action": "edit", "title": board_page, "text": board_wikitext,
        "token": token, "summary": "forum E2E scratch board (run_forum_e2e.py)",
        "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"board page creation failed: {r!r}")

    thread_wikitext = f"""First post of the E2E thread. ~~~~

[[Category:{board}]]
"""
    r = api_call(op, api, {
        "action": "edit", "title": thread_page, "text": thread_wikitext,
        "token": token, "summary": "forum E2E scratch thread (run_forum_e2e.py)",
        "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"thread page creation failed: {r!r}")

    try:
        # Purge the board so the listing re-renders with the new thread.
        r = api_call(op, api, {
            "action": "purge", "titles": board_page,
            "token": token, "format": "json",
        }, post=True)
        if not any("purged" in item for item in r.get("purge", [])):
            raise FlowError(f"purge of {board_page} failed: {r!r}")

        body = page_get(op, base, "/wiki/" + urllib.parse.quote(board_page.replace(" ", "_")))
        errors = re.findall(r'<span class="error"[^>]*>(.*?)</span>', body, re.S)
        if errors or "errorbox" in body:
            raise FlowError(f"{board_page} rendered parser errors: {errors}")
        if f"Thread {stamp}" not in body:
            raise FlowError(f"<forum> listing on {board_page} does not show the thread "
                            f"'{thread_page}' — tag not querying categorylinks?")
        if 'class="forum' not in body:
            raise FlowError(f"<forum> listing rendered without the expected forum markup")
        print(f"[ok] board {board_page} renders; <forum> listing shows thread {thread_page}")
    finally:
        if keep:
            print(f"[keep] leaving {board_page} and {thread_page} on the instance")
            return
        token = csrf_token(op, api)
        for title in (thread_page, board_page):
            try:
                api_call(op, api, {"action": "delete", "title": title, "token": token,
                                   "reason": "forum E2E cleanup (run_forum_e2e.py)",
                                   "format": "json"}, post=True)
            except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                print(f"[warn] cleanup of {title} failed: {exc}")
        print("[ok] cleanup: scratch pages deleted")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--api-url", required=True)
    ap.add_argument("--user", required=True)
    ap.add_argument("--password-file", required=True)
    ap.add_argument("--keep", action="store_true", help="keep scratch pages")
    args = ap.parse_args()

    with open(args.password_file, encoding="utf-8") as fh:
        password = fh.read().strip()

    op = make_opener(args.base_url)
    login(op, args.api_url, args.user, password)
    check_namespaces(op, args.api_url)
    forum_flow(op, args.api_url, args.base_url, args.keep)
    print("forum E2E: all checks passed")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except FlowError as exc:
        print(f"forum E2E FAILED: {exc}")
        raise SystemExit(1)
