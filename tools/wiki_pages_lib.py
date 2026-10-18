"""Shared state/API layer for tools/wiki_pages.py (two-way wiki page sync).

`wiki_pages.py` is the CLI; this module owns the MediaWiki API client, the
manifest/state files, the status state machine and the conflict report.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import hashlib
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_MANIFEST = ROOT / "wiki_pages" / "manifest.json"
DEFAULT_STATE = ROOT / "wiki_pages" / "state.json"
DEFAULT_WIKI = "https://wikibase.ronzz.org"
UA = "ronzz-wiki-pages-sync/1.0"
EXIT_OK = 0
EXIT_ERROR = 1
EXIT_CONFLICT = 3

# MediaWiki namespace ids: MediaWiki: = 8, Template: = 10.
DEFAULT_NAMESPACES = [8, 10]
DEFAULT_EXCLUDE = r"^MediaWiki:Citation-.*\.json$"

STATUS_IN_SYNC = "in-sync"
STATUS_PROD_AHEAD = "prod-ahead"
STATUS_REPO_AHEAD = "repo-ahead"
STATUS_CONFLICT = "conflict"
STATUS_PROD_MISSING = "prod-missing"
STATUS_REPO_MISSING = "repo-missing"
STATUS_PROD_ONLY = "prod-only"
STATUS_REPO_ONLY = "repo-only"


class SyncError(Exception):
    """A transport/API/configuration failure (not a sync conflict)."""


class EditConflict(SyncError):
    """MediaWiki rejected an edit because the page changed under us."""


def sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


# --------------------------------------------------------------- MediaWiki API


class WikiApi:
    def __init__(self, wiki: str, timeout: int = 90) -> None:
        self.api = wiki.rstrip("/") + "/api.php"
        self.timeout = timeout
        self.csrf_token: str | None = None
        self._opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def _open(self, params: dict, post: bool = False) -> dict:
        if post:
            req = urllib.request.Request(
                self.api,
                data=urllib.parse.urlencode(params).encode(),
                headers={"User-Agent": UA},
            )
        else:
            req = urllib.request.Request(
                self.api + "?" + urllib.parse.urlencode(params),
                headers={"User-Agent": UA},
            )
        try:
            with self._opener.open(req, timeout=self.timeout) as resp:
                data = json.load(resp)
        except urllib.error.HTTPError as exc:
            body = exc.read().decode("utf-8", "replace")[:1000]
            raise SyncError(f"HTTP {exc.code} from {self.api}: {body}") from None
        except (urllib.error.URLError, json.JSONDecodeError) as exc:
            raise SyncError(f"cannot reach {self.api}: {exc}") from None
        if isinstance(data, dict) and data.get("error"):
            error = data["error"]
            if error.get("code") == "editconflict":
                raise EditConflict(str(error.get("info", "edit conflict")))
            raise SyncError(f"API error {error.get('code')}: {error.get('info')}")
        return data

    def login(self, user: str, password: str) -> None:
        token = self._open(
            {"action": "query", "meta": "tokens", "type": "login", "format": "json"}
        )["query"]["tokens"]["logintoken"]
        result = self._open(
            {
                "action": "login",
                "lgname": user,
                "lgpassword": password,
                "lgtoken": token,
                "format": "json",
            },
            post=True,
        ).get("login", {})
        if result.get("result") != "Success":
            hint = ""
            if "@" not in user:
                hint = (" (bot-password logins use the 'User@AppId' form, "
                        "e.g. RonzzWikiSync@GitHub)")
            raise SyncError(f"login failed: {result.get('reason', result)}{hint}")
        self.csrf_token = self._open(
            {"action": "query", "meta": "tokens", "type": "csrf", "format": "json"}
        )["query"]["tokens"]["csrftoken"]

    def whoami(self) -> str:
        """The authenticated user name (call after login)."""
        data = self._open({"action": "query", "meta": "userinfo", "format": "json"})
        return (data.get("query", {}).get("userinfo", {}) or {}).get("name", "")

    def _csrf(self) -> str:
        if self.csrf_token is None:
            raise SyncError("push/resolve needs a bot login (--user + --password-file)")
        return self.csrf_token

    def fetch_pages(self, titles: list[str]) -> dict[str, dict | None]:
        """title -> {revid, timestamp, content} or None when missing.

        MediaWiki normalizes the first letter of a title (and underscores to
        spaces), so the response keys are mapped back to the requested title
        case-insensitively.
        """
        def key(title: str) -> str:
            return title.replace("_", " ").lower()

        requested = {key(title): title for title in titles}
        out: dict[str, dict | None] = {title: None for title in titles}
        for start in range(0, len(titles), 50):
            chunk = titles[start:start + 50]
            data = self._open(
                {
                    "action": "query",
                    "prop": "revisions",
                    "rvprop": "content|ids|timestamp",
                    "rvslots": "main",
                    "titles": "|".join(chunk),
                    "formatversion": "2",
                    "format": "json",
                }
            )
            for page in data.get("query", {}).get("pages", []):
                title = page.get("title", "")
                target = requested.get(key(title), title)
                if page.get("missing") or page.get("invalid"):
                    out[target] = None
                    continue
                revisions = page.get("revisions") or []
                if not revisions:
                    out[target] = None
                    continue
                revision = revisions[0]
                content = (
                    revision.get("slots", {})
                    .get("main", {})
                    .get("content")
                )
                if content is None:
                    raise SyncError(f"{title} carries no main-slot text")
                out[target] = {
                    "revid": int(revision.get("revid", 0)),
                    "timestamp": revision.get("timestamp"),
                    "content": str(content),
                }
        return out

    def edit_page(
        self,
        title: str,
        text: str,
        summary: str,
        baserevid: int | None = None,
        contentmodel: str | None = None,
    ) -> int:
        params = {
            "action": "edit",
            "title": title,
            "text": text,
            "summary": summary,
            "token": self._csrf(),
            "bot": "1",
            "format": "json",
        }
        if baserevid is not None:
            params["baserevid"] = str(baserevid)
        if contentmodel:
            params["contentmodel"] = contentmodel
        data = self._open(params, post=True)
        edit = data.get("edit", {})
        if edit.get("result") != "Success":
            raise SyncError(f"edit failed for {title}: {data}")
        return int(edit.get("newrevid", 0))

    def allpages(self, namespace: int) -> list[str]:
        titles: list[str] = []
        cont: str | None = None
        while True:
            params = {
                "action": "query",
                "list": "allpages",
                "apnamespace": str(namespace),
                "aplimit": "max",
                "formatversion": "2",
                "format": "json",
            }
            if cont:
                params["apcontinue"] = cont
            data = self._open(params)
            titles.extend(page["title"] for page in data.get("query", {}).get("allpages", []))
            cont = data.get("continue", {}).get("apcontinue")
            if not cont:
                return titles

    def fetch_revids(self, titles: list[str]) -> dict[str, int]:
        """title -> current revision id (no content); batch of 50 per request."""
        revids: dict[str, int] = {}
        for start in range(0, len(titles), 50):
            chunk = titles[start:start + 50]
            data = self._open(
                {
                    "action": "query",
                    "prop": "revisions",
                    "rvprop": "ids",
                    "rvslots": "main",
                    "titles": "|".join(chunk),
                    "formatversion": "2",
                    "format": "json",
                }
            )
            for page in data.get("query", {}).get("pages", []):
                if page.get("missing") or page.get("invalid"):
                    continue
                revisions = page.get("revisions") or []
                if revisions:
                    revids[page["title"]] = int(revisions[0]["revid"])
        return revids

    def namespace_names(self) -> dict[int, str]:
        """Namespace id -> canonical/localized name (main namespace = '')."""
        data = self._open(
            {
                "action": "query",
                "meta": "siteinfo",
                "siprop": "namespaces",
                "formatversion": "2",
                "format": "json",
            }
        )
        raw = data.get("query", {}).get("namespaces", {})
        namespaces = raw.values() if isinstance(raw, dict) else raw
        return {
            int(namespace["id"]): namespace["name"]
            for namespace in namespaces
        }


# ------------------------------------------------------------------- manifest


def load_manifest(path: Path) -> dict:
    if not path.is_file():
        raise SyncError(f"manifest not found: {path}")
    data = json.loads(path.read_text(encoding="utf-8"))
    pages = data.get("pages")
    if not isinstance(pages, list) or not pages:
        raise SyncError(f"{path}: pages must be a non-empty list")
    titles: set[str] = set()
    paths: set[str] = set()
    for entry in pages:
        title = entry.get("title")
        rel = entry.get("path")
        if not isinstance(title, str) or ":" not in title:
            raise SyncError(f"{path}: invalid title {title!r}")
        if not isinstance(rel, str) or not rel:
            raise SyncError(f"{path}: {title}: missing path")
        pure = PurePosixPath(rel)
        if pure.is_absolute() or ".." in pure.parts:
            raise SyncError(f"{path}: unsafe path {rel!r}")
        if title in titles or rel in paths:
            raise SyncError(f"{path}: duplicate title/path at {title!r} / {rel!r}")
        titles.add(title)
        paths.add(rel)
    data.setdefault("wiki", DEFAULT_WIKI)
    return data


def discover_entries(
    api: WikiApi, namespaces: list[int], exclude: str
) -> list[dict]:
    """Manifest entries for the given namespaces (generated ``Citation-*`` excluded)."""
    pattern = re.compile(exclude)
    entries: list[dict] = []
    for namespace in namespaces:
        for title in api.allpages(namespace):
            if pattern.search(title):
                continue
            entry = {"title": title, "path": path_for_title(title)}
            model = content_model(title)
            if model:
                entry["model"] = model
            entries.append(entry)
    return entries


def merge_manifest_entries(manifest: dict, entries: list[dict]) -> list[str]:
    """Append entries whose title is not tracked yet; returns the added titles.

    Never removes or reorders existing entries (the manifest keeps any manual
    ``model``/path overrides); a manifest is re-sorted by title only when
    something was actually added.
    """
    known = {entry["title"] for entry in manifest["pages"]}
    added: list[str] = []
    for entry in entries:
        if entry["title"] in known:
            continue
        manifest["pages"].append(entry)
        known.add(entry["title"])
        added.append(entry["title"])
    if added:
        manifest["pages"].sort(key=lambda entry: entry["title"])
    return added


def write_manifest(path: Path, manifest: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )


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


def read_repo_text(entry: dict) -> str | None:
    path = ROOT / "wiki_pages" / entry["path"]
    if not path.is_file():
        return None
    return path.read_text(encoding="utf-8")


def write_repo_text(entry: dict, text: str) -> None:
    path = ROOT / "wiki_pages" / entry["path"]
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        handle.write(text)


# -------------------------------------------------------------- status model


def compute_status(state_entry: dict | None, prod: dict | None, repo_text: str | None) -> str:
    prod_exists = prod is not None
    repo_exists = repo_text is not None
    if not prod_exists and not repo_exists:
        return STATUS_PROD_MISSING
    if state_entry is None:
        if prod_exists and repo_exists:
            return STATUS_IN_SYNC if sha256(prod["content"]) == sha256(repo_text) else STATUS_CONFLICT
        return STATUS_PROD_ONLY if prod_exists else STATUS_REPO_ONLY
    if not prod_exists:
        return STATUS_PROD_MISSING
    if not repo_exists:
        return STATUS_REPO_MISSING

    prod_hash = sha256(prod["content"])
    repo_hash = sha256(repo_text)
    prod_changed = prod_hash != state_entry["sha256"] or prod.get("revid") != state_entry.get("revid")
    repo_changed = repo_hash != state_entry["sha256"]
    if not prod_changed and not repo_changed:
        return STATUS_IN_SYNC
    if prod_changed and repo_changed:
        return STATUS_IN_SYNC if prod_hash == repo_hash else STATUS_CONFLICT
    return STATUS_PROD_AHEAD if prod_changed else STATUS_REPO_AHEAD


def state_for(prod: dict) -> dict:
    return {
        "revid": prod["revid"],
        "sha256": sha256(prod["content"]),
        "synced_at": now_iso(),
    }



def build_rows(api: WikiApi, manifest: dict, state: dict, only: list[str]) -> list[dict]:
    entries = [e for e in manifest["pages"] if not only or e["title"] in only]
    prod = api.fetch_pages([e["title"] for e in entries])
    rows = []
    for entry in entries:
        title = entry["title"]
        repo_text = read_repo_text(entry)
        prod_page = prod.get(title)
        state_entry = state["pages"].get(title)
        status = compute_status(state_entry, prod_page, repo_text)
        rows.append({
            "entry": entry,
            "title": title,
            "repo_text": repo_text,
            "prod": prod_page,
            "state": state_entry,
            "status": status,
        })
    return rows

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

def path_for_title(title: str) -> str:
    namespace, _, rest = title.partition(":")
    safe = re.sub(r'[<>:"\\|?*]', "_", rest)
    if namespace == "MediaWiki" and safe.endswith((".css", ".js", ".json")):
        return f"MediaWiki/{safe}"
    return f"{namespace}/{safe}.wiki"


def content_model(title: str) -> str | None:
    if title.startswith("MediaWiki:"):
        if title.endswith(".css"):
            return "css"
        if title.endswith(".js"):
            return "javascript"
        if title.endswith(".json"):
            return "json"
    return None


def write_report(path: str | None, report: str) -> None:
    if path:
        Path(path).write_text(report, encoding="utf-8")
