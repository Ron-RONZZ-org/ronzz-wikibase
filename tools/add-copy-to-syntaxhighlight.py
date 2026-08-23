#!/usr/bin/env python3
"""Standardize ``<syntaxhighlight>`` code blocks: ensure ``copy`` is present.

Wikibase house rule (Help:Contributing/code): every *block-mode*
``<syntaxhighlight lang="...">`` must carry the ``copy`` attribute so readers
get a copy button. Exceptions are left untouched:

- ``inline`` blocks — the extension ignores ``copy`` when combined with
  ``inline`` (mutually exclusive, per Extension:SyntaxHighlight docs).
- ``<syntaxhighlight>`` tags without a ``lang`` attribute — these are literal
  tag examples (e.g. ``<syntaxhighlight lang="text" inline><syntaxhighlight>
  </syntaxhighlight>``) and must not be rewritten.

Modes
-----
- ``--audit`` (default): scan every wiki page, report pages whose block-mode
  ``syntaxhighlight`` tags lack ``copy``. Exit 0 when the wiki conforms,
  exit 1 when violations remain.
- ``--dry-run``: same scan, plus a unified diff per violating page showing
  exactly what would change. Writes nothing.
- ``--apply``: perform the edits (bot login, ``bot=true``, one edit per
  violating page with a fixed summary). Prints the resulting diffs.

Credentials are read at runtime from the mediawiki MCP config
(``~/.config/mediawiki-mcp/ronzz-wikibase.json``, ``wikis.<server>.username``
= ``SeedBot@MCP`` bot login) or from ``--user``/``--password`` — never
committed, never echoed.

Python stdlib only (argparse, difflib, json, re, sys, urllib). Polite
request pacing (1 s between API calls).

Example
-------
    python3 tools/add-copy-to-syntaxhighlight.py --audit      # is the wiki conform?
    python3 tools/add-copy-to-syntaxhighlight.py --dry-run    # review the diffs
    python3 tools/add-copy-to-syntaxhighlight.py --apply      # do the edits
"""

from __future__ import annotations

import argparse
import difflib
import http.cookiejar
import json
import re
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

MCP_CONFIG = Path.home() / ".config" / "mediawiki-mcp" / "ronzz-wikibase.json"
API_URL = "https://wikibase.ronzz.org/api.php"
SUMMARY = "Standardize: add copy button to block code snippets"

# A syntaxhighlight tag and its attribute string (''-terminated at the first '>').
OPEN_RE = re.compile(r"<syntaxhighlight\b([^>]*)>")
CLOSE_RE = re.compile(r"</syntaxhighlight\s*>")


def is_target(attrs: str) -> bool:
    """True when the tag is a block-mode code block that needs ``copy``.

    Only bare-word ``inline`` / ``copy`` attributes exclude a tag — a ``lang``
    value that merely contains those words (there is no such lexer today) must
    not. ``lang=...`` is required so literal tag examples (``<syntaxhighlight>``
    with no attributes) are never rewritten.
    """
    bare = set(re.findall(r"(?:^|\s)([A-Za-z]+)(?=\s|$)", attrs))
    if "inline" in bare or "copy" in bare:
        return False
    return re.search(r"\blang\s*=", attrs) is not None


def transform(text: str) -> tuple[str, int]:
    """Return (text with copy added to every target tag, number of tags changed).

    Nesting-aware, mirroring the MediaWiki tag parser: an opening tag at
    block depth 0 is a real block; any opening tag seen inside a block
    (depth > 0) is literal example text that must not be rewritten — e.g.
    ``<syntaxhighlight lang="text" inline><syntaxhighlight lang="…"></syntaxhighlight>``.
    """
    changed = 0
    out: list[str] = []
    pos = 0
    depth = 0  # 0 = outside any block, 1 = inside one

    # Walk the page one token (open or close tag) at a time, in position order.
    tokens = [
        (m.start(), "open", m.group(1), m.group(0))
        for m in OPEN_RE.finditer(text)
    ] + [
        (m.start(), "close", None, m.group(0))
        for m in CLOSE_RE.finditer(text)
    ]
    tokens.sort(key=lambda t: t[0])

    for start, kind, attrs, tag_text in tokens:
        if start < pos:
            continue  # already consumed inside a previous token's content
        out.append(text[pos:start])
        if kind == "open":
            if depth == 0:
                # Real (outer) block: transform when it is a target.
                if is_target(attrs):
                    changed += 1
                    out.append("<syntaxhighlight" + attrs.rstrip() + " copy>")
                else:
                    out.append(tag_text)
                depth = 1
            else:
                # Literal example inside a block — leave the open tag as-is.
                out.append(tag_text)
        else:
            # Close tag: ends the current block (or is dangling text).
            depth = 0
            out.append(tag_text)
        pos = start + len(tag_text)

    out.append(text[pos:])
    return "".join(out), changed


