#!/usr/bin/env python3
"""One-way wiki → repo mirror of the public content namespaces.

`tools/wiki_pages.py` tracks the *interface* pages (`MediaWiki:` / `Template:`)
two-way. This tool is the read-only companion for **content** pages: it copies
the public content namespaces into a mirror directory (`--dir`) so the
snapshot repository has an offline, searchable, diffable copy. It never writes
to the wiki.

Scope (namespace ids):

- `0` Main
- `2000` Cheatsheet, `2002` HowItWorks, `2008` FOSS
- `2010` Person, `2012` Source, `2014` Collective, `2016` Software

**Gated namespaces are refused**: `2004` RonzzInt and `2006` RonzzIT are
mirrored by the private `ronzz-it-runbooks` repo; copying them here would leak
gated content into this public repo. User/talk/category pages and the Wikibase
`Item:`/`Property:` pages are out of scope.

Sync model:

- each run lists the namespace titles (cheap) and fetches only the current
  `revid`s, then downloads content **only for pages whose revid/path changed**
  (`--full` forces every page);
- deletions are reported as orphans and, with `--prune`, removed from the
  mirror (the file lives in git history, so nothing is lost); a mass prune of
  more than 10% of the mirror is refused unless `--force-prune` (a transient
  partial listing must not wipe it);
- `<dir>/state.json` records `{path, revid, sha256, synced_at}` per page; the
  next run is incremental from it.

Paths: `<dir>/<Namespace>/<Title>.wiki` (`Main/` for the main
namespace); subpages become directories; filesystem-illegal characters are
replaced with `_`. Two titles that map to the same path abort the run.

Usage::

    python3 tools/wiki_content_mirror.py --dir /path/to/mirror --dry-run
    python3 tools/wiki_content_mirror.py --dir /path/to/mirror --prune
    python3 tools/wiki_content_mirror.py --dir /path/to/mirror --full

The snapshot is committed to the separate
``Ron-RONZZ-org/ronzzwikibase-public-mirror`` repository; its
``.github/workflows/mirror.yml`` runs this tool with ``--dir . --state
state.json`` every 15 minutes. Python stdlib only.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
from pathlib import Path, PurePosixPath

from wiki_pages_lib import SyncError, WikiApi, now_iso, sha256

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_WIKI = "https://wikibase.ronzz.org"
DEFAULT_NAMESPACES = [0, 2000, 2002, 2008, 2010, 2012, 2014, 2016]
GATED_NAMESPACES = {2004, 2006}  # RonzzInt / RonzzIT — private mirror only
PACE_SECONDS = 1.0

_ILLEGAL = re.compile(r'[<>:"\\|?*\x00-\x1f]')


def safe_component(part: str) -> str:
    """A single filesystem-safe path component."""
    part = _ILLEGAL.sub("_", part).strip().rstrip(".")
    if part in ("", ".", ".."):
        return "_"
    return part


def mirror_path(namespace: str, title: str) -> str:
    """Relative mirror path for a page (``Main/Set.wiki``, ``Source/X.wiki``)."""
    folder = safe_component(namespace) if namespace else "Main"
    rest = title
    if namespace and title.startswith(namespace + ":"):
        rest = title[len(namespace) + 1:]
    parts = [safe_component(part) for part in rest.split("/")]
    return "/".join([folder] + parts) + ".wiki"


def build_path_map(entries: list[tuple[str, str]]) -> dict[str, str]:
    """title -> path, refusing case-insensitive collisions.

    ``entries`` is ``(title, namespace name)`` in document order.
    """
    paths: dict[str, str] = {}
    seen: dict[str, str] = {}
    for title, namespace in entries:
        path = mirror_path(namespace, title)
        key = path.casefold()
        if key in seen and seen[key] != title:
            raise SyncError(
                f"path collision: {seen[key]!r} and {title!r} both map to {path!r}"
            )
        seen[key] = title
        paths[title] = path
    return paths


def load_state(path: Path) -> dict:
    if not path.is_file():
        return {"version": 1, "pages": {}}
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data.get("pages"), dict):
        raise SyncError(f"{path}: pages must be an object")
    return data


def write_state(path: Path, state: dict) -> None:
    state["updated"] = now_iso()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(state, indent=2, sort_keys=True, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )


def changed_titles(state: dict, revids: dict[str, int], paths: dict[str, str],
                   full: bool = False) -> list[str]:
    """Titles that need a content fetch (new, moved revid, or changed path)."""
    out: list[str] = []
    for title in sorted(revids):
        entry = state["pages"].get(title)
        if (
            full
            or entry is None
            or entry.get("revid") != revids[title]
            or entry.get("path") != paths[title]
        ):
            out.append(title)
    return out


def orphan_titles(state: dict, paths: dict[str, str]) -> list[str]:
    return sorted(set(state["pages"]) - set(paths))


def prune_guard(orphans: list[str], total: int, force: bool = False) -> None:
    """Refuse a mass prune (a transient empty/partial listing must not wipe the mirror)."""
    if not orphans or force:
        return
    limit = max(10, total // 10)
    if len(orphans) > limit:
        raise SyncError(
            f"refusing to prune {len(orphans)} of {total} pages (> {limit}); "
            "re-run with --force-prune if the deletions are genuine"
        )


def remove_file(root: Path, relpath: str) -> None:
    """Delete a mirrored file and its now-empty parent directories."""
    proposed = root / PurePosixPath(relpath)
    try:
        proposed.resolve().relative_to(root.resolve())
    except ValueError:
        raise SyncError(f"unsafe mirror path (outside {root}): {relpath!r}") from None
    if proposed.is_file():
        proposed.unlink()
    parent = proposed.parent
    while parent != root and parent.is_dir() and not any(parent.iterdir()):
        parent.rmdir()
        parent = parent.parent


def ensure_public_namespaces(namespaces: list[int], available: dict[int, str]) -> None:
    for namespace in namespaces:
        if namespace in GATED_NAMESPACES:
            name = available.get(namespace, "?")
            raise SyncError(
                f"refusing to mirror gated namespace {namespace} ({name}) into the "
                "public repo; it is mirrored by ronzz-it-runbooks"
            )
        if namespace not in available:
            raise SyncError(f"unknown namespace id {namespace}")


def collect_entries(api: WikiApi, namespaces: list[int],
                    names: dict[int, str]) -> list[tuple[str, str]]:
    entries: list[tuple[str, str]] = []
    for namespace in namespaces:
        for title in api.allpages(namespace):
            entries.append((title, names[namespace]))
        time.sleep(PACE_SECONDS)
    return entries


def fetch_contents(api: WikiApi, titles: list[str]) -> dict[str, dict]:
    contents: dict[str, dict] = {}
    for start in range(0, len(titles), 50):
        contents.update(api.fetch_pages(titles[start:start + 50]))
        time.sleep(PACE_SECONDS)
    return contents


def report_text(written: list[str], orphans: list[str], pruned: list[str],
                checked: int) -> str:
    lines = [
        "# Wiki content mirror",
        "",
        f"- pages checked: {checked}",
        f"- files written: {len(written)}",
        f"- orphans: {len(orphans)}",
        f"- files pruned: {len(pruned)}",
        "",
    ]
    if written:
        lines += ["## Written", ""] + [f"- `{title}`" for title in written] + [""]
    if orphans:
        lines += ["## Orphans", ""] + [f"- `{title}`" for title in orphans] + [""]
    return "\n".join(lines)


def run(args: argparse.Namespace) -> int:
    root = Path(args.dir)
    state_path = Path(args.state) if args.state else root / "state.json"
    api = WikiApi(args.wiki)
    names = api.namespace_names()
    namespaces = args.namespace or DEFAULT_NAMESPACES
    ensure_public_namespaces(namespaces, names)

    state = load_state(state_path)
    entries = collect_entries(api, namespaces, names)
    paths = build_path_map(entries)
    revids = api.fetch_revids([title for title, _namespace in entries])
    for title in sorted(set(paths) - set(revids)):
        print(f"[warn] {title}: no revision returned (skipped)", file=sys.stderr)

    changed = changed_titles(state, revids, paths, full=args.full)
    print(f"[ok] {len(paths)} page(s) in mirror, {len(changed)} need content")
    if args.dry_run:
        for title in changed:
            print(f"[fetch] {title} -> {paths[title]}")
    contents = fetch_contents(api, changed)

    written: list[str] = []
    state_dirty = False
    for title in sorted(contents):
        page = contents[title]
        path = paths[title]
        dest = root / PurePosixPath(path)
        content = page["content"]
        existing = None
        if dest.is_file() and not args.full:
            existing = dest.read_text(encoding="utf-8")
        old_path = state["pages"].get(title, {}).get("path")
        if old_path and old_path != path:
            print(f"[move] {title}: {old_path} -> {path}")
            if not args.dry_run:
                remove_file(root, old_path)
        if args.full or existing != content:
            written.append(title)
            print(f"[write] {title} -> {path}")
            if not args.dry_run:
                dest.parent.mkdir(parents=True, exist_ok=True)
                with dest.open("w", encoding="utf-8", newline="") as handle:
                    handle.write(content)
        entry = {
            "path": path,
            "revid": page["revid"],
            "sha256": sha256(content),
            "synced_at": now_iso(),
        }
        if state["pages"].get(title) != entry:
            state_dirty = True
            if not args.dry_run:
                state["pages"][title] = entry

    orphans = orphan_titles(state, paths)
    if args.prune and not args.dry_run:
        prune_guard(orphans, len(paths), force=args.force_prune)
    pruned: list[str] = []
    for title in orphans:
        old_path = state["pages"][title].get("path", "?")
        print(f"[orphan] {title} ({old_path})", file=sys.stderr)
        if args.prune:
            if args.dry_run:
                print(f"[prune] would remove {old_path}")
            else:
                pruned.append(title)
                remove_file(root, old_path)
                del state["pages"][title]
                state_dirty = True

    if not args.dry_run and state_dirty:
        write_state(state_path, state)

    report = report_text(written, orphans, pruned, len(paths))
    if args.report:
        Path(args.report).write_text(report, encoding="utf-8")
    print(report)
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("--dir", required=True,
                        help="mirror root (the scheduled run passes the mirror repo root)")
    parser.add_argument("--state", default=None,
                        help="state file (default: <dir>/state.json)")
    parser.add_argument("--namespace", action="append", type=int, metavar="NS",
                        help="namespace id to mirror (repeatable; default: public content)")
    parser.add_argument("--full", action="store_true",
                        help="refetch and rewrite every page (ignore state revids)")
    parser.add_argument("--prune", action="store_true",
                        help="remove files for pages deleted on the wiki")
    parser.add_argument("--force-prune", action="store_true",
                        help="allow pruning more than 10%% of the mirror in one run")
    parser.add_argument("--dry-run", action="store_true", help="write nothing")
    parser.add_argument("--report", default=None, help="write the markdown report here")
    parser.add_argument("--wiki", default=DEFAULT_WIKI, help="wiki base URL")
    args = parser.parse_args(argv)
    try:
        return run(args)
    except SyncError as exc:
        print(f"[fail] {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
