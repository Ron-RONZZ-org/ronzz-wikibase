#!/usr/bin/env python3
"""Migrate legacy ``'''Proof''':`` / ``'''Solution''':`` / ``'''Example''':``
labels to the ``{{Hidden proof}}`` / ``{{Hidden solution}}`` /
``{{Hidden example}}`` … ``{{End hidden}}`` template blocks.

The legacy convention put a bold label on its own line and let the rest of the
section follow::

    '''Proof''':

    ...proof body...

    == Next section ==

The new convention wraps the body in a collapsible block, replacing the label
(the templates carry the toggle text "Show proof" / "Show solution" /
"Show example")::

    {{Hidden proof}}
    ...proof body...
    {{End hidden}}

    == Next section ==

Accepted label spellings (the colon may sit inside or outside the bold):

- ``'''Proof''':`` / ``'''Proof:'''`` / ``'''Proof:''':``
- ``'''Solution''':`` / ``'''Solution:'''`` / ``'''Solution:''':``
- ``'''Example''':`` / ``'''Example:'''`` / ``'''Example:''':``

Only the bare keyword is migrated. Descriptive labels such as
``'''Proof by induction''':`` or ``'''Example (homogeneous):'''`` are left
untouched (they are reported by ``--dry-run``) — a human decides whether the
descriptor belongs inside the hidden block.

Block boundary (the body ends at the earliest of):

- the next heading line (``== … ==``),
- the next legacy label (any ``'''Proof…`` / ``'''Solution…`` /
  ``'''Example…`` line),
- an existing ``{{Hidden …}}`` / ``{{End hidden}}`` directive (never nest),
- the matching ``</blockquote>`` when the label sits inside a blockquote,
- end of page.

Labels inside ``<nowiki>`` / ``<syntaxhighlight>`` / ``<pre>`` / ``<math>`` /
HTML comments, and labels already inside an existing Hidden block, are skipped.

Modes
-----
- ``--audit`` (default): scan and list pages with migratable labels. Exit 1
  when changes are pending (CI-usable), 0 when the wiki conforms.
- ``--dry-run``: same scan plus a unified diff per page. Writes nothing.
- ``--apply``: bot edits (``baserevid``-guarded), one edit per changed page.

Credentials come from ``--user``/``--password`` (or ``--password-file``), else
from the mediawiki MCP config ``~/.config/mediawiki-mcp/ronzz-wikibase.json``.
They are never printed. Login is required even for ``--audit`` because the
gated ``RonzzIT`` / ``RonzzInt`` namespaces are unreadable anonymously.

Python stdlib only (AGENTS.md: no pip dependencies). Polite pacing between
reads and edits.

Examples
--------
    python3 tools/migrate_hidden_blocks.py --audit
    python3 tools/migrate_hidden_blocks.py --dry-run
    python3 tools/migrate_hidden_blocks.py --apply
"""

from __future__ import annotations

import argparse
import difflib
import json
import re
import sys
import time
import urllib.parse
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from seed.wikibase_api import WikibaseApi, WikibaseApiError  # noqa: E402

API_URL = "https://wikibase.ronzz.org/api.php"
SUMMARY = "Migrate: wrap Proof/Solution/Example blocks in Hidden templates"
MCP_CONFIG = Path.home() / ".config" / "mediawiki-mcp" / "ronzz-wikibase.json"
DEFAULT_NAMESPACES = [0, 2000, 2002, 2004, 2006, 2008, 2010, 2012, 2014, 2016]
PACE_SECONDS = 1.0

# Label line: exact keyword, colon inside and/or outside the bold, optional
# same-line content. `Example:` inside a sentence (``'''Example: x.'''``) does
# not match because the colon must be adjacent to the closing quotes.
LABEL_RE = re.compile(
    r"^[ \t]*'''[ \t]*(?P<kind>Proof|Solution|Example)[ \t]*"
    r"(?P<inside_colon>:)?[ \t]*'''"
    r"(?P<outside_colon>:)?[ \t]*(?P<inline>[^\n]*)$",
    re.IGNORECASE | re.MULTILINE,
)
# Any line starting with one of the keywords is a hard boundary, even when the
# label itself is out of scope (descriptive variant) — it must not be swallowed.
BOUNDARY_LABEL_RE = re.compile(
    r"^[ \t]*'''[ \t]*(?:Proof|Solution|Example)\b",
    re.IGNORECASE | re.MULTILINE,
)
HEADING_RE = re.compile(r"^=+[^\n]*?=+[ \t]*$", re.MULTILINE)
BLOCKQUOTE_CLOSE_RE = re.compile(r"</blockquote\s*>", re.IGNORECASE)

