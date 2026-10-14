#!/usr/bin/env python3
"""Make downloaded SVG multi-line text editable in Inkscape — fetch or local.

Why
---
Inkscape marks each text line with ``sodipodi:role="line"`` on the matching
``<tspan>`` (see wiki.inkscape.org/wiki/Text and Inkscape bug #1514050).
Generated SVGs (browsers, graph tools, Illustrator/draw.io exports) instead
position each visual line with an absolute ``x``/``y`` on a bare ``<tspan>``
and no role. Inkscape then reads the ``<text>`` as a *single* line, so pressing
Enter inserts the new line at ``single-line y + line-height`` — overlapping an
existing line.

What it does
------------
For every ``<text>`` element, per direct ``<tspan>`` child:

* absolute ``x``/``y``           -> mark ``sodipodi:role="line"``
* relative ``dy`` (single value) -> convert to absolute ``y``, drop ``dy``, mark
* ``dy`` list / no position      -> leave as inline styling (do NOT mark)

It also seeds ``x``/``y`` on the ``<text>`` from its first line and sets
``xml:space="preserve"``. Rendering is unchanged; only Inkscape editing metadata
is added. Comments and processing instructions are preserved.

Remote sources
--------------
A target may be an ``http(s)://`` URL instead of a path. The SVG is downloaded
into a temp directory, normalised, and the path is printed; an interactive menu
then lets you open it in Inkscape, rename/move it, or exit.

Usage
-----
    # local files (in place, or -o elsewhere)
    python3 fix_svg_text.py drawing.svg
    python3 fix_svg_text.py *.svg
    python3 fix_svg_text.py -o fixed.svg drawing.svg

    # fetch from a URL, then pick from the menu (open / rename / exit)
    python3 fix_svg_text.py https://example.org/diagram.svg
    python3 fix_svg_text.py --outdir ~/Downloads https://example.org/diagram.svg

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import gzip
import os
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

SVG_NS = "http://www.w3.org/2000/svg"
SODIPODI_NS = "http://sodipodi.sourceforge.net/DTD/sodipodi-0.0.dtd"
XLINK_NS = "http://www.w3.org/1999/xlink"
XHTML_NS = "http://www.w3.org/1999/xhtml"
XML_SPACE = "{http://www.w3.org/XML/1998/namespace}space"

# Conventional prefixes, so re-serialization doesn't remap xlink:/html: to nsN:.
ET.register_namespace("sodipodi", SODIPODI_NS)
ET.register_namespace("xlink", XLINK_NS)
ET.register_namespace("html", XHTML_NS)

USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120 Safari/537.36"
)
DEFAULT_FETCH_DIR = os.path.join(tempfile.gettempdir(), "inkscape-svg")


class SvgFixError(Exception):
    """Fetch / parse / write failure with a message meant for the user."""


# --------------------------------------------------------------------------- #
# Normalization (pure)
# --------------------------------------------------------------------------- #

def _local(tag: object) -> str:
    if not isinstance(tag, str):        # comment / processing-instruction node
        return ""
    return tag.rsplit("}", 1)[-1]


def _single_number(value: str) -> float | None:
    """Parse a ``tspan`` ``dy`` that is one number; None for per-glyph lists."""
    parts = value.split()
    if len(parts) != 1:
        return None
    try:
        return float(parts[0])
    except ValueError:
        return None


def _normalize_text(el: ET.Element, warnings: list[str]) -> int:
    tspans = [c for c in el if _local(c.tag) == "tspan"]
    if not tspans:
        return 0

    baseline = el.get("y")
    lines: list[ET.Element] = []
    for tspan in tspans:
        x, y, dy = tspan.get("x"), tspan.get("y"), tspan.get("dy")
        if y is not None:                     # absolute line position
            baseline = y
            lines.append(tspan)
        elif dy is not None:                  # relative shift -> absolute line
            shift = _single_number(dy)
            if shift is None:
                warnings.append(f"non-numeric/multi-value dy={dy!r}; left inline")
            elif shift == 0.0:
                pass                          # dy=0 -> inline, not a new line
            elif baseline is None:
                warnings.append(f"tspan dy={dy!r} before any baseline; left inline")
            else:
                baseline = f"{float(baseline) + shift:g}"
                tspan.set("y", baseline)
                del tspan.attrib["dy"]
                lines.append(tspan)
        elif x is not None:                   # reposition on the same baseline
            lines.append(tspan)

    if not lines:
        return 0

    first = lines[0]
    if el.get("x") is None and first.get("x") is not None:
        el.set("x", first.get("x"))
    if el.get("y") is None and first.get("y") is not None:
        el.set("y", first.get("y"))
    el.set(XML_SPACE, "preserve")
    for tspan in lines:
        tspan.set("{%s}role" % SODIPODI_NS, "line")
    return len(lines)


def normalize_tree(tree: ET.ElementTree, warnings: list[str]) -> int:
    """Mark every text's positioned direct tspans as Inkscape lines.

    Returns the number of tspans marked.
    """
    root = tree.getroot()
    # Keep the SVG namespace as the default (avoid rewriting it to ns0:).
    if root.tag.startswith("{"):
        ET.register_namespace("", root.tag.split("}", 1)[0][1:])
    marked = 0
    for el in root.iter():
        if _local(el.tag) == "text":
            marked += _normalize_text(el, warnings)
    return marked


def normalize_file(
    path_in: str, path_out: str, warnings: list[str], *, force: bool = False
) -> int:
    """Normalize ``path_in`` and write to ``path_out``; return tspans marked.

    ``force`` writes even when nothing was marked (used for ``-o`` copies).
    In-place with nothing to mark leaves the file byte-identical.
    """
    # insert_comments/insert_pis keep comments and processing instructions
    # instead of dropping them on re-serialization.
    parser = ET.XMLParser(target=ET.TreeBuilder(insert_comments=True, insert_pis=True))
    try:
        tree = ET.parse(path_in, parser=parser)
    except ET.ParseError as exc:
        raise SvgFixError(f"{path_in}: not well-formed XML ({exc})") from exc
    marked = normalize_tree(tree, warnings)
    if marked or force or path_out != path_in:
        try:
            tree.write(path_out, encoding="UTF-8", xml_declaration=True)
        except OSError as exc:
            raise SvgFixError(f"cannot write {path_out}: {exc}") from exc
    return marked


# --------------------------------------------------------------------------- #
# Fetching
# --------------------------------------------------------------------------- #

def is_url(target: str) -> bool:
    return target.lower().startswith(("http://", "https://"))


def _maybe_gunzip(data: bytes) -> bytes:
    if data[:2] == b"\x1f\x8b":
        try:
            return gzip.decompress(data)
        except OSError:
            return data
    return data


def _looks_like_svg(data: bytes) -> bool:
    head = data[:4096]
    stripped = head.lstrip()
    return stripped.startswith(b"<?xml") or b"<svg" in head.lower()


def _filename_from_url(url: str, outdir: str) -> str:
    raw = urllib.parse.unquote(os.path.basename(urllib.parse.urlparse(url).path))
    name = re.sub(r"[^A-Za-z0-9._-]+", "_", raw).strip("_") or "download.svg"
    if not name.lower().endswith(".svg"):
        name += ".svg"
    stem, ext = os.path.splitext(name)
    candidate, i = name, 1
    while os.path.exists(os.path.join(outdir, candidate)):
        candidate = f"{stem}-{i}{ext}"
        i += 1
    return candidate


def fetch_svg(url: str, outdir: str) -> tuple[str, bool]:
    """Download ``url`` into ``outdir``; return (path, looks_like_svg)."""
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "image/svg+xml,image/*,*/*;q=0.8",
            "Accept-Encoding": "identity",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = resp.read()
    except urllib.error.HTTPError as exc:
        raise SvgFixError(f"HTTP {exc.code} fetching {url}") from exc
    except urllib.error.URLError as exc:
        raise SvgFixError(f"cannot fetch {url}: {exc.reason}") from exc
    except OSError as exc:
        raise SvgFixError(f"network error fetching {url}: {exc}") from exc

    data = _maybe_gunzip(data)
    looks_like = _looks_like_svg(data)
    try:
        os.makedirs(outdir, exist_ok=True)
    except OSError as exc:
        raise SvgFixError(f"cannot create {outdir}: {exc}") from exc
    path = os.path.join(outdir, _filename_from_url(url, outdir))
    try:
        with open(path, "wb") as fh:
            fh.write(data)
    except OSError as exc:
        raise SvgFixError(f"cannot write {path}: {exc}") from exc
    return path, looks_like


# --------------------------------------------------------------------------- #
# Interactive session
# --------------------------------------------------------------------------- #

def _launch_inkscape(path: str, inkscape_bin: str) -> None:
    try:
        subprocess.Popen(
            [inkscape_bin, path],
            start_new_session=True,      # detach so the menu keeps running
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except FileNotFoundError as exc:
        raise SvgFixError(
            f"Inkscape not found: {inkscape_bin!r} (install it or pass --inkscape)"
        ) from exc


def _do_rename(path: str) -> str:
    raw = input("  new name (or full path): ").strip()
    if not raw:
        print("  (cancelled)")
        return path
    target = raw
    if not (os.path.isabs(target) or os.sep in target):
        target = os.path.join(os.path.dirname(path), target)
    if not os.path.splitext(os.path.basename(target))[1]:
        target += ".svg"
    target = os.path.abspath(target)
    if target == path:
        return path
    if os.path.exists(target):
        print(f"  {target} already exists; not overwriting")
        return path
    try:
        os.makedirs(os.path.dirname(target), exist_ok=True)
        shutil.move(path, target)
    except OSError as exc:
        print(f"  rename failed: {exc}")
        return path
    print(f"  -> {target}")
    return target


def menu(path: str, inkscape_bin: str) -> None:
    print(f"\nfile: {path}")
    while True:
        print("\n  1) Open in Inkscape")
        print("  2) Rename / move")
        print("  3) Exit")
        try:
            choice = input("  > ").strip().lower()
        except (EOFError, KeyboardInterrupt):
            print()
            return
        if choice in ("1", "o", "open"):
            try:
                _launch_inkscape(path, inkscape_bin)
                print("  launched Inkscape")
            except SvgFixError as exc:
                print(f"  error: {exc}")
        elif choice in ("2", "r", "rename"):
            path = _do_rename(path)
        elif choice in ("3", "q", "exit"):
            return
        else:
            print("  enter 1, 2 or 3")


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #

def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="fix_svg_text.py",
        description=(
            "Make SVG multi-line text editable in Inkscape "
            "(add sodipodi:role=\"line\"). Accepts local paths or http(s) URLs; "
            "URLs are fetched to a temp dir and can be opened/renamed via a menu."
        ),
    )
    parser.add_argument(
        "targets", nargs="+", metavar="PATH|URL",
        help="SVG file(s) to normalize, or http(s) URL(s) to fetch",
    )
    parser.add_argument(
        "-o", "--output",
        help="write output here instead of in place (single input only)",
    )
    parser.add_argument(
        "--outdir",
        help=f"directory for fetched SVGs (default: {DEFAULT_FETCH_DIR})",
    )
    parser.add_argument(
        "--inkscape", default=os.environ.get("INKSCAPE", "inkscape"),
        help="Inkscape command (default: $INKSCAPE or 'inkscape')",
    )
    parser.add_argument(
        "--menu", action="store_true",
        help="also show the interactive menu for local files",
    )
    parser.add_argument(
        "--no-menu", action="store_true",
        help="never show the menu (fetch/convert only)",
    )
    return parser


def _process_url(target: str, args: argparse.Namespace) -> int:
    outdir = args.outdir or DEFAULT_FETCH_DIR
    try:
        path, looks_like = fetch_svg(target, outdir)
    except SvgFixError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1
    if not looks_like:
        print(f"warning: {target} does not look like an SVG", file=sys.stderr)

    warnings: list[str] = []
    try:
        marked = normalize_file(path, path, warnings)
    except SvgFixError as exc:
        print(f"warning: {exc}", file=sys.stderr)
        marked = 0
    print(f"fetched -> {path} (marked {marked} tspan(s) as lines)")
    for warning in warnings:
        print(f"  warning: {warning}", file=sys.stderr)

    if not args.no_menu and sys.stdin.isatty():
        menu(path, args.inkscape)
    return 0


def _process_path(target: str, args: argparse.Namespace) -> int:
    dest = args.output or target
    warnings: list[str] = []
    try:
        marked = normalize_file(target, dest, warnings, force=args.output is not None)
    except SvgFixError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1
    print(f"{target}: marked {marked} tspan(s) as lines -> {dest}")
    for warning in warnings:
        print(f"  warning: {warning}", file=sys.stderr)
    if args.menu and sys.stdin.isatty():
        menu(dest, args.inkscape)
    return 0


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    if args.output and len(args.targets) != 1:
        print("error: -o/--output accepts exactly one input", file=sys.stderr)
        return 2
    if args.menu and args.no_menu:
        print("error: --menu and --no-menu are mutually exclusive", file=sys.stderr)
        return 2

    for target in args.targets:
        code = _process_url(target, args) if is_url(target) else _process_path(target, args)
        if code:
            return code
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
