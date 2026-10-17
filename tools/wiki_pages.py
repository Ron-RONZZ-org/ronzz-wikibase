#!/usr/bin/env python3
"""Two-way sync between the wiki's tracked pages and the repo.

The repo is the review/commit surface, the wiki is the live surface, and
`wiki_pages/state.json` records the last synchronized revision/hash. Every
operation compares BOTH sides against that state:

  in-sync      repo and wiki match the last sync (state may refresh a revid)
  prod-ahead   only the wiki changed -> pull writes the file into the repo
  repo-ahead   only the repo changed -> push writes the page to the wiki
  conflict     BOTH changed since the last sync and the contents differ
               -> refuse, exit 3, and write a conflict report

Never auto-deletes: a page that vanished on either side is reported, not
removed. Writes are limited to the titles in `wiki_pages/manifest.json`
(there is no ad-hoc title argument), and every push carries `baserevid` so a
racing wiki edit is rejected instead of overwritten.

Subcommands::

    pull      wiki -> repo files (+ state); --dry-run
    push      repo files -> wiki; --dry-run; refuses conflicts
    verify    read-only drift check; exit 1 unless every page is in-sync
    status    per-page status table (also --json)
    resolve   --accept-prod | --accept-repo for conflicting pages
    discover  list the wiki's MediaWiki:/Template: pages as manifest JSON

Reads (pull/verify/status) are anonymous. Push/resolve need a bot login:
`--user` / `--password-file`, or WIKI_SYNC_BOT_USER / WIKI_SYNC_BOT_PASS.
Credentials are never printed.

License: GPL-2.0-or-later
"""

import argparse
import json
import os
import re
import sys
from pathlib import Path

from wiki_pages_lib import (
    DEFAULT_EXCLUDE,
    DEFAULT_MANIFEST,
    DEFAULT_NAMESPACES,
    DEFAULT_STATE,
    DEFAULT_WIKI,
    EXIT_CONFLICT,
    EXIT_ERROR,
    EXIT_OK,
    STATUS_CONFLICT,
    STATUS_IN_SYNC,
    STATUS_PROD_AHEAD,
    STATUS_PROD_MISSING,
    STATUS_PROD_ONLY,
    STATUS_REPO_AHEAD,
    STATUS_REPO_MISSING,
    STATUS_REPO_ONLY,
    EditConflict,
    SyncError,
    WikiApi,
    build_rows,
    compute_status,
    conflict_report,
    content_model,
    load_manifest,
    load_state,
    now_iso,
    path_for_title,
    read_repo_text,
    sha256,
    state_for,
    write_report,
    write_repo_text,
    write_state,
)

def print_rows(rows: list[dict]) -> None:
    for row in rows:
        prod = row["prod"]
        rev = prod["revid"] if prod else "-"
        print(f"{row['status']:<14} {row['title']} (rev {rev})")


def conflict_report(rows: list[dict]) -> str:
    lines = [
        "# Wiki page sync conflict",
        "",
        "Both the wiki and the repo changed since the last synchronized state.",
        "The sync refused to overwrite either side.",
        "",
        "| Page | Status | Wiki revid | Wiki sha256 | Repo sha256 | Last synced revid |",
        "|---|---|---|---|---|---|",
    ]
    for row in rows:
        prod = row["prod"]
        repo_text = row["repo_text"]
        state = row["state"] or {}
        lines.append(
            "| {title} | {status} | {rev} | {ph} | {rh} | {sr} |".format(
                title=row["title"],
                status=row["status"],
                rev=prod["revid"] if prod else "-",
                ph=sha256(prod["content"])[:12] if prod else "-",
                rh=sha256(repo_text)[:12] if repo_text is not None else "-",
                sr=state.get("revid", "-"),
            )
        )
    lines += [
        "",
        "Resolve with one side explicitly:",
        "",
        "```",
        "python3 tools/wiki_pages.py resolve --accept-prod   # keep the wiki",
        "python3 tools/wiki_pages.py resolve --accept-repo   # keep the repo",
        "```",
        "",
    ]
    return "\n".join(lines)

def blocking_rows(rows: list[dict]) -> list[dict]:
    """Rows that must stop a sync before anything is written."""
    return [
        row for row in rows
        if row["status"] in (STATUS_CONFLICT, STATUS_PROD_MISSING, STATUS_REPO_MISSING)
    ]


def refuse(rows: list[dict], args: argparse.Namespace) -> int:
    report = conflict_report(rows)
    write_report(args.report, report)
    print(report, file=sys.stderr)
    return EXIT_CONFLICT