# Template output must not be nested: an existing Hidden block is a boundary,
# and a label inside one is skipped.
HIDDEN_DIRECTIVE_RE = re.compile(
    r"\{\{\s*(?:(?P<open>Hidden[ \t]+(?:proof|solution|example))"
    r"|(?P<close>End[ \t]+hidden))\b[^}]*\}\}",
    re.IGNORECASE,
)

# Verbatim regions where a label is example text, never a real label.
PROTECTED_RE = re.compile(
    r"<!--.*?-->"
    r"|<nowiki\b[^>]*>.*?</nowiki\s*>"
    r"|<syntaxhighlight\b[^>]*>.*?</syntaxhighlight\s*>"
    r"|<source\b[^>]*>.*?</source\s*>"
    r"|<pre\b[^>]*>.*?</pre\s*>"
    r"|<math\b[^>]*>.*?</math\s*>",
    re.IGNORECASE | re.DOTALL,
)
BLOCKQUOTE_OPEN_RE = re.compile(r"<blockquote\b", re.IGNORECASE)


@dataclass(frozen=True)
class Migration:
    """One migrated label (for reporting)."""

    kind: str
    line: int
    inline: bool


@dataclass(frozen=True)
class Skip:
    """One label that looked like a candidate but was deliberately left alone."""

    line: int
    label: str
    reason: str


def _inside(position: int, ranges: list[tuple[int, int]]) -> bool:
    return any(start <= position < end for start, end in ranges)


def _protected_ranges(text: str) -> list[tuple[int, int]]:
    return [(match.start(), match.end()) for match in PROTECTED_RE.finditer(text)]


def _hidden_ranges(text: str) -> tuple[list[tuple[int, int]], bool]:
    """Existing ``{{Hidden …}}`` … ``{{End hidden}}`` spans.

    Returns the spans and whether any opening directive was never closed (the
    span then runs to end of page, so later labels are correctly skipped).
    """
    ranges: list[tuple[int, int]] = []
    start: int | None = None
    depth = 0
    for match in HIDDEN_DIRECTIVE_RE.finditer(text):
        if match.group("open"):
            if depth == 0:
                start = match.start()
            depth += 1
        elif depth > 0:
            depth -= 1
            if depth == 0:
                ranges.append((start, match.end()))
                start = None
    unclosed = depth > 0
    if unclosed and start is not None:
        ranges.append((start, len(text)))
    return ranges, unclosed


def _first_match(
    regex: re.Pattern[str], text: str, start: int, protected: list[tuple[int, int]]
) -> int | None:
    for match in regex.finditer(text, start):
        if not _inside(match.start(), protected):
            return match.start()
    return None


def _blockquote_depth(text: str, position: int) -> int:
    before = text[:position]
    return len(BLOCKQUOTE_OPEN_RE.findall(before)) - len(
        BLOCKQUOTE_CLOSE_RE.findall(before)
    )


def _block_end(
    text: str,
    start: int,
    label_position: int,
    protected: list[tuple[int, int]],
) -> int:
    """Earliest section/structural boundary after ``start`` (or EOF)."""
    candidates = [
        _first_match(HEADING_RE, text, start, protected),
        _first_match(BOUNDARY_LABEL_RE, text, start, protected),
        _first_match(HIDDEN_DIRECTIVE_RE, text, start, protected),
    ]
    if _blockquote_depth(text, label_position) > 0:
        candidates.append(_first_match(BLOCKQUOTE_CLOSE_RE, text, start, protected))
    found = [candidate for candidate in candidates if candidate is not None]
    return min(found) if found else len(text)


def migrate_wikitext(text: str) -> tuple[str, list[Migration], list[Skip], bool]:
    """Return (migrated text, migrations, skipped labels, has-unclosed-hidden)."""
    protected = _protected_ranges(text)
    hidden, unclosed = _hidden_ranges(text)

    migrations: list[Migration] = []
    skipped: list[Skip] = []
    # (start, end, replacement) in document order, non-overlapping.
    edits: list[tuple[int, int, str]] = []
    cursor = 0

    for match in LABEL_RE.finditer(text):
        if not (match.group("inside_colon") or match.group("outside_colon")):
            continue  # bare bold keyword, not a label
        if match.start() < cursor:
            continue  # inside a block already claimed by a previous label
        line = text.count("\n", 0, match.start()) + 1
        label = match.group(0).strip()
        if _inside(match.start(), protected):
            skipped.append(Skip(line, label, "inside verbatim markup"))
            continue
        if _inside(match.start(), hidden):
            skipped.append(Skip(line, label, "already inside a Hidden template"))
            continue

        end = _block_end(text, match.end(), match.start(), protected)
        inline = (match.group("inline") or "").strip()
        body = text[match.end():end]
        if inline:
            body = inline + body
        body = body.strip("\n").rstrip()

        kind = match.group("kind").lower()
        block = f"{{{{Hidden {kind}}}}}"
        if body:
            block += f"\n{body}"
        block += "\n{{End hidden}}"
        separator = "\n\n" if end < len(text) else "\n"
        edits.append((match.start(), end, block + separator))
        migrations.append(Migration(kind=kind, line=line, inline=bool(inline)))
        cursor = end

    if not edits:
        return text, migrations, skipped, unclosed

    pieces: list[str] = []
    position = 0
    for start, end, replacement in edits:
        pieces.append(text[position:start])
        pieces.append(replacement)
        position = end
    pieces.append(text[position:])
    return "".join(pieces), migrations, skipped, unclosed