class Wiki:
    """Minimal MediaWiki API client (login + CSRF + page read/write)."""

    def __init__(self, api_url: str, user: str, password: str) -> None:
        self.api_url = api_url
        self.user = user
        self.password = password
        self.csrf = None
        # API login is session-based: the login token and its submission must
        # share cookies, so keep a cookie jar on the opener.
        self._jar = http.cookiejar.CookieJar()
        self._opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self._jar)
        )

    def _post(self, params: dict) -> dict:
        data = urllib.parse.urlencode(params).encode()
        req = urllib.request.Request(self.api_url, data=data, method="POST")
        req.add_header("User-Agent", "ronzz-wikibase add-copy tool/1.0")
        with self._opener.open(req, timeout=60) as resp:
            return json.load(resp)

    def login(self) -> None:
        # MediaWiki API login is two-step: request a token, then submit it.
        d = self._post({"action": "login", "lgname": self.user,
                        "lgpassword": self.password, "format": "json"})
        token = d.get("login", {}).get("token")
        if not token:
            raise RuntimeError(f"login failed: {d}")
        d = self._post({"action": "login", "lgname": self.user,
                        "lgpassword": self.password, "lgtoken": token,
                        "format": "json"})
        result = d.get("login", {}).get("result")
        if result != "Success":
            raise RuntimeError(f"login rejected ({result}): {d}")
        # CSRF token (needed for edits; read-only calls do not require it).
        d = self._post({"action": "query", "meta": "tokens", "type": "csrf",
                        "format": "json"})
        self.csrf = d["query"]["tokens"]["csrftoken"]

    def get_page(self, title: str) -> str:
        params = {
            "action": "query", "prop": "revisions", "rvprop": "content",
            "rvslots": "main", "titles": title, "format": "json",
        }
        d = self._post(params)
        for page in d["query"]["pages"].values():
            try:
                return page["revisions"][0]["slots"]["main"]["*"]
            except (KeyError, IndexError):
                raise RuntimeError(f"no content for {title!r}: {page}")
        raise RuntimeError(f"page {title!r} not found")

    def all_pages(self) -> list[str]:
        """Every page in content namespaces (mirrors the wiki scan used to
        size this migration; ns 0/4/12/2000/2002/2004/2006/2008)."""
        titles: list[str] = []
        for ns in (0, 4, 12, 2000, 2002, 2004, 2006, 2008):
            cont = ""
            while True:
                params = {"action": "query", "list": "allpages",
                          "apnamespace": ns, "aplimit": "500", "format": "json"}
                if cont:
                    params["apcontinue"] = cont
                d = self._post(params)
                titles += [p["title"] for p in d["query"]["allpages"]]
                cont = d.get("continue", {}).get("apcontinue")
                if not cont:
                    break
        return titles

    def edit(self, title: str, text: str) -> None:
        if not self.csrf:
            raise RuntimeError("login() must run before edit()")
        d = self._post({
            "action": "edit", "title": title, "text": text,
            "token": self.csrf, "summary": SUMMARY, "bot": "1", "format": "json",
        })
        if d.get("edit", {}).get("result") != "Success":
            raise RuntimeError(f"edit failed for {title!r}: {d}")


def credentials(args) -> tuple[str, str]:
    if args.user and args.password:
        return args.user, args.password
    if not MCP_CONFIG.exists():
        raise RuntimeError(
            f"no --user/--password and {MCP_CONFIG} not found; "
            "refusing to guess credentials"
        )
    cfg = json.loads(MCP_CONFIG.read_text())
    wiki = cfg.get("wikis", {}).get(cfg.get("defaultWiki"), {})
    user = wiki.get("username") or args.user
    password = wiki.get("password") or args.password
    if not user or not password:
        raise RuntimeError(f"{MCP_CONFIG} lacks username/password for the default wiki")
    return user, password


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--audit", action="store_true",
                    help="scan only: list pages with block-mode no-copy tags (default)")
    ap.add_argument("--dry-run", action="store_true",
                    help="scan + print diffs of what --apply would change")
    ap.add_argument("--apply", action="store_true",
                    help="perform the edits (requires login)")
    ap.add_argument("--user", help="wiki username (default: MCP config)")
    ap.add_argument("--password", help="wiki password / bot password")
    ap.add_argument("--page", action="append", metavar="TITLE",
                    help="restrict to one page (repeatable; default: all pages)")
    ap.add_argument("--api", default=API_URL, help="API endpoint URL")
    args = ap.parse_args()

    mode = "apply" if args.apply else ("dry" if args.dry_run else "audit")

    # Login for every mode: gated pages (RonzzIT/RonzzInt) require an
    # authenticated read. Only --apply writes.
    if args.user and args.password:
        user, password = args.user, args.password
    elif MCP_CONFIG.exists():
        user, password = credentials(args)
    else:
        raise RuntimeError(
            f"no --user/--password and {MCP_CONFIG} not found; "
            "refusing to guess credentials"
        )
    wiki = Wiki(args.api, user, password)
    wiki.login()

    titles = args.page or wiki.all_pages()
    print(f"Scanning {len(titles)} pages…", file=sys.stderr)

    violations: list[tuple[str, str, str, int]] = []  # (title, old, new, changed_count)
    for title in titles:
        try:
            old = wiki.get_page(title)
        except RuntimeError as e:
            print(f"  [skip] {title}: {e}", file=sys.stderr)
            continue
        new_text, changed = transform(old)
        if changed:
            violations.append((title, old, new_text, changed))
        time.sleep(1)  # polite pacing

    violations.sort(key=lambda v: -v[3])
    total = sum(v[3] for v in violations)
    print(f"\n{len(violations)} page(s), {total} tag(s) missing `copy`.")

    for title, old, new_text, changed in violations:
        print(f"\n=== {title}: {changed} tag(s) ===")
        if mode == "audit":
            continue
        diff = difflib.unified_diff(
            old.splitlines(), new_text.splitlines(),
            fromfile=f"{title} (current)", tofile=f"{title} (with copy)",
            lineterm="",
        )
        for line in diff:
            print(line)

    if mode == "apply":
        for title, _, new_text, changed in violations:
            wiki.edit(title, new_text)
            print(f"  edited {title} ({changed} tags)", file=sys.stderr)
            time.sleep(1)
        print(f"\nApplied {total} tag(s) across {len(violations)} page(s).")
        return 1 if violations else 0

    # audit/dry-run: nonzero exit signals remaining violations (CI-usable).
    return 1 if violations else 0


if __name__ == "__main__":
    sys.exit(main())