def cmd_pull(api: WikiApi, manifest: dict, state: dict, args: argparse.Namespace) -> int:
    rows = build_rows(api, manifest, state, args.only)
    # Transactional refusal: a conflict or a one-sided deletion blocks the
    # whole pull, so the repo is never left half-synced.
    blocked = blocking_rows(rows)
    if blocked:
        return refuse(blocked, args)
    changed = 0
    state_dirty = False
    for row in rows:
        status = row["status"]
        if status in (STATUS_IN_SYNC, STATUS_PROD_AHEAD, STATUS_PROD_ONLY):
            prod = row["prod"]
            if prod is None:
                continue
            current = read_repo_text(row["entry"])
            if current != prod["content"]:
                changed += 1
                if args.dry_run:
                    print(f"[pull] would update {row['title']} (rev {prod['revid']})")
                else:
                    write_repo_text(row["entry"], prod["content"])
            # Refresh state when the wiki revision/content moved, even for a
            # null edit that left the text unchanged. A no-op pull must not
            # rewrite state.json (otherwise the hourly job commits forever).
            old = row["state"]
            if (
                old is None
                or old.get("revid") != prod["revid"]
                or old.get("sha256") != sha256(prod["content"])
            ):
                state_dirty = True
                if not args.dry_run:
                    state["pages"][row["title"]] = state_for(prod)
    if not args.dry_run and state_dirty:
        write_state(DEFAULT_STATE if args.state is None else Path(args.state), state)
    print(f"[ok] pull: {changed} file(s) updated, {len(rows)} page(s) checked")
    return EXIT_OK


def cmd_push(api: WikiApi, manifest: dict, state: dict, args: argparse.Namespace) -> int:
    rows = build_rows(api, manifest, state, args.only)
    # Transactional refusal: a conflict blocks the whole push before any
    # page is written. A race after this point is still caught by baserevid
    # (the successful pages are recorded, the conflicting one fails loudly).
    blocked = blocking_rows(rows)
    if blocked:
        return refuse(blocked, args)
    pushed = 0
    conflicts = []
    for row in rows:
        status = row["status"]
        if status in (STATUS_IN_SYNC, STATUS_PROD_AHEAD):
            continue
        # repo-ahead or repo-only: create/update the page.
        prod = row["prod"]
        repo_text = row["repo_text"]
        if repo_text is None:
            conflicts.append(row)
            continue
        if args.dry_run:
            print(f"[push] would write {row['title']}")
            continue
        try:
            newrev = api.edit_page(
                row["title"],
                repo_text,
                "Sync from repo (wiki-pages)",
                baserevid=prod["revid"] if prod else None,
                contentmodel=row["entry"].get("model"),
            )
        except EditConflict:
            conflicts.append(row)
            continue
        except SyncError as exc:
            print(f"[error] {row['title']}: {exc}", file=sys.stderr)
            conflicts.append(row)
            continue
        # The edit succeeded; record the new revision. The content hash is
        # the repo file's (MediaWiki stores the posted text verbatim).
        state["pages"][row["title"]] = {
            "revid": newrev,
            "sha256": sha256(repo_text),
            "synced_at": now_iso(),
        }
        pushed += 1
        print(f"[push] {row['title']} -> rev {newrev}")
    if not args.dry_run and pushed > 0:
        write_state(DEFAULT_STATE if args.state is None else Path(args.state), state)
    if conflicts:
        report = conflict_report(conflicts)
        write_report(args.report, report)
        print(report, file=sys.stderr)
        return EXIT_CONFLICT
    print(f"[ok] push: {pushed} page(s) written")
    return EXIT_OK


def cmd_verify(api: WikiApi, manifest: dict, state: dict, args: argparse.Namespace) -> int:
    rows = build_rows(api, manifest, state, args.only)
    if args.json:
        print(json.dumps([
            {"title": r["title"], "status": r["status"]} for r in rows
        ], indent=2))
    else:
        print_rows(rows)
    drift = [r for r in rows if r["status"] != STATUS_IN_SYNC]
    return EXIT_ERROR if drift else EXIT_OK


def cmd_status(api: WikiApi, manifest: dict, state: dict, args: argparse.Namespace) -> int:
    rows = build_rows(api, manifest, state, args.only)
    if args.json:
        print(json.dumps([
            {
                "title": r["title"],
                "status": r["status"],
                "revid": r["prod"]["revid"] if r["prod"] else None,
            }
            for r in rows
        ], indent=2))
    else:
        print_rows(rows)
    return EXIT_CONFLICT if any(r["status"] == STATUS_CONFLICT for r in rows) else EXIT_OK


def cmd_resolve(api: WikiApi, manifest: dict, state: dict, args: argparse.Namespace) -> int:
    if not args.accept_prod and not args.accept_repo:
        raise SyncError("resolve needs --accept-prod or --accept-repo")
    rows = build_rows(api, manifest, state, args.only)
    targets = [r for r in rows if r["status"] in (STATUS_CONFLICT, STATUS_PROD_MISSING, STATUS_REPO_MISSING)]
    if not targets:
        print("[ok] nothing to resolve")
        return EXIT_OK
    resolved = 0
    for row in targets:
        prod = row["prod"]
        repo_text = row["repo_text"]
        if args.accept_prod:
            if prod is None:
                print(f"[error] {row['title']}: no wiki revision to accept", file=sys.stderr)
                continue
            if not args.dry_run:
                write_repo_text(row["entry"], prod["content"])
                state["pages"][row["title"]] = state_for(prod)
            resolved += 1
            print(f"[resolve] {row['title']} <- wiki rev {prod['revid']}")
        else:
            if repo_text is None:
                print(f"[error] {row['title']}: no repo file to accept", file=sys.stderr)
                continue
            if not args.dry_run:
                newrev = api.edit_page(
                    row["title"],
                    repo_text,
                    "Resolve wiki-pages conflict (keep repo)",
                    baserevid=prod["revid"] if prod else None,
                    contentmodel=row["entry"].get("model"),
                )
                state["pages"][row["title"]] = {
                    "revid": newrev,
                    "sha256": sha256(repo_text),
                    "synced_at": now_iso(),
                }
            resolved += 1
            print(f"[resolve] {row['title']} -> wiki (repo wins)")
    if not args.dry_run and resolved > 0:
        write_state(DEFAULT_STATE if args.state is None else Path(args.state), state)
    return EXIT_OK