def variant_labels(text: str) -> list[tuple[int, str]]:
    """Descriptive legacy-like labels that are deliberately out of scope."""
    protected = _protected_ranges(text)
    hidden, _unclosed = _hidden_ranges(text)
    out: list[tuple[int, str]] = []
    for match in BOUNDARY_LABEL_RE.finditer(text):
        if _inside(match.start(), protected) or _inside(match.start(), hidden):
            continue
        line_end = text.find("\n", match.start())
        line = text[match.start(): line_end if line_end != -1 else len(text)].strip()
        exact = LABEL_RE.match(line)
        if exact and (exact.group("inside_colon") or exact.group("outside_colon")):
            continue  # exact-keyword label, handled by migrate_wikitext
        if ":" not in line:
            continue
        out.append((text.count("\n", 0, match.start()) + 1, line))
    return out


def fetch_all(api: WikibaseApi, titles: list[str]) -> dict[str, dict]:
    """Batch fetch with a per-page fallback for a rejected batch."""
    pages: dict[str, dict] = {}
    for offset in range(0, len(titles), 50):
        batch = titles[offset: offset + 50]
        try:
            pages.update(fetch_pages(api, batch))
        except WikibaseApiError as exc:
            print(f"  [warn] batch fetch failed ({exc}); retrying per page",
                  file=sys.stderr)
            for title in batch:
                try:
                    page = fetch_pages(api, [title]).get(title)
                except WikibaseApiError as error:
                    print(f"  [skip] {title}: {error}", file=sys.stderr)
                    continue
                if page is not None:
                    pages[title] = page
    return pages


def credentials(args: argparse.Namespace) -> tuple[str, str]:
    if args.user:
        if args.password_file:
            password = Path(args.password_file).read_text(encoding="utf-8").strip()
        else:
            password = args.password or ""
        if not password:
            raise WikibaseApiError("--user needs --password or --password-file")
        return args.user, password
    if not MCP_CONFIG.exists():
        raise WikibaseApiError(
            f"no --user/--password and {MCP_CONFIG} not found; "
            "refusing to guess credentials"
        )
    config = json.loads(MCP_CONFIG.read_text(encoding="utf-8"))
    wiki = config.get("wikis", {}).get(config.get("defaultWiki"), {})
    user = wiki.get("username")
    password = wiki.get("password") or ""
    if not user or not password:
        raise WikibaseApiError(f"{MCP_CONFIG} lacks username/password for the default wiki")
    return user, password


def _get(api: WikibaseApi, query: str) -> dict:
    data = api._get(query)
    if data.get("error"):
        error = data["error"]
        raise WikibaseApiError(f"API error {error.get('code')}: {error.get('info')}")
    return data


def iter_titles(api: WikibaseApi, namespaces: list[int]) -> list[str]:
    titles: list[str] = []
    for namespace in namespaces:
        cont: str | None = None
        while True:
            query = (
                f"action=query&list=allpages&apnamespace={namespace}"
                "&aplimit=max&formatversion=2"
            )
            if cont:
                query += "&apcontinue=" + urllib.parse.quote(cont)
            data = _get(api, query)
            titles.extend(page["title"] for page in data["query"]["allpages"])
            cont = data.get("continue", {}).get("apcontinue")
            if not cont:
                break
    return titles


def fetch_pages(api: WikibaseApi, titles: list[str]) -> dict[str, dict]:
    """title -> {content, revid} for the pages that exist."""
    pages: dict[str, dict] = {}
    for offset in range(0, len(titles), 50):
        batch = titles[offset: offset + 50]
        query = (
            "action=query&prop=revisions&rvprop=content|ids&rvslots=main"
            "&formatversion=2&titles="
            + urllib.parse.quote("|".join(batch))
        )
        data = _get(api, query)
        for page in data["query"]["pages"]:
            if page.get("missing") or page.get("invalid"):
                continue
            revisions = page.get("revisions") or []
            if not revisions:
                continue
            content = revisions[0].get("slots", {}).get("main", {}).get("content")
            if content is None:
                continue
            pages[page["title"]] = {
                "content": content,
                "revid": int(revisions[0]["revid"]),
            }
        time.sleep(PACE_SECONDS)
    return pages


