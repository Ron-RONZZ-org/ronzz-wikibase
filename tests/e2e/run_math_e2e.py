#!/usr/bin/env python3
"""E2E for the vendored SimpleMathJax extension ($...$ inline LaTeX math).

Verifies the server-side contract of inline-math rendering on a live
instance. MathJax typesets CLIENT-SIDE, so a curl-based suite can only
assert what the server emits and serves — the actual pixel rendering is
checked separately (see run_math_ux_e2e.mjs against the dev stack or a
deployed instance):

1. the `SimpleMathJax` extension is loaded (siteinfo),
2. a `<math>…</math>` tag renders server-side as an escaped
   `<span class="smj-container">[math]…[/math]</span>` marker (nowiki) —
   the TeX is inert text, never parsed as HTML,
3. an XSS probe payload inside `<math>` survives only ESCAPED (no live
   script/event-handler markup), and a `$…$`-wrapped probe stays plain
   escaped page text (the server builds no HTML from `$…$` content),
4. a real page view (Main_Page) carries the `ext.SimpleMathJax` module and
   the client config (`wgSmjUseCdn:false` → self-hosted MathJax,
   `wgSmjExtraInlineMath` → the `$…$` delimiter pair),
5. the self-hosted MathJax 3 assets return HTTP 200 (installed by
   `tools/install-mathjax.sh` into
   `extensions/SimpleMathJax/resources/MathJax/` — never a CDN).

Usage::

    python3 tests/e2e/run_math_e2e.py --base-url https://wikibase.ronzz.org

    python3 tests/e2e/run_math_e2e.py --base-url http://127.0.0.1:8082

Exit code 0 = all checks passed. Anonymous read access suffices (no login).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import json
import re
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-math-e2e/1.0"

# Server-side marker produced for <math>…</math> (wrapDisplaystyle on):
# <span class=" smj-container">[math]\displaystyle{ … }[/math]</span>
# The `\displaystyle{` wrapper is added unless the tag carries an explicit
# display attribute.
MATH_TAG = "<math>e^{i\\pi}+1=0</math>"
MATH_DISPLAY_TAG = '<math display="block">\\int_0^1 x^2 \\, dx</math>'

# XSS probes: markup inside <math> and inside a $…$ span must never survive
# as live HTML — only escaped text.
XSS_PROBES = """<math><script>alert(1)</script></math>

$<img src=x onerror=alert(2)>$
"""


class FlowError(Exception):
    """Raised when a math check fails."""


def api_parse(api: str, text: str) -> str:
    """action=parse of wikitext -> rendered HTML body (anonymous)."""
    params = {
        "action": "parse",
        "text": text,
        "contentmodel": "wikitext",
        "format": "json",
        "disablelimitreport": "1",
        "disableeditsection": "1",
    }
    req = urllib.request.Request(api + "?" + urllib.parse.urlencode(params),
                                 headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=90) as resp:
        r = json.load(resp)
    if "parse" not in r or "text" not in r["parse"]:
        raise FlowError(f"action=parse failed: {r!r}")
    return r["parse"]["text"]["*"]


def page_get(base: str, path: str) -> str:
    with urllib.request.urlopen(base + path, timeout=90) as resp:
        return resp.read().decode("utf-8", "replace")


def http_status(base: str, path: str) -> int:
    req = urllib.request.Request(base + path, method="HEAD",
                                 headers={"User-Agent": UA})
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return resp.status
    except urllib.error.HTTPError as exc:
        return exc.code


def check_extension_loaded(api: str) -> None:
    # siteinfo extensions list
    params = {"action": "query", "meta": "siteinfo", "siprop": "extensions", "format": "json"}
    req = urllib.request.Request(api + "?" + urllib.parse.urlencode(params),
                                 headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=90) as resp:
        r = json.load(resp)
    names = [ext.get("name") for ext in r["query"]["extensions"]]
    if "SimpleMathJax" not in names:
        raise FlowError(f"SimpleMathJax extension not loaded (siteinfo extensions: {names})")
    print("[ok] SimpleMathJax extension loaded")


def assert_math_tag(api: str) -> None:
    body = api_parse(api, MATH_TAG)
    if 'class="smj-container"' not in body:
        raise FlowError(f"<math> tag did not render a smj-container span: {body[:300]!r}")
    if "[math]" not in body or "[/math]" not in body:
        raise FlowError(f"<math> tag did not emit the [math] marker: {body[:300]!r}")
    # The TeX is preserved (inert text inside the marker).
    if "e^{i\\pi}+1=0" not in body:
        raise FlowError(f"<math> TeX payload not preserved: {body[:300]!r}")
    print("[ok] <math> tag renders an escaped smj-container [math] marker")

    disp = api_parse(api, MATH_DISPLAY_TAG)
    if "\\begin{displaymjx}" not in disp:
        raise FlowError(f"<math display=block> did not emit the displaymjx wrapper: {disp[:300]!r}")
    print("[ok] <math display=block> emits the displaymjx display-math wrapper")


def assert_no_xss(api: str) -> None:
    body = api_parse(api, XSS_PROBES)
    # Raw live payloads must not survive in any form.
    for probe in ("<script>alert(1)</script>", "<img src=x onerror=alert(2)>",
                  "onerror=alert(2)"):
        if probe in body:
            raise FlowError(f"XSS probe {probe!r} survived rendering: {body[:400]!r}")
    # The payloads must be present ONLY escaped (Html::element / wikitext
    # text escaping) — the marker around the <math> payload proves the TeX
    # was inert server-side text.
    if "alert(1)" not in body or "&lt;script&gt;" not in body:
        raise FlowError(f"XSS probe payload missing/not escaped: {body[:400]!r}")
    print("[ok] no XSS payload survives — <math>/$…$ content stays inert escaped text")


def assert_page_contract(base: str) -> None:
    body = page_get(base, "/wiki/Main_Page")
    if "ext.SimpleMathJax" not in body:
        raise FlowError("Main_Page does not load the ext.SimpleMathJax module")
    if not re.search(r'"wgSmjUseCdn"\s*:\s*false', body):
        raise FlowError("Main_Page jsconfig wgSmjUseCdn is not false (must be self-hosted)")
    if "wgSmjExtraInlineMath" not in body:
        raise FlowError("Main_Page jsconfig wgSmjExtraInlineMath missing (the $…$ delimiters)")
    print("[ok] Main_Page loads ext.SimpleMathJax + self-hosted $…$ client config")


def assert_assets(base: str) -> None:
    # The MathJax 3 es5 build must be served by the wiki itself (no CDN).
    path = "/extensions/SimpleMathJax/resources/MathJax/es5/tex-chtml.js"
    status = http_status(base, path)
    if status != 200:
        raise FlowError(
            f"MathJax asset {path} returned HTTP {status} — run "
            "tools/install-mathjax.sh in the checkout (CI/dev) or on the "
            "server (production) first")
    print(f"[ok] self-hosted MathJax asset served (HTTP 200): {path}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--api-url", default=None,
                    help="defaults to <base-url>/api.php")
    args = ap.parse_args()

    api = args.api_url or args.base_url.rstrip("/") + "/api.php"

    check_extension_loaded(api)
    assert_math_tag(api)
    assert_no_xss(api)
    assert_page_contract(args.base_url)
    assert_assets(args.base_url)
    print("math E2E: all checks passed")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except FlowError as exc:
        print(f"math E2E FAILED: {exc}")
        raise SystemExit(1)
