#!/usr/bin/env python3
"""E2E for the vendored SimpleMathJax extension ($...$ inline LaTeX math).

Verifies the server-side contract of inline-math rendering on a live
instance. MathJax typesets CLIENT-SIDE, so a curl-based suite can only assert
what the server emits and serves — the actual pixel rendering is checked
separately (run_math_ux_e2e.mjs against the dev stack or a deployed
instance):

1. the `SimpleMathJax` extension is loaded (siteinfo),
2. a scratch page holding `<math>…</math>`, `<math display="block">`,
   `$…$` and a `<syntaxhighlight>` block with `$` renders server-side:
   - `<math>` → an escaped `<span class="smj-container">[math]…[/math]</span>`
     marker (nowiki); `display="block"` → the `displaymjx` wrapper — the
     TeX is inert text, never parsed as HTML,
   - XSS probe payloads inside `<math>` / `$…$` survive only ESCAPED (no
     live script/event-handler/markup), and the code block's `$` is not
     disturbed server-side,
   - the page carries the `ext.SimpleMathJax` module and the client config
     (`wgSmjUseCdn:false` → self-hosted MathJax; `wgSmjExtraInlineMath` →
     the `$…$` delimiter pair),
3. the self-hosted MathJax 4 assets + NewCM font return HTTP 200 (installed by
   `tools/install-mathjax.sh` into
   `extensions/SimpleMathJax/resources/MathJax/` — never a CDN),
4. cleanup: the scratch page is deleted (self-cleaning).

Usage::

    python3 tests/e2e/run_math_e2e.py \\
        --base-url https://wikibase.ronzz.org \\
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
import urllib.error
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-math-e2e/1.0"

# Server-side markers (wrapDisplaystyle on, EnableHtmlAttributes on):
#   <math>…</math>               -> <span class="smj-container">[math]\displaystyle{ … }[/math]</span>
#   <math display="block">…</math> -> <span class="smj-container">\begin{displaymjx}{ … }\end{displaymjx}</span>
# `$…$`/`$$…$$` are NOT seen by the server — they stay plain escaped page
# text that MathJax typesets client-side.
SCRATCH_TEMPLATE = """SimpleMathJax E2E scratch page.

== Inline tag ==
<math>e^{i\\pi}+1=0</math>

== Display tag ==
<math display="block">\\int_0^1 x^2 \\, dx</math>

== Dollar delimiters ==
Inline: $a^2 + b^2 = c^2$

Display: $$\\frac{1}{2}$$

== Code stays literal ==
<syntaxhighlight lang="bash">
price=\\$5.00
echo "total \\${price}0"
</syntaxhighlight>

== XSS probes ==
<math><script>alert(1)</script></math>

