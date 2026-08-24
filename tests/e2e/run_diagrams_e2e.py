#!/usr/bin/env python3
"""E2E for the vendored Diagrams extension (PlantUML/GraphViz/Mscgen/Mermaid).

Verifies integrated diagram rendering from wikitext on a live instance:

1. the `Diagrams` extension is loaded (siteinfo),
2. a scratch page holding `<uml>`, `<graphviz>`, `<mscgen>` and
   `<mermaid>` tags renders:
   - three server-side `.ext-diagrams` `<img>` elements (PlantUML, GraphViz,
     Mscgen) pointing at cached files under `/images/diagrams/` that return
     HTTP 200,
   - one client-side `.ext-diagrams-mermaid` container carrying the hidden
     source text, with the bundled `ext.diagrams.mermaid` module loaded
     (no CDN — the module is served from the wiki itself),
3. a broken GraphViz source renders a graceful `.ext-diagrams error` span
   (no 500), and a PlantUML `!include /etc/passwd` probe errors out — proof
   the PlantUML SANDBOX security profile is active (an old jar without
   profile support would render the include instead),
4. an XSS probe page (script/event-handler payloads inside a `<uml>` block)
   renders with none of the injected payloads surviving — the diagram text is
   consumed server-side by the rendering binary and the output is served as
   an inert image,
5. cleanup: both scratch pages are deleted (self-cleaning).

Server-side rendering needs the local binaries in the wiki container:
`graphviz` + `mscgen` via apt, and the pinned PlantUML jar + SANDBOX wrapper
via `tools/install-plantuml.sh` — CI installs them (see
`.github/workflows/ci.yml`); the dev stack per `dev/README.md`.

Usage::

    python3 tests/e2e/run_diagrams_e2e.py \\
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

UA = "ronzz-wikibase-diagrams-e2e/1.0"

# Canonical tag usage: bare content with the `type` attribute (PlantUML) /
# bare DOT (GraphViz) / bare mscgen / bare mermaid. Explicit @start/@end
# directives are also supported (use one form or the other, not both).
PAGE_TEMPLATE = """Diagram E2E scratch page.

== PlantUML ==
<uml type="uml">
Alice -> Bob: hello
</uml>

== GraphViz ==
<graphviz>
digraph g { A -> B -> C }
</graphviz>

== Mscgen ==
<mscgen>
msc {
  A, B;
  A -> B [ label="hello" ];
}
</mscgen>

== Mermaid ==
<mermaid>
graph LR
  A --> B