def edit_page(api: WikibaseApi, title: str, text: str, revid: int) -> None:
    data = api._post(
        "action=edit",
        token=api.require_csrf(),
        title=title,
        text=text,
        summary=SUMMARY,
        bot="1",
        baserevid=str(revid),
    )
    if data.get("error"):
        error = data["error"]
        raise WikibaseApiError(
            f"edit failed for {title}: {error.get('code')}: {error.get('info')}"
        )
    if data.get("edit", {}).get("result") != "Success":
        raise WikibaseApiError(f"edit failed for {title}: {data}")


def _print_diff(title: str, old: str, new: str) -> None:
    for line in difflib.unified_diff(
        old.splitlines(), new.splitlines(),
        fromfile=f"{title} (current)", tofile=f"{title} (migrated)", lineterm="",
    ):
        print(line)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--audit", action="store_true",
                      help="scan only; list pages with migratable labels (default)")
    mode.add_argument("--dry-run", action="store_true",
                      help="scan + print diffs; writes nothing")
    mode.add_argument("--apply", action="store_true",
                      help="perform the edits (requires login)")
    parser.add_argument("--page", action="append", metavar="TITLE",
                        help="restrict to one page (repeatable; default: all pages)")
    parser.add_argument("--namespace", action="append", type=int, metavar="NS",
                        help="namespace id to scan (repeatable; default: content namespaces)")
    parser.add_argument("--api-url", default=API_URL, help="MediaWiki API endpoint")
    parser.add_argument("--user", help="wiki username (default: MCP config)")
    parser.add_argument("--password", help="wiki password / bot password")
    parser.add_argument("--password-file", help="file holding the wiki password")
    args = parser.parse_args(argv)

    try:
        user, password = credentials(args)
        api = WikibaseApi(args.api_url, user, password)
        api.login()
    except (WikibaseApiError, OSError) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2

    try:
        titles = args.page or iter_titles(api, args.namespace or DEFAULT_NAMESPACES)
    except WikibaseApiError as exc:
        print(f"error: cannot list pages: {exc}", file=sys.stderr)
        return 2
    print(f"Scanning {len(titles)} page(s)…", file=sys.stderr)

    pages = fetch_all(api, titles)
    pending: list[tuple[str, dict, str, list[Migration]]] = []
    skipped_total = 0
    variants: dict[str, list[tuple[int, str]]] = {}
    for title in titles:
        page = pages.get(title)
        if page is None:
            continue
        old = page["content"]
        new, migrations, skipped, unclosed = migrate_wikitext(old)
        if unclosed:
            print(f"  [warn] {title}: unclosed {{{{Hidden …}}}} block; "
                  "later labels were skipped", file=sys.stderr)
        for skip in skipped:
            print(f"  [note] {title}:{skip.line}: {skip.label} — {skip.reason}",
                  file=sys.stderr)
        skipped_total += len(skipped)
        found_variants = variant_labels(old)
        if found_variants:
            variants[title] = found_variants
            print(f"  [note] {title}: {len(found_variants)} descriptive "
                  "label(s) left untouched (see --dry-run)", file=sys.stderr)
        if migrations:
            pending.append((title, page, new, migrations))

    total = sum(len(entry[3]) for entry in pending)
    print(f"\n{len(pending)} page(s), {total} legacy label(s) to migrate.")
    if skipped_total:
        print(f"{skipped_total} label(s) skipped (see notes above).", file=sys.stderr)

    for title, _page, new, migrations in pending:
        kinds = ", ".join(f"{m.kind}@L{m.line}" for m in migrations)
        print(f"  {title}: {len(migrations)} ({kinds})")

    if args.dry_run:
        for title, page, new, _migrations in pending:
            print(f"\n=== {title} ===")
            _print_diff(title, page["content"], new)
        if variants:
            print("\n=== descriptive labels left untouched ===")
            for title, entries in variants.items():
                for line, label in entries:
                    print(f"  {title}:{line}: {label}")

    if args.apply:
        for title, page, new, migrations in pending:
            edit_page(api, title, new, page["revid"])
            print(f"  edited {title} ({len(migrations)} block(s))", file=sys.stderr)
            time.sleep(PACE_SECONDS)
        print(f"\nApplied {total} block(s) across {len(pending)} page(s).")
        return 0

    return 1 if pending else 0


if __name__ == "__main__":
    sys.exit(main())