$<img src=x onerror=alert(2)>$
"""


class FlowError(Exception):
    """Raised when a math check fails."""


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


def delete_linked_item(op, api: str, title: str, token: str) -> None:
    """Deletes the item the page→item hook auto-created for a new
    Main-namespace page. The unlink is synchronous (wbsetsitelink with an
    empty linktitle); the item delete alone only enqueues an async
    PurgeEntityData job, so the page would stay linked until the cron."""
    r = api_call(op, api, {"action": "wbgetentities", "sites": "wikibase",
                           "titles": title, "props": "sitelinks", "format": "json"})
    for eid in r.get("entities", {}):
        if eid.startswith("Q"):
            api_call(op, api, {"action": "wbsetsitelink", "id": eid,
                               "linksite": "wikibase", "linktitle": "", "token": token,
                               "summary": "E2E cleanup (page→item unlink)",
                               "format": "json"}, post=True)
            api_call(op, api, {"action": "delete", "title": f"Item:{eid}", "token": token,
                               "reason": "E2E cleanup (page→item)", "format": "json"}, post=True)


def page_get(op, base: str, path: str) -> str:
    with op.open(base + path, timeout=90) as resp:
        return resp.read().decode("utf-8", "replace")


def check_extension_loaded(op, api: str) -> None:
    r = api_call(op, api, {
        "action": "query", "meta": "siteinfo", "siprop": "extensions", "format": "json",
    })
    names = [ext.get("name") for ext in r["query"]["extensions"]]
    if "SimpleMathJax" not in names:
        raise FlowError(f"SimpleMathJax extension not loaded (siteinfo extensions: {names})")
    print("[ok] SimpleMathJax extension loaded")


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


def api_parse_wikitext(op, api: str, text: str) -> str:
    """action=parse of a wikitext fragment -> rendered HTML body."""
    r = api_call(op, api, {
        "action": "parse", "text": text, "contentmodel": "wikitext",
        "prop": "text", "format": "json",
        "disablelimitreport": "1", "disableeditsection": "1",
    })
    if "parse" not in r or "text" not in r["parse"]:
        raise FlowError(f"action=parse failed: {r!r}")
    return r["parse"]["text"]["*"]


def assert_apostrophe_guard(op, api: str) -> None:
    """'' / ''' inside $…$ math must survive wikitext emphasis parsing.

    MediaWiki turns '' into italics markup; inside MathJax-delimited math
    those are TeX primes (y'', f'''(x)). The guard (InternalParseBeforeLinks
    strip markers) must keep the literal quotes in the HTML for MathJax,
    while prose ''italic'' keeps working as emphasis.
    """
    inline = api_parse_wikitext(op, api, "Derivative: $y'' = 2a_2$ then.")
    if "$y'' = 2a_2$" not in inline or "$y<i>" in inline or "<i> = 2a_2" in inline:
        raise FlowError(f"'' inside inline $…$ math was mangled by italics parsing: {inline[:300]!r}")

    display = api_parse_wikitext(op, api, "$$y'' - xy = 0$$")
    if "$$y'' - xy = 0$$" not in display:
        raise FlowError(f"'' inside display $$…$$ math was mangled: {display[:300]!r}")

    prose = api_parse_wikitext(op, api, "plain ''italic'' text stays")
    if "<i>italic</i>" not in prose:
        raise FlowError(f"prose ''italic'' no longer renders as emphasis: {prose[:300]!r}")

    # Regression (2026-09-27, Atom#Elements): an UNBALANCED display
    # delimiter must not let the guard pair it across a paragraph break and
    # swallow prose bold. `'''element'''` after `$$a=b$` + blank line must
    # still parse as <b>bold</b>.
    unbalanced = api_parse_wikitext(
        op, api, "$$a=b$\n\nprose '''element''' here $x$ end")
    if "<b>element</b>" not in unbalanced:
        raise FlowError(
            f"prose '''bold''' after an unbalanced $$ was not parsed as bold "
            f"(quote guard crossed a paragraph break): {unbalanced[:300]!r}")

    print("[ok] '' / ''' inside $…$ math survive; prose italics/bold untouched")


def assert_multiline_display(op, api: str) -> None:
    """A top-level `\\\\` in display math is wrapped in `gathered` (MathJax
    renders a top-level `\\\\` as a space, not a line break); an environment
    that already breaks lines (`cases`) is left untouched. A top-level
    `\\tag{}` must use `gather` — `gathered` is an inner environment where
    MathJax rejects tags."""
    wrapped = api_parse_wikitext(op, api, "$$a = b \\\\ c = d$$")
    if "\\begin{gathered}" not in wrapped or "\\end{gathered}" not in wrapped:
        raise FlowError(
            "top-level `\\\\` in display math was not wrapped in "
            f"\\begin{{gathered}}: {wrapped[:300]!r}")
    if "a = b \\\\ c = d" not in wrapped:
        raise FlowError(f"wrapped display math lost its content: {wrapped[:300]!r}")

    tagged = api_parse_wikitext(
        op, api, "$$a = b \\\\ c = d \\tag{1}$$")
    if "\\begin{gather}" not in tagged or "\\end{gather}" not in tagged:
        raise FlowError(
            "a top-level \\tag in multiline display math was not wrapped in "
            f"\\begin{{gather}} (rejected inside gathered): {tagged[:300]!r}")
    if "\\begin{gathered}" in tagged:
        raise FlowError(
            f"a \\tag content was wrongly wrapped in gathered: {tagged[:300]!r}")

    cases = api_parse_wikitext(op, api, "$$\\begin{cases}1 \\\\ 0\\end{cases}$$")
    if "\\begin{gathered}" in cases:
        raise FlowError(
            f"a cases environment that already breaks lines was re-wrapped: {cases[:300]!r}")

    print("[ok] multiline display math: top-level \\\\ wrapped in gathered, "
          "\\tag uses gather, cases untouched")


def assert_label_wrapper(op, api: str) -> None:
    r"""A top-level `\\label` is wrapped in the taggable `gather` (both a
    single-line display and a multiline block), so with `tex.tags='ams'`
    MathJax numbers the equation and `\eqref` can resolve. A label inside a
    braced group is not top-level and keeps the default `gathered` wrapper."""
    single = api_parse_wikitext(op, api, "$$x = 1 \\label{eq:x}$$")
    if "\\begin{gather}" not in single or "\\label{eq:x}" not in single:
        raise FlowError(
            "a single-line display with a top-level \\label was not wrapped "
            f"in \\begin{{gather}}: {single[:300]!r}")

    multiline = api_parse_wikitext(op, api, "$$a = b \\\\ c = d \\label{eq:y}$$")
    if "\\begin{gather}" not in multiline or "\\begin{gathered}" in multiline:
        raise FlowError(
            "a multiline display with a top-level \\label did not use "
            f"gather: {multiline[:300]!r}")

    braced = api_parse_wikitext(op, api, "$$\\text{\\label{x}} \\\\ a = b$$")
    if "\\begin{gathered}" not in braced:
        raise FlowError(
            f"a label inside a braced group was wrongly treated as top-level: {braced[:300]!r}")

    print("[ok] top-level \\label is wrapped in gather (label registers)")


def assert_pipe_guard(op, api: str) -> None:
    """A raw `|` inside math in a wikitable must not be taken as the cell /
    attribute separator.

    `Parser::internalParse()` runs `onInternalParseBeforeLinks` BEFORE
    `handleTables()`, so the pipe guard hides each math pipe behind a strip
    marker; the table parser sees one intact cell and the marker is restored
    to `|` in the final HTML. Without the guard `! $ |\theta|$` splits at the
    first pipe ($ as attributes, `\theta|$` as content).
    """
    table = api_parse_wikitext(
        op, api,
        '{| class="wikitable"\n'
        '! $ |\\theta|$\n'
        '! $D$\n'
        '|-\n'
        '| $\\frac{D}{\\|\\theta\\|}$\n'
        '| 20\n'
        '|}')
    if "$ |\\theta|$" not in table:
        raise FlowError(
            "a `|` inside a wikitable header's math split the cell: "
            f"{table[:400]!r}")
    if "$\\frac{D}{\\|\\theta\\|}$" not in table:
        raise FlowError(
            "a `\\|` inside a wikitable cell's math split the cell: "
            f"{table[:400]!r}")
    if table.count("<th") < 2 or table.count("<td") < 2:
        raise FlowError(f"wikitable cells were mangled: {table[:400]!r}")
    print("[ok] pipes inside math survive wikitable parsing (cell intact)")


def assert_file_link_pipe_guard(op, api: str) -> None:
    r"""A `|` inside math in a `[[File:…]]` caption is consumed by
    `Parser::makeImageInternal()`'s option split (unlike a normal wikilink
    label, every caption pipe is an option separator). The pipe guard runs in
    `InternalParseBeforeLinks`, before `handleInternalLinks()`, so the caption
    must survive intact.

    Reported on `Chinchilla_prescription#Empirical_determination`: the caption
    was truncated to "_\text{opt}$ around." — the math before the pipe was
    parsed as a file option.
    """
    inline = api_parse_wikitext(
        op, api,
        '[[File:DoesNotExist-e2e.png|thumb|As seen, $D \\approx 20\\,|\\theta|$ now.|350px]]')
    if 'As seen, $D \\approx 20\\,|\\theta|$ now.' not in inline:
        raise FlowError(
            "a `|` inside $…$ in a [[File:]] caption split the caption/options: "
            f"{inline[:400]!r}")
    if '<figcaption>$ now.</figcaption>' in inline:
        raise FlowError(
            f"[[File:]] caption truncated at the math pipe: {inline[:400]!r}")

    display = api_parse_wikitext(
        op, api,
        '[[File:DoesNotExist-e2e.png|thumb|$$a|b$$ caption|350px]]')
    if '$$a|b$$ caption' not in display:
        raise FlowError(
            f"a `|` inside $$…$$ in a [[File:]] caption split the options: {display[:400]!r}")
    print("[ok] pipes inside math in [[File:]] captions survive the option split")


def assert_server_markers(body: str, title: str) -> None:
    """<math> tags -> escaped smj-container markers; display=block -> displaymjx."""
    if 'class="smj-container"' not in body:
        raise FlowError(f"{title}: no smj-container span rendered: {body[:300]!r}")
    if "[math]" not in body or "[/math]" not in body:
        raise FlowError(f"{title}: no [math] inline marker: {body[:300]!r}")
    if "\\begin{displaymjx}" not in body or "\\end{displaymjx}" not in body:
        raise FlowError(f"{title}: display=block did not emit the displaymjx wrapper: {body[:300]!r}")
    # TeX payloads preserved (inert text inside the markers).
    if "e^{i\\pi}+1=0" not in body or "\\int_0^1 x^2" not in body:
        raise FlowError(f"{title}: <math> TeX payloads not preserved: {body[:400]!r}")
    print(f"[ok] {title}: <math> (inline) + <math display=block> render escaped markers")


def assert_no_xss(body: str, title: str) -> None:
    # LIVE payloads must not survive: the COMPLETE raw injected strings
    # (payload-specific — a rendered page legitimately carries skin
    # <script>/<img> markup, so bare tag names cannot be probed). The
    # escaped forms (&lt;…&gt;) prove the content is inert text.
    for probe in ("<script>alert(1)</script>", "<img src=x onerror=alert(2)>"):
        if probe in body:
            raise FlowError(f"{title}: XSS probe {probe!r} survived as LIVE markup: {body[:400]!r}")
    # The payloads must be present ONLY escaped — the <math> payload as
    # &lt;script&gt; inside the inert [math] marker, the $…$ probe as plain
    # escaped page text. Both prove the server never emits live HTML from TeX.
    if "&lt;script&gt;alert(1)&lt;/script&gt;" not in body:
        raise FlowError(f"{title}: <math> XSS payload not escaped inside the marker: {body[:400]!r}")
    if "&lt;img src=x onerror=alert(2)&gt;" not in body:
        raise FlowError(f"{title}: $…$ XSS payload not escaped as page text: {body[:400]!r}")
    print(f"[ok] {title}: no XSS payload survives — <math>/$…$ content stays inert escaped text")


def assert_page_contract(body: str, title: str) -> None:
    """The rendered page loads the module + self-hosted $…$ client config."""
    if "ext.SimpleMathJax" not in body:
        raise FlowError(f"{title}: page does not load the ext.SimpleMathJax module")
    if not re.search(r'"wgSmjUseCdn"\s*:\s*false', body):
        raise FlowError(f"{title}: jsconfig wgSmjUseCdn is not false (must be self-hosted)")
    if "wgSmjExtraInlineMath" not in body:
        raise FlowError(f"{title}: jsconfig wgSmjExtraInlineMath missing (the $…$ delimiters)")
    print(f"[ok] {title}: loads ext.SimpleMathJax + self-hosted $…$ client config")


def assert_assets(op, base: str) -> None:
    # The MathJax 4 component + the NewCM font data must be served by the
    # wiki itself (no CDN). The font file is the installer's sentinel.
    for path in (
        "/extensions/SimpleMathJax/resources/MathJax/tex-chtml.js",
        "/extensions/SimpleMathJax/resources/MathJax/fonts/chtml/woff2/mjx-ncm-n.woff2",
    ):
        try:
            with op.open(base + path, timeout=60) as resp:
                if resp.status != 200:
                    raise FlowError(
                        f"MathJax asset {path} returned HTTP {resp.status} — run "
                        "tools/install-mathjax.sh in the checkout (CI/dev) or on the "
                        "server (production) first")
        except urllib.error.HTTPError as exc:
            raise FlowError(
                f"MathJax asset {path} returned HTTP {exc.code} — run "
                "tools/install-mathjax.sh in the checkout (CI/dev) or on the "
                "server (production) first") from exc
        print(f"[ok] self-hosted MathJax asset served (HTTP 200): {path}")


def cleanup(op, api: str, page: str, keep: bool) -> None:
    if keep:
        print(f"[keep] leaving {page} on the instance")
        return
    token = csrf_token(op, api)
    try:
        delete_linked_item(op, api, page, token)
        api_call(op, api, {"action": "delete", "title": page, "token": token,
                           "reason": "math E2E cleanup (run_math_e2e.py)",
                           "format": "json"}, post=True)
    except Exception as exc:  # noqa: BLE001 — best-effort cleanup
        print(f"[warn] cleanup of {page} failed: {exc}")
    else:
        print("[ok] cleanup: scratch page deleted")


def math_flow(op, api: str, base: str, keep: bool) -> None:
    stamp = int(time.time())
    page = f"Math E2E {stamp}"

    create_page(op, api, page, SCRATCH_TEMPLATE,
                "math E2E scratch (run_math_e2e.py)")

    try:
        assert_apostrophe_guard(op, api)
        assert_multiline_display(op, api)
        assert_label_wrapper(op, api)
        assert_pipe_guard(op, api)
        assert_file_link_pipe_guard(op, api)
        body = page_get(op, base, rendered_path(page))
        assert_server_markers(body, page)
        assert_no_xss(body, page)
        assert_page_contract(body, page)
        assert_assets(op, base)
    except Exception:
        cleanup(op, api, page, keep)
        raise
    cleanup(op, api, page, keep)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--api-url", required=True)
    ap.add_argument("--user", required=True)
    ap.add_argument("--password-file", required=True)
    ap.add_argument("--keep", action="store_true", help="keep the scratch page")
    args = ap.parse_args()

    with open(args.password_file, encoding="utf-8") as fh:
        password = fh.read().strip()

    op = make_opener(args.base_url)
    login(op, args.api_url, args.user, password)
    check_extension_loaded(op, args.api_url)
    math_flow(op, args.api_url, args.base_url, args.keep)
    print("math E2E: all checks passed")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except FlowError as exc:
        print(f"math E2E FAILED: {exc}")
        raise SystemExit(1)
