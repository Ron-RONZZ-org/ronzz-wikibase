#!/usr/bin/env python3
"""E2E for the LanguageBar extension (automatic languages bar).

Checks the server-side contract of the bar on a live instance:

1. the `LanguageBar` extension is loaded (siteinfo),
2. a content page renders exactly ONE `<div class="languages-bar">` injected
   by the `OutputPageBeforeHTML` hook — in the Main (0) and Help (12)
   namespaces — with the `English` self-link and `/fr` + `/eo` links
   (red until a copy exists, blue once it does),
3. a page that already renders a bar (an explicit `{{Languages}}` /
   `{{#languagebar:}}` call, directly or via a transcluded template) keeps
   exactly ONE bar — the hook does not duplicate it,
4. a `/fr` translation copy (which carries the `{{Translation}}` banner)
   gets NO bar,
5. out-of-scope pages get NO bar: a `Template:` page and an `Item:` entity
   page,
6. cleanup: every scratch page is deleted (self-cleaning).

Usage::

    python3 tests/e2e/run_languagebar_e2e.py \\
        --base-url https://wikibase.ronzz.org \\
        --api-url https://wikibase.ronzz.org/api.php \\
        --user SeedBot --password-file seed/.seedbot.pass \\
        [--keep]

Exit code 0 = all checks passed. Requires a user with edit + delete rights
(SeedBot / CIAdmin).

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

UA = "ronzz-wikibase-languagebar-e2e/1.0"

# The injected bar (and the template/parser-function rendering) share this
# class; the hook skips a page whose HTML already contains it.
BAR_MARKER = 'class="languages-bar"'
BAR_DIV_RE = re.compile( r'<div class="languages-bar".*?</div>', re.S )

# A scratch template wrapping the parser function — the same content the
# on-wiki Template:Languages carries after the 2026-09 refactor.
TEMPLATE_FIXTURE = "{{#languagebar:}}\n"

# A manual copy of the bar markup (tests the hook's already-present skip
# without depending on the on-wiki template existing).
MANUAL_BAR = (
    '<div class="languages-bar" '
    'style="border:1px solid #a2a9b1;background:#f8f9fa;padding:0.3em 1em;margin:0 0 1em;">'
    '<p><b>Languages:</b> manual</p></div>\n'
)


class FlowError(Exception):
    """Raised when a LanguageBar check fails."""


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


def create_page(op, api: str, title: str, text: str, summary: str) -> None:
    token = csrf_token(op, api)
    r = api_call(op, api, {
        "action": "edit", "title": title, "text": text,
        "token": token, "summary": summary, "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"creation of {title} failed: {r!r}")


def delete_page(op, api: str, title: str) -> None:
    token = csrf_token(op, api)
    api_call(op, api, {
        "action": "delete", "title": title, "token": token,
        "reason": "LanguageBar E2E cleanup (run_languagebar_e2e.py)", "format": "json",
    }, post=True)


def rendered_path(title: str) -> str:
    return "/wiki/" + urllib.parse.quote(title.replace(" ", "_"), safe="/:")


def count_bars(body: str) -> int:
    return body.count(BAR_MARKER)


def check_extension_loaded(op, api: str) -> None:
    r = api_call(op, api, {
        "action": "query", "meta": "siteinfo", "siprop": "extensions", "format": "json",
    })
    names = [ext.get("name") for ext in r["query"]["extensions"]]
    if "LanguageBar" not in names:
        raise FlowError(f"LanguageBar extension not loaded (siteinfo extensions: {names})")
    print("[ok] LanguageBar extension loaded")


def assert_bar(body: str, page: str, *, fr_red: bool, eo_red: bool) -> None:
    """Exactly one bar, the English self-link, and live /fr + /eo links."""
    count = count_bars(body)
    if count != 1:
        raise FlowError(f"{page}: expected exactly one languages-bar, found {count}")
    match = BAR_DIV_RE.search(body)
    if match is None:
        raise FlowError(f"{page}: languages-bar div not found in the rendered page")
    bar = match.group(0)
    if not re.search(r"<b>(Languages|Langues|Lingvoj):</b>", bar):
        raise FlowError(f"{page}: bar label missing or not localized: {bar[:200]!r}")
    if "mw-selflink selflink" not in bar or ">English<" not in bar:
        raise FlowError(f"{page}: English self-link missing: {bar[:300]!r}")
    fr = re.search(r'<a[^>]*href="([^"]*)"[^>]*>français</a>', bar)
    eo = re.search(r'<a[^>]*href="([^"]*)"[^>]*>Esperanto</a>', bar)
    if fr is None or eo is None:
        raise FlowError(f"{page}: /fr or /eo link missing: {bar[:300]!r}")
    if not fr.group(1).endswith("/fr"):
        raise FlowError(f"{page}: français link does not target /fr: {fr.group(1)!r}")
    if not eo.group(1).endswith("/eo"):
        raise FlowError(f"{page}: Esperanto link does not target /eo: {eo.group(1)!r}")
    fr_is_red = 'class="new"' in fr.group(0)
    eo_is_red = 'class="new"' in eo.group(0)
    if fr_is_red != fr_red:
        raise FlowError(f"{page}: français link red state is {fr_is_red}, expected {fr_red}")
    if eo_is_red != eo_red:
        raise FlowError(f"{page}: Esperanto link red state is {eo_is_red}, expected {eo_red}")
    print(f"[ok] {page}: one bar, English self-link, /fr + /eo (fr red={fr_is_red}, eo red={eo_is_red})")


def languagebar_flow(op, api: str, base: str, keep: bool) -> None:
    stamp = int(time.time())
    main_page = f"LanguageBar E2E {stamp}"
    help_page = f"Help:LanguageBar E2E {stamp}"
    template_page = f"Template:LanguageBar E2E {stamp}"
    transcluding_page = f"LanguageBar transclusion E2E {stamp}"
    source_page = f"LanguageBar source E2E {stamp}"
    fr_copy = source_page + "/fr"
    created: list[str] = []

    try:
        # 1. Main namespace — the hook injects the bar; both links red.
        create_page(op, api, main_page, "LanguageBar E2E scratch page.\n",
                    "LanguageBar E2E scratch (run_languagebar_e2e.py)")
        created.append(main_page)
        assert_bar(page_get(op, base, rendered_path(main_page)), main_page,
                   fr_red=True, eo_red=True)

        # 2. Help namespace is in the enabled scope too.
        create_page(op, api, help_page, "LanguageBar E2E scratch page.\n",
                    "LanguageBar E2E scratch (run_languagebar_e2e.py)")
        created.append(help_page)
        assert_bar(page_get(op, base, rendered_path(help_page)), help_page,
                   fr_red=True, eo_red=True)

        # 3a. A page already rendering a bar (the parser function, via a
        #     transcluded template) keeps exactly one bar.
        create_page(op, api, template_page, TEMPLATE_FIXTURE,
                    "LanguageBar E2E template fixture (run_languagebar_e2e.py)")
        created.append(template_page)
        create_page(op, api, transcluding_page,
                    f"{{{{{template_page.removeprefix('Template:')}}}}}\n\nBody.\n",
                    "LanguageBar E2E scratch (run_languagebar_e2e.py)")
        created.append(transcluding_page)
        assert_bar(page_get(op, base, rendered_path(transcluding_page)), transcluding_page,
                   fr_red=True, eo_red=True)

        # 3b. A page carrying the raw bar markup (an explicit {{Languages}}
        #     render) also keeps exactly one bar.
        explicit_page = f"LanguageBar explicit E2E {stamp}"
        create_page(op, api, explicit_page, MANUAL_BAR + "\nBody.\n",
                    "LanguageBar E2E scratch (run_languagebar_e2e.py)")
        created.append(explicit_page)
        body = page_get(op, base, rendered_path(explicit_page))
        if count_bars(body) != 1:
            raise FlowError(f"{explicit_page}: explicit bar must not be duplicated "
                            f"(found {count_bars(body)})")
        print(f"[ok] {explicit_page}: explicit bar not duplicated")

        # 4. A /fr translation copy gets NO bar; the source page's fr link
        #    turns blue once the copy exists.
        create_page(op, api, source_page, "Source page for the fr copy.\n",
                    "LanguageBar E2E scratch (run_languagebar_e2e.py)")
        created.append(source_page)
        create_page(op, api, fr_copy,
                    "{{Translation|lang=fr|based-on=1|date=2026-01-01}}\n\nCopie française.\n",
                    "LanguageBar E2E fr copy (run_languagebar_e2e.py)")
        created.append(fr_copy)
        copy_body = page_get(op, base, rendered_path(fr_copy))
        if count_bars(copy_body) != 0:
            raise FlowError(f"{fr_copy}: a translation copy must not get a languages bar")
        if "translation-banner" not in copy_body:
            raise FlowError(f"{fr_copy}: translation banner missing (fixture problem)")
        print(f"[ok] {fr_copy}: no bar on a translation copy (banner present)")
        assert_bar(page_get(op, base, rendered_path(source_page)), source_page,
                   fr_red=False, eo_red=True)

        # 5. Out-of-scope namespaces get NO bar.
        template_body = page_get(op, base, rendered_path(template_page))
        if count_bars(template_body) != 0:
            raise FlowError(f"{template_page}: template pages must not get a languages bar")
        print(f"[ok] {template_page}: no bar on a Template page")
        item_body = page_get(op, base, rendered_path("Item:Q1"))
        if count_bars(item_body) != 0:
            raise FlowError("Item:Q1: entity pages must not get a languages bar")
        print("[ok] Item:Q1: no bar on an entity page")
    finally:
        if keep:
            print(f"[keep] leaving {len(created)} scratch pages on the instance")
        else:
            for page in reversed(created):
                try:
                    delete_page(op, api, page)
                except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                    print(f"[warn] cleanup of {page} failed: {exc}")
            print(f"[ok] cleanup: {len(created)} scratch pages deleted")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--api-url", required=True)
    ap.add_argument("--user", required=True)
    ap.add_argument("--password-file", required=True)
    ap.add_argument("--keep", action="store_true", help="keep the scratch pages")
    args = ap.parse_args()

    with open(args.password_file, encoding="utf-8") as fh:
        password = fh.read().strip()

    op = make_opener(args.base_url)
    login(op, args.api_url, args.user, password)
    check_extension_loaded(op, args.api_url)
    languagebar_flow(op, args.api_url, args.base_url, args.keep)
    print("languagebar E2E: all checks passed")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except FlowError as exc:
        print(f"languagebar E2E FAILED: {exc}")
        raise SystemExit(1)
