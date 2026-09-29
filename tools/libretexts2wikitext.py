#!/usr/bin/env python3
"""Convert a LibreTexts (MindTouch) page into MediaWiki wikitext.

Targets the ronzz-wikibase SimpleMathJax setup: inline math ``$...$`` and
display math ``$$...$$`` (``$wgSmjExtraInlineMath`` / ``$wgSmjDisplayMath``).

LibreTexts runs MindTouch, not MediaWiki: there is no ``action=raw`` and the
``@api/deki`` REST API now requires a developer token, so the only practical
route is rendered HTML -> pandoc -> wikitext. Pipeline:

    fetch HTML -> extract the article section -> pandoc (html -> mediawiki)
    -> normalise LaTeX delimiters -> expand the page's custom \\newcommand
    macros inline -> strip LibreTexts numbering/cross-reference macros
    -> strip chrome -> collect images -> prepend provenance + attribution.

Requires the ``pandoc`` executable (3.x) on PATH; everything else is stdlib.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import datetime as _dt
import html as _html
import os
import re
import subprocess
import sys
import urllib.parse
import urllib.request
from html.parser import HTMLParser

USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120 Safari/537.36"
)

# HTML void elements: opening tag never has a matching closing tag.
VOID_TAGS = frozenset(
    "area base br col embed hr img input link meta param source track wbr".split()
)

# LibreTexts display environments -> the MathJax inner environment to use.
# ``None`` means "drop the wrapper, keep the content as-is".
DISPLAY_ENVS = {
    "equation": None, "equation*": None,
    "align": "aligned", "align*": "aligned",
    "gather": "gathered", "gather*": "gathered",
    "multline": "multlined", "multline*": "multlined",
    "eqnarray": "aligned", "eqnarray*": "aligned",
}
_DISPLAY_ENV_RE = re.compile(
    r"\\begin\{(?P<env>" + "|".join(map(re.escape, DISPLAY_ENVS)) + r")\}"
    r"(?P<body>.*?)\\end\{(?P=env)\}",
    re.S,
)

# LibreTexts site-wide macros that carry real content (not defined per page).
# ``xrightleftharpoons`` is a chemistry arrow; this wiki does not load mhchem,
# so it maps to plain MathJax.
LIBRETEXTS_GLOBAL_MACROS: dict[str, tuple[int, str]] = {
    "xrightleftharpoons": (2, "\\overset{#1}{\\underset{#2}{\\rightleftharpoons}}"),
    "mhchemxrightleftharpoons": (0, "\\rightleftharpoons"),
}

# LibreTexts numbering / cross-reference / internal macros: they have no
# portable meaning on a plain wiki, so they are dropped.
NONCONTENT_MACROS = (
    "PageIndex", "Index", "label", "ref", "eqref", "pageref",
    "eatSpaces", "nonumber",
)
_NONCONTENT_RE = re.compile(
    r"\\(?:" + "|".join(NONCONTENT_MACROS) + r")\*?\s*(?:\{[^{}]*\})?"
)


class ConversionError(Exception):
    """Raised when a LibreTexts page cannot be converted."""


def fetch_html(url: str, timeout: int = 60) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            charset = resp.headers.get_content_charset() or "utf-8"
            return resp.read().decode(charset, errors="replace")
    except Exception as exc:
        raise ConversionError(f"failed to fetch {url}: {exc}") from exc


class _SectionExtractor(HTMLParser):
    """Capture the inner HTML of the first element matching an id or class."""

    def __init__(self, tag, *, id_=None, cls=None):
        super().__init__(convert_charrefs=False)
        self.tag, self.id_, self.cls = tag, id_, cls
        self.depth, self.active, self.parts = 0, False, []

    def _matches(self, tag, attrs):
        if tag != self.tag:
            return False
        if self.id_ is not None and attrs.get("id") == self.id_:
            return True
        return self.cls is not None and self.cls in (attrs.get("class") or "").split()

    def _append(self, text):
        if self.active:
            self.parts.append(text)

    def handle_starttag(self, tag, attrs):
        if not self.active and self._matches(tag, dict(attrs)):
            self.active, self.depth = True, 1
            return
        if self.active:
            self.parts.append(self.get_starttag_text())
            if tag not in VOID_TAGS:
                self.depth += 1

    def handle_startendtag(self, tag, attrs):
        self._append(self.get_starttag_text())

    def handle_endtag(self, tag):
        if not self.active:
            return
        self.depth -= 1
        if self.depth == 0:
            self.active = False
        else:
            self.parts.append(f"</{tag}>")

    def handle_data(self, data):
        self._append(data)

    def handle_entityref(self, name):
        self._append(f"&{name};")

    def handle_charref(self, name):
        self._append(f"&#{name};")

    def handle_comment(self, data):
        self._append(f"<!--{data}-->")


def extract_section(html: str) -> str:
    """Return the article body: the ``mt-content-container`` section."""
    for extractor in (
        _SectionExtractor("section", cls="mt-content-container"),
        _SectionExtractor("div", id_="elm-main-content"),
    ):
        extractor.feed(html)
        if extractor.parts:
            return "".join(extractor.parts)
    raise ConversionError(
        "could not locate the article body (mt-content-container / elm-main-content)"
    )


def _read_group(text: str, start: int) -> tuple[str, int]:
    """Read a balanced ``{...}`` group starting at ``text[start] == '{'``."""
    if start >= len(text) or text[start] != "{":
        raise ConversionError(f"expected '{{' at offset {start}")
    depth = 0
    i = start
    while i < len(text):
        ch = text[i]
        if ch == "\\":
            i += 2
            continue
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return text[start + 1:i], i + 1
        i += 1
    raise ConversionError("unbalanced braces in macro definition")


def _skip_space(text: str, i: int) -> int:
    while i < len(text) and text[i].isspace():
        i += 1
    return i


def extract_macros(html: str) -> dict[str, tuple[int, str]]:
    """Parse every ``\\newcommand`` on the page as ``{name: (arity, body)}``.

    Malformed definitions are skipped rather than aborting the conversion.
    """
    text = _html.unescape(html)
    macros: dict[str, tuple[int, str]] = {}
    for match in re.finditer(r"\\(?:re)?newcommand\s*", text):
        i = _skip_space(text, match.end())
        try:
            if text[i] != "{":
                continue
            name, i = _read_group(text, i)
            name = name.strip()
            if not name.startswith("\\"):
                continue
            i = _skip_space(text, i)
            arity = 0
            if i < len(text) and text[i] == "[":
                close = text.index("]", i)
                arity = int(text[i + 1:close])
                i = _skip_space(text, close + 1)
            body, _ = _read_group(text, i)
        except (ConversionError, ValueError):
            continue
        macros[name[1:]] = (arity, body)
    return macros


def _read_arg(text: str, start: int) -> tuple[str, int]:
    """Read one macro argument: a braced group, else a single token."""
    i = _skip_space(text, start)
    if i < len(text) and text[i] == "{":
        return _read_group(text, i)
    return (text[i] if i < len(text) else ""), i + 1


def _expand_once(text: str, macros: dict[str, tuple[int, str]]) -> str:
    out, i, n = [], 0, len(text)
    while i < n:
        if text[i] == "\\" and i + 1 < n and text[i + 1].isalpha():
            j = i + 1
            while j < n and text[j].isalpha():
                j += 1
            name = text[i + 1:j]
            if name in macros:
                arity, body = macros[name]
                k, args = j, []
                for _ in range(arity):
                    arg, k = _read_arg(text, k)
                    args.append(arg)
                for idx, arg in enumerate(args, start=1):
                    body = body.replace(f"#{idx}", arg)
                out.append(body)
                i = k
                continue
            out.append(text[i:j])
            i = j
            continue
        out.append(text[i])
        i += 1
    return "".join(out)


def expand_macros(text: str, macros: dict[str, tuple[int, str]], max_iter: int = 12) -> str:
    """Expand custom macros to a fixed point (handles macros using macros)."""
    if not macros:
        return text
    for _ in range(max_iter):
        expanded = _expand_once(text, macros)
        if expanded == text:
            break
        text = expanded
    return text


def strip_noncontent(tex: str) -> str:
    """Drop LibreTexts numbering/cross-reference macros with no portable meaning.

    ``\\PageIndex{9}``, ``\\label{eqn:x}``, ``\\ref{eqn:x}`` etc. are removed
    (macro plus its braced argument); ``\\tag{9.2.1}`` is kept because it is a
    visible equation number. LibreTexts writes some of these in prose too
    (``Equation \\ref{eqn:det}``), so this runs over the whole document.
    """
    tex = _NONCONTENT_RE.sub("", tex)
    tex = re.sub(r"[ \t]{2,}", " ", tex)
    return re.sub(r" +([,.;:])", r"\1", tex)


_DROP_BLOCKS = tuple(re.compile(p, re.S | re.I) for p in (
    r"<style\b.*?</style>",
    r"<script\b.*?</script>",
    r"<div[^>]*id=\"librelens-list\"[^>]*>.*?</div>",
    r"<div[^>]*id=\"librelens-attribution-list\"[^>]*>.*?</div>",
    r"<div[^>]*class=\"[^\"]*autoattribution[^\"]*\"[^>]*>.*?</div>",
    r"<div[^>]*class=\"[^\"]*Headertext[^\"]*\"[^>]*>.*?</div>",
))
_IMG_RE = re.compile(r"<img\b[^>]*>", re.I)
# LibreTexts pads with whitespace-only emphasis; pandoc turns it into ''''''.
_EMPTY_EMPHASIS_RE = re.compile(r"<(strong|b|em|i)>\s*</\1>", re.I)


def _attr(tag: str, name: str) -> str:
    match = re.search(rf"{name}\s*=\s*\"([^\"]*)\"", tag, re.I)
    return _html.unescape(match.group(1)) if match else ""


def prepare_html(section: str) -> tuple[str, list[tuple[str, str]]]:
    """Strip chrome and replace images with placeholders for pandoc."""
    for pattern in _DROP_BLOCKS:
        section = pattern.sub("", section)
    section = _EMPTY_EMPHASIS_RE.sub(" ", section)

    images: list[tuple[str, str]] = []

    def repl_img(match: re.Match) -> str:
        tag = match.group(0)
        images.append((_attr(tag, "src"), _attr(tag, "alt")))
        return f"\n@@LTIMG{len(images) - 1}@@\n"

    return _IMG_RE.sub(repl_img, section), images


def pandoc_html_to_mediawiki(fragment: str) -> str:
    try:
        proc = subprocess.run(
            ["pandoc", "-f", "html", "-t", "mediawiki", "--wrap=none"],
            input=fragment, capture_output=True, text=True, check=True,
        )
    except FileNotFoundError as exc:
        raise ConversionError("pandoc executable not found on PATH") from exc
    except subprocess.CalledProcessError as exc:
        raise ConversionError(f"pandoc failed: {exc.stderr.strip()}") from exc
    return proc.stdout


def _clean_math(body: str, macros: dict[str, tuple[int, str]]) -> str:
    body = re.sub(r"<br\s*/?>", " ", body, flags=re.I)
    return expand_macros(_html.unescape(body), macros).strip()


def _inner_env(env: str, body: str) -> str:
    """Map a display environment to its MathJax inner form (no delimiters)."""
    inner = DISPLAY_ENVS[env]
    if inner is None:
        # `equation`/`equation*` wrap content that is often `split`, which
        # MathJax accepts more reliably as `aligned` at top level.
        body = body.replace("\\begin{split}", "\\begin{aligned}")
        return body.replace("\\end{split}", "\\end{aligned}")
    return f"\\begin{{{inner}}}{body}\\end{{{inner}}}"


def _display_math(raw_body: str, macros: dict[str, tuple[int, str]],
                  env: str | None = None) -> str:
    """Wrap one display-math region in ``$$...$$`` (empty -> removed).

    LibreTexts nests a display environment inside ``\\[...\\]``
    (``\\[ \\begin{eqnarray*}...\\end{eqnarray*} \\]``); ``env`` is None for
    that form and the inner environment is collapsed so it is not double-wrapped.
    """
    body = _clean_math(raw_body, macros)
    if env is not None:
        return f"$${_inner_env(env, body)}$$" if body else ""
    if not body:
        return ""
    match = _DISPLAY_ENV_RE.fullmatch(body)
    if match:
        inner = _clean_math(match.group("body"), macros)
        return f"$${_inner_env(match.group('env'), inner)}$$" if inner else ""
    return f"$${body}$$"


def normalize_math(text: str, macros: dict[str, tuple[int, str]]) -> str:
    """Convert LibreTexts LaTeX delimiters to SimpleMathJax ($ / $$).

    Math whose whole content was stripped (e.g. a lone ``\\PageIndex{9}``) is
    removed entirely, so no empty ``$$`` delimiter is left behind.
    """
    text = strip_noncontent(text)

    # `\[...\]` first: its body may itself be a display environment.
    text = re.sub(
        r"\\\[(.*?)\\\]", lambda m: _display_math(m.group(1), macros),
        text, flags=re.S,
    )
    text = _DISPLAY_ENV_RE.sub(
        lambda m: _display_math(m.group("body"), macros, env=m.group("env")), text,
    )

    def inline(raw_body: str) -> str:
        body = _clean_math(raw_body, macros)
        return f"${body}$" if body else ""

    return re.sub(r"\\\((.*?)\\\)", lambda m: inline(m.group(1)), text, flags=re.S)


_MATH_SPAN_RE = re.compile(r"\$\$.*?\$\$|\$[^$\n]*?\$", re.S)


def clean_wikitext(text: str) -> str:
    text = re.sub(r"<br\s*/?>", "", text, flags=re.I)
    text = re.sub(r"<nowiki>\s*</nowiki>", "", text)
    # LibreTexts uses ``~`` for a non-breaking space in prose (not inside math).
    parts, last = [], 0
    for match in _MATH_SPAN_RE.finditer(text):
        parts.append(text[last:match.start()].replace("~", "&nbsp;"))
        parts.append(match.group(0))
        last = match.end()
    parts.append(text[last:].replace("~", "&nbsp;"))
    text = "".join(parts)
    text = re.sub(r"\n{3,}", "\n\n", text)
    # LibreTexts puts an <hr> before its auto-attribution block, re-added below.
    text = re.sub(r"\n-{4,}\s*$", "", text)
    return text.strip() + "\n"


def restore_images(text: str, images: list[tuple[str, str]]) -> tuple[str, list[str]]:
    """Replace @@LTIMGn@@ placeholders with MediaWiki file embeds."""
    manifest: list[str] = []
    for index, (src, alt) in enumerate(images):
        filename = _html.unescape(os.path.basename(src.split("?")[0]))
        filename = filename or f"image-{index}"
        text = text.replace(f"@@LTIMG{index}@@", f"[[File:{filename}|{alt or filename}]]")
        manifest.append(f"{filename}  <-  {src}")
    return text, manifest


def extract_attribution(html: str) -> str | None:
    match = re.search(
        r"<div[^>]*class=\"[^\"]*autoattribution[^\"]*\"[^>]*>(.*?)</div>",
        html, re.S | re.I,
    )
    if not match:
        return None
    text = _html.unescape(re.sub(r"<[^>]+>", "", match.group(1)))
    return re.sub(r"\s+", " ", text).strip() or None


def build_page(body: str, *, url: str, attribution: str | None,
               manifest: list[str]) -> str:
    now = _dt.datetime.now(_dt.timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    header = [
        "<!--",
        "  Converted from LibreTexts (MindTouch) by libretexts2wikitext.py.",
        f"  Source: {url}",
        f"  Retrieved: {now}",
        "  NOTE: verify the source licence before republishing. LibreTexts pages",
        "        are usually CC BY-NC-SA 4.0, but some declare no licence.",
    ]
    if manifest:
        header.append("  Images referenced (upload these before the links resolve):")
        header.extend(f"    {line}" for line in manifest)
    header.append("-->")

    parts = ["\n".join(header), "", body.rstrip()]
    if attribution:
        parts += ["", "== Attribution ==", "", attribution]
    return "\n".join(parts) + "\n"


def slug_from_url(url: str) -> str:
    tail = urllib.parse.unquote(os.path.basename(urllib.parse.urlsplit(url).path.rstrip("/")))
    slug = re.sub(r"[^\w.\-]+", "_", tail)
    return re.sub(r"_+", "_", slug).strip("_") or "libretexts-page"


def convert(url: str) -> tuple[str, str]:
    """Return ``(page_text, slug)`` for a LibreTexts URL."""
    html = fetch_html(url)
    # Page-defined macros win over the site-wide LibreTexts globals.
    macros = {**LIBRETEXTS_GLOBAL_MACROS, **extract_macros(html)}
    fragment, images = prepare_html(extract_section(html))
    wikitext = normalize_math(pandoc_html_to_mediawiki(fragment), macros)
    wikitext, manifest = restore_images(wikitext, images)
    page = build_page(
        clean_wikitext(wikitext), url=url,
        attribution=extract_attribution(html), manifest=manifest,
    )
    return page, slug_from_url(url)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("url", help="LibreTexts page URL")
    parser.add_argument("-o", "--output", help="output .wiki file (default: <slug>.wiki)")
    parser.add_argument("--outdir", default=".", help="directory for the output file")
    parser.add_argument("--stdout", action="store_true", help="print to stdout only")
    args = parser.parse_args(argv)

    try:
        page, slug = convert(args.url)
    except ConversionError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1

    if args.stdout:
        sys.stdout.write(page)
        return 0

    out_path = args.output or os.path.join(args.outdir, f"{slug}.wiki")
    with open(out_path, "w", encoding="utf-8") as handle:
        handle.write(page)
    print(f"wrote {out_path} ({len(page)} bytes)", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