</mermaid>
"""

# A deliberately broken GraphViz source must fail gracefully (error span,
# not a 500).
BROKEN_TEMPLATE = """Broken diagram: <graphviz>
digraph { this is not valid dot syntax at all
</graphviz>
"""

# The PlantUML SANDBOX security probe: `!include` of a local file must be
# blocked by the SANDBOX profile (non-zero exit -> error span). If the server
# runs a plantuml binary without profile support (e.g. the Ubuntu noble apt
# package 1.2020.2), the include would succeed and this probe fails the run.
SANDBOX_TEMPLATE = """PlantUML sandbox probe: <uml type="uml">
!include /etc/passwd
Alice -> Bob
</uml>
"""

# XSS probes: script tags and event handlers in PlantUML participant /
# note text. PlantUML renders them as SVG *text* (inert when served as an
# image); the extension must never re-emit them as live HTML.
XSS_TEMPLATE = """Diagram XSS probe page.

<uml type="uml">
participant "<script>alert(1)</script>"
note right: <img src=x onerror=alert(2)>
"Bob" -> "Alice": <svg onload=alert(3)>
</uml>
"""


class FlowError(Exception):
    """Raised when a diagrams check fails."""


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


def check_extension_loaded(op, api: str) -> None:
    r = api_call(op, api, {
        "action": "query", "meta": "siteinfo", "siprop": "extensions", "format": "json",
    })
    names = [ext.get("name") for ext in r["query"]["extensions"]]
    if "Diagrams" not in names:
        raise FlowError(f"Diagrams extension not loaded (siteinfo extensions: {names})")
    print("[ok] Diagrams extension loaded")


def create_page(op, api: str, title: str, text: str, summary: str) -> None:
    token = csrf_token(op, api)
    r = api_call(op, api, {
        "action": "edit", "title": title, "text": text,
        "token": token, "summary": summary, "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"creation of {title} failed: {r!r}")


def rendered_path(title: str) -> str:
    return "/wiki/" + urllib.parse.quote(title.replace(" ", "_"))


def assert_server_side(op, base: str, body: str, title: str) -> None:
    """The three server-side tags must render <img> files under /images/diagrams/."""
    imgs = re.findall(r'<img[^>]*src="([^"]*?/images/diagrams/Diagrams_[a-f0-9]+\.svg)"', body)
    if len(imgs) != 3:
        raise FlowError(
            f"{title}: expected 3 server-side diagram <img> tags "
            f"(uml/graphviz/mscgen), found {len(imgs)}: {imgs}")
    for src in imgs:
        path = urllib.parse.urlsplit(src).path
        with op.open(base + path, timeout=90) as resp:
            if resp.status != 200:
                raise FlowError(f"{title}: diagram file {path} returned HTTP {resp.status}")
    print(f"[ok] {title}: 3 server-side diagrams render as cached SVG files (HTTP 200)")


def assert_mermaid(body: str, title: str) -> None:
    """The mermaid tag must emit the container + hidden source + bundled module."""
    if body.count('class="ext-diagrams-mermaid"') != 1:
        raise FlowError(f"{title}: expected exactly 1 .ext-diagrams-mermaid container")
    if "display:none" not in body:
        raise FlowError(f"{title}: mermaid hidden source <div> missing (display:none)")
    # Html::element() HTML-escapes the source: "A --> B" appears as "A --&gt; B".
    escaped = "A --&gt; B"
    raw = "A --> B"
    if "graph LR" not in body or (escaped not in body and raw not in body):
        raise FlowError(f"{title}: mermaid source text not preserved in the container")
    if "ext.diagrams.mermaid" not in body:
        raise FlowError(f"{title}: ext.diagrams.mermaid module not loaded on the page")
    print(f"[ok] {title}: <mermaid> container + hidden source + bundled module present")


def assert_error_spans(body: str, title: str, expected: int) -> None:
    found = body.count('class="ext-diagrams error"')
    if found != expected:
        raise FlowError(
            f"{title}: expected {expected} graceful `.ext-diagrams error` span(s), "
            f"found {found}")
    print(f"[ok] {title}: {expected} graceful error span(s) (no 500)")


def assert_no_xss(body: str, title: str) -> None:
    # A rendered MediaWiki page legitimately contains <script> tags (the
    # ResourceLoader head), so probe for the injected PAYLOADS themselves —
    # the unique strings from the XSS_TEMPLATE — not the generic tag.
    for probe in ("alert(1)", "alert(2)", "alert(3)",
                  "onerror=alert", "onload=alert", "<img src=x", "<svg onload"):
        if probe in body:
            raise FlowError(f"{title}: XSS probe {probe!r} survived rendering")
    print(f"[ok] {title}: no XSS payload survives (script/event-handler/markup probes)")


def diagrams_flow(op, api: str, base: str, keep: bool) -> None:
    stamp = int(time.time())
    page = f"Diagram E2E {stamp}"
    xss_page = f"Diagram E2E XSS {stamp}"
    titles = (page, xss_page)

    create_page(op, api, page, PAGE_TEMPLATE, "diagrams E2E scratch (run_diagrams_e2e.py)")
    create_page(op, api, xss_page, BROKEN_TEMPLATE + SANDBOX_TEMPLATE + XSS_TEMPLATE,
                "diagrams E2E scratch (run_diagrams_e2e.py)")

    try:
        body = page_get(op, base, rendered_path(page))
        assert_server_side(op, base, body, page)
        assert_mermaid(body, page)

        xss_body = page_get(op, base, rendered_path(xss_page))
        # Broken GraphViz -> graceful error; SANDBOX-blocked !include -> error.
        assert_error_spans(xss_body, xss_page, expected=2)
        assert_no_xss(xss_body, xss_page)
    finally:
        if keep:
            print(f"[keep] leaving {page} and {xss_page} on the instance")
            return
        token = csrf_token(op, api)
        for title in titles:
            try:
                api_call(op, api, {"action": "delete", "title": title, "token": token,
                                   "reason": "diagrams E2E cleanup (run_diagrams_e2e.py)",
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
    check_extension_loaded(op, args.api_url)
    diagrams_flow(op, args.api_url, args.base_url, args.keep)
    print("diagrams E2E: all checks passed")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except FlowError as exc:
        print(f"diagrams E2E FAILED: {exc}")
        raise SystemExit(1)