def cmd_discover(api: WikiApi, args: argparse.Namespace) -> int:
    exclude = re.compile(args.exclude)
    pages = []
    for namespace in args.namespaces:
        for title in api.allpages(namespace):
            if exclude.search(title):
                continue
            entry = {"title": title, "path": path_for_title(title)}
            model = content_model(title)
            if model:
                entry["model"] = model
            pages.append(entry)
    manifest = {"wiki": args.wiki or DEFAULT_WIKI, "pages": pages}
    text = json.dumps(manifest, indent=2, ensure_ascii=False) + "\n"
    if args.write:
        path = DEFAULT_MANIFEST if args.manifest is None else Path(args.manifest)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
        print(f"[ok] wrote {len(pages)} pages to {path}")
    else:
        print(text)
    return EXIT_OK
# ---------------------------------------------------------------------- main


def make_api(args: argparse.Namespace) -> WikiApi:
    wiki = args.wiki
    path = DEFAULT_MANIFEST if args.manifest is None else Path(args.manifest)
    if not wiki and path.is_file():
        wiki = json.loads(path.read_text(encoding="utf-8")).get("wiki")
    return WikiApi(wiki or DEFAULT_WIKI)


def login_from_args(api: WikiApi, args: argparse.Namespace) -> None:
    user = args.user or os.environ.get("WIKI_SYNC_BOT_USER", "")
    password = args.password or os.environ.get("WIKI_SYNC_BOT_PASS", "")
    if args.password_file:
        try:
            password = Path(args.password_file).read_text(encoding="utf-8").strip()
        except OSError as exc:
            raise SyncError(f"cannot read --password-file: {exc}") from None
    if not user or not password:
        raise SyncError("push/resolve needs --user and --password-file (or WIKI_SYNC_BOT_USER/WIKI_SYNC_BOT_PASS)")
    api.login(user, password)


def build_parser() -> argparse.ArgumentParser:
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument("--manifest", default=None)
    common.add_argument("--state", default=None)
    common.add_argument("--wiki", default=None)
    common.add_argument("--only", action="append", default=[], help="limit to a title (repeatable)")
    common.add_argument("--dry-run", action="store_true")
    common.add_argument("--json", action="store_true")
    common.add_argument("--report", default=None, help="write the conflict report here")
    common.add_argument("--user", default=None)
    common.add_argument("--password", default=None)
    common.add_argument("--password-file", default=None)

    parser = argparse.ArgumentParser(description="Two-way wiki page sync")
    sub = parser.add_subparsers(dest="command", required=True)
    for name in ("pull", "push", "verify", "status"):
        sub.add_parser(name, parents=[common])
    resolve = sub.add_parser("resolve", parents=[common])
    resolve.add_argument("--accept-prod", action="store_true")
    resolve.add_argument("--accept-repo", action="store_true")
    discover = sub.add_parser("discover", parents=[common])
    discover.add_argument("--namespaces", type=lambda v: [int(n) for n in v.split(",")], default=DEFAULT_NAMESPACES)
    discover.add_argument("--exclude", default=DEFAULT_EXCLUDE)
    discover.add_argument("--write", action="store_true")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        api = make_api(args)
        if args.command == "discover":
            return cmd_discover(api, args)
        manifest = load_manifest(DEFAULT_MANIFEST if args.manifest is None else Path(args.manifest))
        state = load_state(DEFAULT_STATE if args.state is None else Path(args.state))
        if args.command in ("push", "resolve"):
            if args.command == "resolve" and args.accept_prod:
                pass  # accepting the wiki side is a read-only pull
            else:
                login_from_args(api, args)
        if args.command == "pull":
            return cmd_pull(api, manifest, state, args)
        if args.command == "push":
            return cmd_push(api, manifest, state, args)
        if args.command == "verify":
            return cmd_verify(api, manifest, state, args)
        if args.command == "status":
            return cmd_status(api, manifest, state, args)
        if args.command == "resolve":
            return cmd_resolve(api, manifest, state, args)
        return EXIT_ERROR
    except SyncError as exc:
        print(f"[fail] {exc}", file=sys.stderr)
        return EXIT_ERROR


if __name__ == "__main__":
    sys.exit(main())
