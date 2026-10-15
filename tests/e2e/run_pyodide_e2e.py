#!/usr/bin/env python3
r"""E2E for the Pyodide4MediaWiki extension (client-side Python execution).

The extension makes `class="pyodide"` code blocks executable in the reader's
browser with Pyodide. This suite is read-only (`action=parse` with an inline
`text=` payload — no page needs to exist, no login is needed) and safe against
production. It asserts the server-side contract the browser runner depends on:

1. the `Pyodide4MediaWiki` extension is loaded (siteinfo),
2. a `class="pyodide"` SyntaxHighlight block keeps its classes and the
   `ext.pyodide4mediawiki.run` ResourceLoader module is queued,
3. the module's site-wide config is exported (`wgPyodide4MediaWikiIndexUrl`
   pointing at the wiki's own Pyodide asset directory),
4. a plain SyntaxHighlight block does NOT load the module (no overhead),
5. the code text is HTML-escaped (an injected `<script>` never survives).

The browser-side run itself is exercised by
`tests/e2e/run_pyodide_ux_e2e.mjs` (Playwright; needs the assets installed).

Usage::

    python3 tests/e2e/run_pyodide_e2e.py --api-url https://wikibase.ronzz.org/api.php

Exit code 0 = all checks passed. Safe against production (read-only).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-pyodide-e2e/1.0"
RUN_MODULE = "ext.pyodide4mediawiki.run"

PYODIDE_BLOCK = (
    '<syntaxhighlight lang="python" class="pyodide" copy>\n'
    'print("hello", 1 + 1)\n'
    '</syntaxhighlight>'
)
PLAIN_BLOCK = (
    '<syntaxhighlight lang="python">\n'
    'print("hello", 1 + 1)\n'
    '</syntaxhighlight>'
)


class CheckError(Exception):
    """Raised when a Pyodide4MediaWiki check fails."""


def api_post(api: str, params: dict) -> dict:
    data = urllib.parse.urlencode(params).encode()
    req = urllib.request.Request(api, data=data, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=90) as resp:
        return json.load(resp)


def parse_text(api: str, text: str) -> dict:
    """Parse an inline wikitext payload; return the whole `parse` object."""
    data = api_post(api, {
        "action": "parse",
        "contentmodel": "wikitext",
        "text": text,
        "prop": "text|modules|jsconfigvars",
        "format": "json",
        "formatversion": "2",
    })
    if "error" in data:
        raise CheckError(f"action=parse error: {data['error']}")
    return data["parse"]


def check_extension_loaded(api: str) -> None:
    req = urllib.request.Request(
        api + "?" + urllib.parse.urlencode({
            "action": "query", "meta": "siteinfo", "siprop": "extensions",
            "format": "json", "formatversion": "2",
        }),
        headers={"User-Agent": UA},
    )
    with urllib.request.urlopen(req, timeout=90) as resp:
        data = json.load(resp)
    names = {ext.get("name") for ext in data["query"]["extensions"]}
    if "Pyodide4MediaWiki" not in names:
        raise CheckError("the Pyodide4MediaWiki extension is not loaded (siteinfo)")


def check_block_registers_module(api: str) -> None:
    parsed = parse_text(api, PYODIDE_BLOCK)
    if RUN_MODULE not in parsed.get("modules", []):
        raise CheckError(
            f"the {RUN_MODULE} module is not queued for a pyodide block: "
            f"modules={parsed.get('modules')}"
        )
    html = parsed["text"]
    if 'class="pyodide' not in html:
        raise CheckError(f"the pyodide class is missing from the rendered block: {html[:300]!r}")


def check_config_exported(api: str) -> None:
    parsed = parse_text(api, PYODIDE_BLOCK)
    config = parsed.get("jsconfigvars", {})
    index_url = config.get("wgPyodide4MediaWikiIndexUrl")
    if not index_url:
        raise CheckError(
            "wgPyodide4MediaWikiIndexUrl is not exported for a pyodide block: "
            f"jsconfigvars={list(config)}"
        )
    if not index_url.endswith("/Pyodide4MediaWiki/resources/pyodide/"):
        raise CheckError(
            f"wgPyodide4MediaWikiIndexUrl does not point at the local assets: {index_url!r}"
        )


def check_plain_block_has_no_module(api: str) -> None:
    parsed = parse_text(api, PLAIN_BLOCK)
    if RUN_MODULE in parsed.get("modules", []):
        raise CheckError(
            "a plain SyntaxHighlight block loaded the Pyodide module (it must not): "
            f"modules={parsed.get('modules')}"
        )


def check_code_is_escaped(api: str) -> None:
    parsed = parse_text(
        api,
        '<syntaxhighlight lang="python" class="pyodide">\n'
        'print("<script>alert(1)</script>")\n'
        '</syntaxhighlight>',
    )
    html = parsed["text"]
    if "<script>" in html:
        raise CheckError(f"an injected <script> survived rendering: {html[:400]!r}")
    if "&lt;script&gt;" not in html:
        raise CheckError(f"the code text was not HTML-escaped as expected: {html[:400]!r}")


CHECKS = [
    ("extension loaded (siteinfo)", check_extension_loaded),
    ("pyodide block queues the runner module", check_block_registers_module),
    ("site-wide config is exported (local asset URL)", check_config_exported),
    ("plain block loads nothing extra", check_plain_block_has_no_module),
    ("code text is HTML-escaped", check_code_is_escaped),
]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--api-url", required=True, help="api.php URL of the wiki")
    args = parser.parse_args()

    failures = 0
    for label, check in CHECKS:
        try:
            check(args.api_url)
        except (CheckError, urllib.error.URLError, KeyError) as exc:
            failures += 1
            print(f"FAIL  {label}: {exc}")
        else:
            print(f"ok    {label}")

    if failures:
        print(f"\n{failures} check(s) failed")
        return 1
    print("\nall Pyodide4MediaWiki checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
