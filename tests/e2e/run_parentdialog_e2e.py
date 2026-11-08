#!/usr/bin/env python3
"""Read-only E2E for the parent-creation dialog (EmbeddableContent).

Checks that:
  * the child-source manual form renders the always-visible "Create it now"
    link with the right parent class (book excerpt → book);
  * the AddCollective manual form renders the parent-organization link;
  * `?wbparentdialog=1` marks the flow (the frame body class) on the parent
    AddSource pages (website URL entry + book search).

The full browser flow (click → create inside the iframe → Q-id filled) is
covered by the manual Playwright suite; this script is the CI-safe,
read-only contract check.

Usage::

    python3 tests/e2e/run_parentdialog_e2e.py --base-url https://wikibase.ronzz.org

Exit 0 = pass.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import http.cookiejar
import sys
import urllib.error
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-parentdialog-e2e/1.0"


class CheckError(Exception):
    """Raised when a check fails."""


class HostRewritingRedirect(urllib.request.HTTPRedirectHandler):
    """Keep redirects on the runner's base host."""

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


def get(opener: urllib.request.OpenerDirector, url: str) -> str:
    return get_with_headers(opener, url)[0]


def get_with_headers(opener: urllib.request.OpenerDirector, url: str) -> tuple[str, dict]:
    request = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        with opener.open(request, timeout=30) as response:
            headers = {k.lower(): v for k, v in response.headers.items()}
            return response.read().decode("utf-8", errors="replace"), headers
    except urllib.error.HTTPError as error:
        raise CheckError(f"GET {url} failed: HTTP {error.code}") from error


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    args = parser.parse_args()
    base = args.base_url.rstrip("/")
    opener = make_opener(base)
    failures: list[str] = []

    def check(name: str, condition: bool, detail: str) -> None:
        if condition:
            print(f"[ok] {name}")
        else:
            failures.append(f"{name}: {detail}")

    try:
        book = get(opener, f"{base}/wiki/Special:AddSource/bookExcerpt/manual")
        check(
            "book excerpt parent link",
            'class="wb-parent-create"' in book and 'data-parent-class="book"' in book,
            "missing the Create it now link / data-parent-class=book",
        )

        collective = get(opener, f"{base}/wiki/Special:AddCollective/manual")
        check(
            "collective parent-organization link",
            'class="wb-parent-create"' in collective
            and 'data-parent-class="collective"' in collective
            and 'data-field="parentOrganization"' in collective,
            "missing the parent-organization Create it now link",
        )

        website, website_headers = get_with_headers(
            opener, f"{base}/wiki/Special:AddSource/website?wbparentdialog=1")
        check(
            "website dialog frame",
            "wb-parentdialog-frame" in website and "Special:AddSource/website" in website,
            "the website URL-entry page is not marked as a parent-dialog frame",
        )
        # X-Frame-Options: the dialog runs the real Add* page in a
        # same-origin iframe. DENY (MediaWiki's default) blocks it with the
        # browser "page embedded in another site" error; SAMEORIGIN is the
        # fix (prod LocalSettings + dev/config/Extensions.php).
        xfo = (website_headers.get("x-frame-options") or "").strip().upper()
        check(
            "same-origin framing for the creation dialogs",
            xfo == "SAMEORIGIN",
            f"Special:AddSource sends X-Frame-Options={xfo or '<none>'!r}, expected SAMEORIGIN",
        )

        book_search = get(opener, f"{base}/wiki/Special:AddSource/book?wbparentdialog=1")
        check(
            "book search dialog frame",
            "wb-parentdialog-frame" in book_search,
            "the book search page is not marked as a parent-dialog frame",
        )
    except CheckError as error:
        failures.append(str(error))

    if failures:
        print("\nFAILURES:", file=sys.stderr)
        for failure in failures:
            print(f"  - {failure}", file=sys.stderr)
        return 1
    print("\nAll parent-dialog checks passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
