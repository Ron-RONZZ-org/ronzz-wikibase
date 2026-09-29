#!/usr/bin/env python3
r"""E2E for the CodeBlockSpaces extension (literal spaces in code blocks).

MediaWiki core's *French space armoring* (`Sanitizer::armorFrenchSpaces()`,
applied unconditionally by `Parser::internalParse()`) turns the space before
`! ? : ; % » ›` into `&#160;` (U+00A0) across the whole parse output —
including `<pre>`, `<code>` and SyntaxHighlight blocks. The NBSP then leaks
into copied code (the SyntaxHighlight copy button copies `textContent`) and
silently corrupts pasted commands: a Vim `\=` replacement expression aborts
with `E488: Trailing characters` and Vim replaces the match with an empty
string, deleting the targeted content.

Checks (read-only, `action=parse` with an inline `text=` payload — no page
needs to exist, no login is needed):

1. the `CodeBlockSpaces` extension is loaded (siteinfo),
2. a `<syntaxhighlight lang="text">` block keeps its literal spaces (no
   `&#160;`/`&nbsp;` inside the `<pre>`),
3. the SAME text in prose still arms its spaces — the fix is scoped to code,
   French typography is untouched,
4. plain `<pre>` and `<code>` blocks are restored,
5. a tokenizing lexer (`lang="vim"`, which still leaves `?`/`:` as raw text)
   is restored too,
6. the exact katex command from `User:Rongzhou/Nvim_Regex` round-trips
   byte-for-byte.

Usage::

    python3 tests/e2e/run_codeblock_spaces_e2e.py \\
        --api-url https://wikibase.ronzz.org/api.php

Exit code 0 = all checks passed. Safe against production (read-only).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import html
import json
import re
import sys
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-codeblockspaces-e2e/1.0"

# The command from User:Rongzhou/Nvim_Regex#Katex — the reported breakage.
KATEX_COMMAND = (
    r":%s/\(\\(\(.\{-}\)\\)\)\|\(\\\[\(\_.\{-}\)\\\]\)/"
    r"\=submatch(1) != '' ? '$'.submatch(2).'$' : '$$'.submatch(4).'$$'/g"
)

PRE_RE = re.compile(r"<pre[^>]*>(.*?)</pre>", re.S)
CODE_RE = re.compile(r"<code[^>]*>(.*?)</code>", re.S)
TAG_RE = re.compile(r"<[^>]+>")
ARMORED_RE = re.compile(r"&#160;|&nbsp;|\u00a0")


class CheckError(Exception):
    """Raised when a CodeBlockSpaces check fails."""


def api_post(api: str, params: dict) -> dict:
    data = urllib.parse.urlencode(params).encode()
    req = urllib.request.Request(
        api, data=data, headers={"User-Agent": UA},
        # POST keeps large payloads (and the raw command) out of the URL.
    )
    with urllib.request.urlopen(req, timeout=90) as resp:
        return json.load(resp)


def parse_text(api: str, text: str) -> str:
    """Parse an inline wikitext payload and return the rendered HTML."""
    data = api_post(api, {
        "action": "parse",
        "contentmodel": "wikitext",
        "text": text,
        "prop": "text",
        "format": "json",
        "formatversion": "2",
    })
    if "error" in data:
        raise CheckError(f"action=parse error: {data['error']}")
    return data["parse"]["text"]


def first_pre(api_html: str) -> str:
    match = PRE_RE.search(api_html)
    if not match:
        raise CheckError(f"no <pre> in the parsed HTML: {api_html[:300]!r}")
    return match.group(1)


def first_code(api_html: str) -> str:
    match = CODE_RE.search(api_html)
    if not match:
        raise CheckError(f"no <code> in the parsed HTML: {api_html[:300]!r}")
    return match.group(1)


def text_of(block: str) -> str:
    """Visible text of a highlighted block: highlight <span>s stripped,
    HTML entities decoded."""
    return html.unescape(TAG_RE.sub("", block)).strip()


def assert_no_armor(block: str, where: str) -> None:
    match = ARMORED_RE.search(block)
    if match:
        raise CheckError(
            f"{where} still contains an armored space {match.group(0)!r}: {block!r}"
        )


def assert_has_armor(block: str, where: str) -> None:
    if not ARMORED_RE.search(block):
        raise CheckError(
            f"{where} lost its French-space armoring (the fix leaked into prose): "
            f"{block!r}"
        )


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
    if "CodeBlockSpaces" not in names:
        raise CheckError("the CodeBlockSpaces extension is not loaded (siteinfo)")


def check_syntaxhighlight_text(api: str) -> None:
    rendered = parse_text(
        api,
        '<syntaxhighlight lang="text">\na != b ? c : d\n</syntaxhighlight>',
    )
    pre = first_pre(rendered)
    assert_no_armor(pre, 'the lang="text" <pre>')
    if text_of(pre) != "a != b ? c : d":
        raise CheckError(f'unexpected lang="text" content: {pre!r}')


def check_prose_still_armed(api: str) -> None:
    rendered = parse_text(api, "a != b ? c : d")
    if not ARMORED_RE.search(rendered):
        raise CheckError(
            "prose armoring disappeared — the fix is not scoped to code: "
            f"{rendered!r}"
        )


def check_plain_pre(api: str) -> None:
    rendered = parse_text(api, "<pre>a != b ? c : d</pre>")
    pre = first_pre(rendered)
    assert_no_armor(pre, "the <pre> block")


def check_plain_code(api: str) -> None:
    rendered = parse_text(api, "<code>a != b ? c : d</code>")
    code = first_code(rendered)
    assert_no_armor(code, "the <code> block")


def check_vim_lexer(api: str) -> None:
    # The Vim lexer leaves ? and : as raw text (only != is span-wrapped), so
    # they are the cases that still armored before the fix.
    rendered = parse_text(
        api,
        '<syntaxhighlight lang="vim">\na ? b : c\n</syntaxhighlight>',
    )
    pre = first_pre(rendered)
    assert_no_armor(pre, 'the lang="vim" <pre>')


def check_katex_command_roundtrip(api: str) -> None:
    rendered = parse_text(
        api,
        f'<syntaxhighlight lang="text" copy>\n{KATEX_COMMAND}\n</syntaxhighlight>',
    )
    pre = first_pre(rendered)
    assert_no_armor(pre, "the katex command <pre>")
    if text_of(pre) != KATEX_COMMAND:
        raise CheckError(
            "the katex command did not round-trip byte-for-byte:\n"
            f"  expected {KATEX_COMMAND!r}\n"
            f"  got      {text_of(pre)!r}"
        )


CHECKS = [
    ("extension loaded (siteinfo)", check_extension_loaded),
    ('lang="text" block keeps literal spaces', check_syntaxhighlight_text),
    ("prose armoring still applies (fix scoped to code)", check_prose_still_armed),
    ("plain <pre> restored", check_plain_pre),
    ("plain <code> restored", check_plain_code),
    ('lang="vim" block restored', check_vim_lexer),
    ("katex command round-trips", check_katex_command_roundtrip),
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
    print("\nall CodeBlockSpaces checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
