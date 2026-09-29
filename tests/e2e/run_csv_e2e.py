#!/usr/bin/env python3
"""E2E for the CSVTable extension (uploaded CSV rendered as a wiki table).

Checks the full contract of a `[[File:data.csv]]` embed on a live instance:

1. the `CSVTable` extension is loaded and `csv` is an allowed upload
   extension (siteinfo),
2. a `.csv` uploads and is typed `text/csv`,
3. a page embedding `[[File:name.csv]]` renders a
   `<table class="wikitable csv-table">` with the CSV's header row as `<th>`
   and the data rows as `<td>` (including a quoted field with an embedded
   delimiter),
4. the File: page renders the same table,
5. an injection cell does not survive rendering (escaped, never live markup),
6. cleanup: the scratch page and file are deleted (self-cleaning).

Usage::

    python3 tests/e2e/run_csv_e2e.py \\
        --base-url https://wikibase.ronzz.org \\
        --api-url https://wikibase.ronzz.org/api.php \\
        --user SeedBot --password-file seed/.seedbot.pass \\
        [--keep]

Exit code 0 = all checks passed. Requires a user with upload + edit + delete
rights (SeedBot / CIAdmin).

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

UA = "ronzz-wikibase-csv-e2e/1.0"

# A cell carrying HTML markup: it must be escaped in the table. (A
# `<script>` payload cannot be used here — MediaWiki's own upload filter
# rejects scripted content with `uploadscripted` before the handler sees it;
# the parser's escaping is the defense-in-depth boundary.)
MARKUP_PAYLOAD = "<b>bold</b>"

# name,note — a quoted field with an embedded comma + a markup cell.
CSV_CONTENT = (
    "name,note\n"
    'Alice,"a, b"\n'
    f"Bob,{MARKUP_PAYLOAD}\n"
)


class FlowError(Exception):
    """Raised when a CSVTable check fails."""


class HostRewritingRedirect(urllib.request.HTTPRedirectHandler):
    """Rewrites redirect targets to the base URL's host (dev-stack $wgServer
    is the internal container hostname the runner cannot resolve)."""

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


def page_get(op, base: str, path: str) -> str:
    with op.open(base + path, timeout=90) as resp:
        return resp.read().decode("utf-8", "replace")


def create_page(op, api: str, title: str, text: str) -> None:
    token = csrf_token(op, api)
    r = api_call(op, api, {
        "action": "edit", "title": title, "text": text, "token": token,
        "summary": "CSVTable E2E scratch (run_csv_e2e.py)", "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"creation of {title} failed: {r!r}")


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


def delete_page(op, api: str, title: str) -> None:
    token = csrf_token(op, api)
    delete_linked_item(op, api, title, token)
    api_call(op, api, {
        "action": "delete", "title": title, "token": token,
        "reason": "CSVTable E2E cleanup (run_csv_e2e.py)", "format": "json",
    }, post=True)


def api_upload(op, api: str, filename: str, content: bytes) -> dict:
    """POST action=upload with the file as multipart/form-data."""
    token = csrf_token(op, api)
    boundary = "----ronzzcsv" + "".join(str(i) for i in range(12))

    def field(name: str, value: str) -> bytes:
        return (f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n'
                f'{value}\r\n').encode()

    body = b""
    body += field("action", "upload")
    body += field("filename", filename)
    body += field("token", token)
    body += field("ignorewarnings", "1")
    body += field("format", "json")
    body += (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; '
             f'filename="{filename}"\r\nContent-Type: text/csv\r\n\r\n').encode()
    body += content
    body += f"\r\n--{boundary}--\r\n".encode()

    req = urllib.request.Request(api, data=body, headers={
        "User-Agent": UA,
        "Content-Type": f"multipart/form-data; boundary={boundary}",
    })
    with op.open(req, timeout=180) as resp:
        return json.load(resp)


def check_siteinfo(op, api: str) -> None:
    r = api_call(op, api, {
        "action": "query", "meta": "siteinfo", "siprop": "extensions|fileextensions", "format": "json",
    })
    extensions = [ext.get("name") for ext in r["query"]["extensions"]]
    if "CSVTable" not in extensions:
        raise FlowError(f"CSVTable extension not loaded (siteinfo extensions: {extensions})")
    exts = [e.get("ext") if isinstance(e, dict) else e for e in r["query"]["fileextensions"]]
    if "csv" not in exts:
        raise FlowError(f"csv is not an allowed upload extension (fileextensions: {exts})")
    print("[ok] CSVTable loaded, csv uploadable")


def file_info(op, api: str, title: str) -> dict:
    r = api_call(op, api, {
        "action": "query", "titles": title, "prop": "imageinfo",
        "iiprop": "url|mime|size", "format": "json",
    })
    pages = r["query"]["pages"]
    page = next(iter(pages.values()))
    info = page.get("imageinfo")
    if not info:
        raise FlowError(f"no imageinfo for {title}: {r!r}")
    return info[0]


def rendered_path(title: str) -> str:
    return "/wiki/" + urllib.parse.quote(title.replace(" ", "_"), safe="/:")


def assert_table(body: str, page: str) -> None:
    if 'class="wikitable csv-table"' not in body:
        raise FlowError(f"{page}: no csv-table rendered: {body[:400]!r}")
    # Header row from the first CSV line.
    if "<th>name</th>" not in body or "<th>note</th>" not in body:
        raise FlowError(f"{page}: header row not rendered as <th>: {body[:400]!r}")
    # Data rows, including the quoted field with an embedded comma.
    if "<td>Alice</td>" not in body or "<td>a, b</td>" not in body:
        raise FlowError(f"{page}: data row / quoted field not rendered: {body[:400]!r}")
    # A markup cell must be escaped, never emitted as live HTML.
    if MARKUP_PAYLOAD in body:
        raise FlowError(f"{page}: markup cell survived as live HTML: {body[:400]!r}")
    if "&lt;b&gt;bold&lt;/b&gt;" not in body:
        raise FlowError(f"{page}: markup cell was not escaped into the table cell: {body[:400]!r}")
    print(f"[ok] {page}: csv-table rendered, markup escaped")


def csv_flow(op, api: str, base: str, keep: bool) -> None:
    stamp = int(time.time())
    file_name = f"CSVTable E2E {stamp}.csv"
    file_title = f"File:{file_name}"
    page_title = f"CSVTable E2E {stamp}"
    created: list[str] = []

    try:
        up = api_upload(op, api, file_name, CSV_CONTENT.encode("utf-8"))
        if up.get("upload", {}).get("result") != "Success":
            raise FlowError(f"upload failed: {up!r}")
        print(f"[ok] uploaded {file_title}")

        info = file_info(op, api, file_title)
        if info.get("mime") != "text/csv":
            raise FlowError(f"wrong MIME for {file_title}: {info.get('mime')!r} "
                            "(expected text/csv)")

        create_page(op, api, page_title, f"CSVTable E2E scratch.\n\n[[File:{file_name}]]\n")
        created.append(page_title)
        assert_table(page_get(op, base, rendered_path(page_title)), page_title)

        # The File: page itself renders the table too.
        assert_table(page_get(op, base, rendered_path(file_title)), file_title)
    finally:
        if keep:
            print(f"[keep] leaving {len(created)} scratch page(s) + {file_title}")
        else:
            for page in reversed(created):
                try:
                    delete_page(op, api, page)
                except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                    print(f"[warn] cleanup of {page} failed: {exc}")
            try:
                delete_page(op, api, file_title)
            except Exception as exc:  # noqa: BLE001
                print(f"[warn] cleanup of {file_title} failed: {exc}")
            print(f"[ok] cleanup: {len(created)} page(s) + {file_title} deleted")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--api-url", required=True)
    ap.add_argument("--user", required=True)
    ap.add_argument("--password-file", required=True)
    ap.add_argument("--keep", action="store_true", help="keep the scratch page + file")
    args = ap.parse_args()

    with open(args.password_file, encoding="utf-8") as fh:
        password = fh.read().strip()

    op = make_opener(args.base_url)
    login(op, args.api_url, args.user, password)
    check_siteinfo(op, args.api_url)
    csv_flow(op, args.api_url, args.base_url, args.keep)
    print("csv E2E: all checks passed")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except FlowError as exc:
        print(f"csv E2E FAILED: {exc}")
        raise SystemExit(1)
