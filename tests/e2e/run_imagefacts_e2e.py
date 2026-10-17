#!/usr/bin/env python3
r"""E2E for action=imagefacts (semantic image facts for API uploads).

The Special:Upload form itemizes uploads through UploadComplete; API
uploads (the MediaWiki MCP server, scripts) bypass that hook. This suite
proves the API counterpart writes the same facts:

1. upload a tiny PNG through action=upload (no form marker),
2. resolve the image vocabulary by label (properties: instance of, image,
   license, image author, additional license information, source URL) and a
   license item,
3. POST action=imagefacts with the file title + facts,
4. read the returned item and assert the sitelink and every statement,
5. cleanup: unlink + delete the item and the File: page.

Usage::

    python3 tests/e2e/run_imagefacts_e2e.py \
        --base-url https://wikibase.ronzz.org \
        --api-url https://wikibase.ronzz.org/api.php \
        --user SeedBot --password-file seed/.seedbot.pass \
        [--keep]

Exit code 0 = all checks passed. Requires upload + edit + delete rights.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import base64
import http.cookiejar
import json
import sys
import time
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-imagefacts-e2e/1.0"

PNG_1X1 = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
)

AUTHOR = "Ronzz E2E"
LICENSE_INFO = "Test fixture, no rights reserved"
SOURCE_URL = "https://example.org/ronzz-imagefacts-e2e.png"


class FlowError(Exception):
    """Raised when an imagefacts check fails."""


class HostRewritingRedirect(urllib.request.HTTPRedirectHandler):
    """Rewrites redirects to the base URL host (dev-stack $wgServer is the
    internal container hostname the runner cannot resolve)."""

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
        data = json.load(resp)
    if "error" in data:
        raise FlowError(f"API error: {data['error']}")
    return data


def login(op, api: str, user: str, password: str) -> None:
    token = api_call(op, api, {"action": "query", "meta": "tokens", "type": "login",
                               "format": "json"})["query"]["tokens"]["logintoken"]
    r = api_call(op, api, {"action": "login", "lgname": user, "lgpassword": password,
                           "lgtoken": token, "format": "json"}, post=True)
    if r.get("login", {}).get("result") != "Success":
        raise FlowError(f"login failed: {r}")


def csrf_token(op, api: str) -> str:
    return api_call(op, api, {"action": "query", "meta": "tokens",
                              "format": "json"})["query"]["tokens"]["csrftoken"]


def search_entity(op, api: str, label: str, entity_type: str) -> str:
    r = api_call(op, api, {"action": "wbsearchentities", "search": label,
                           "language": "en", "type": entity_type, "limit": 10,
                           "format": "json"})
    for hit in r.get("search", []):
        if (hit.get("label") or "").casefold() == label.casefold():
            return hit["id"]
    raise FlowError(f"no {entity_type} labelled {label!r}: {r.get('search')!r}")


def upload_png(op, api: str, filename: str, token: str) -> None:
    boundary = "----ronzzimagefacts" + str(int(time.time()))
    text = "Image-facts E2E fixture (run_imagefacts_e2e.py)."

    def field(name: str, value: str) -> bytes:
        return (f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n'
                f'{value}\r\n').encode()

    body = b""
    for name, value in (
        ("action", "upload"), ("filename", filename), ("token", token),
        ("ignorewarnings", "1"), ("text", text), ("format", "json"),
    ):
        body += field(name, value)
    body += (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; '
             f'filename="{filename}"\r\nContent-Type: image/png\r\n\r\n').encode()
    body += PNG_1X1
    body += f"\r\n--{boundary}--\r\n".encode()

    req = urllib.request.Request(api, data=body, headers={
        "User-Agent": UA,
        "Content-Type": f"multipart/form-data; boundary={boundary}",
    })
    with op.open(req, timeout=180) as resp:
        r = json.load(resp)
    if r.get("upload", {}).get("result") != "Success":
        raise FlowError(f"upload failed: {r!r}")


def claim_values(item: dict, prop: str) -> list:
    values = []
    for statement in item.get("claims", {}).get(prop, []):
        value = statement.get("mainsnak", {}).get("datavalue", {}).get("value")
        if isinstance(value, dict) and "id" in value:
            values.append(value["id"])
        else:
            values.append(value)
    return values


def fetch_item(op, api: str, item_id: str) -> dict:
    r = api_call(op, api, {"action": "wbgetentities", "ids": item_id,
                           "props": "claims|sitelinks|labels",
                           "format": "json"})
    return r["entities"][item_id]


def check_item(item: dict, file_title: str, expected: dict) -> None:
    sitelink = (item.get("sitelinks", {}).get("wikibase") or {}).get("title")
    if sitelink != file_title:
        raise FlowError(f"sitelink {sitelink!r} != {file_title!r}")

    for prop, wanted in expected.items():
        got = claim_values(item, prop)
        if wanted not in got:
            raise FlowError(f"{prop}: {wanted!r} not in {got!r}")


def cleanup(op, api: str, item_id: str, file_title: str, token: str) -> None:
    try:
        api_call(op, api, {"action": "wbsetsitelink", "id": item_id,
                           "linksite": "wikibase", "linktitle": "",
                           "token": token, "summary": "imagefacts E2E cleanup",
                           "format": "json"}, post=True)
        api_call(op, api, {"action": "delete", "title": f"Item:{item_id}",
                           "token": token, "reason": "imagefacts E2E cleanup",
                           "format": "json"}, post=True)
        api_call(op, api, {"action": "delete", "title": file_title,
                           "token": token, "reason": "imagefacts E2E cleanup",
                           "format": "json"}, post=True)
    except Exception as error:  # noqa: BLE001 - best-effort cleanup, reported below
        print(f"cleanup warning: {error}", file=sys.stderr)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--api-url", required=True)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password-file", required=True)
    parser.add_argument("--keep", action="store_true", help="keep the test item/file")
    args = parser.parse_args()

    password = open(args.password_file, encoding="utf-8").read().strip()
    op = make_opener(args.base_url)
    try:
        login(op, args.api_url, args.user, password)
        token = csrf_token(op, args.api_url)

        filename = f"Ronzz-imagefacts-e2e-{int(time.time())}.png"
        file_title = f"File:{filename}"
        upload_png(op, args.api_url, filename, token)

        instance_of = search_entity(op, args.api_url, "instance of", "property")
        image = search_entity(op, args.api_url, "image", "property")
        license_prop = search_entity(op, args.api_url, "license", "property")
        author_prop = search_entity(op, args.api_url, "image author", "property")
        info_prop = search_entity(op, args.api_url,
                                  "additional license information", "property")
        source_prop = search_entity(op, args.api_url, "source URL", "property")
        license_item = search_entity(op, args.api_url, "CC0 1.0", "item")

        r = api_call(op, args.api_url, {
            "action": "imagefacts", "filename": file_title,
            "description": "Image-facts E2E fixture",
            "license": license_item, "author": AUTHOR,
            "licenseInfo": LICENSE_INFO, "sourceUrl": SOURCE_URL,
            "token": token, "format": "json",
        }, post=True)
        item_id = r.get("imagefacts", {}).get("itemId")
        if not item_id:
            raise FlowError(f"imagefacts returned no itemId: {r!r}")

        item = fetch_item(op, args.api_url, item_id)
        check_item(item, file_title, {
            license_prop: license_item,
            author_prop: AUTHOR,
            info_prop: LICENSE_INFO,
            source_prop: SOURCE_URL,
        })
        if not claim_values(item, instance_of):
            raise FlowError(f"{item_id} has no instance-of statement")
        image_values = claim_values(item, image)
        if not any(str(value).endswith(file_title) for value in image_values):
            raise FlowError(f"{image}: no value ends with {file_title!r}: {image_values!r}")

        print(f"OK imagefacts: {file_title} -> {item_id}")
        if not args.keep:
            cleanup(op, args.api_url, item_id, file_title, token)
        return 0
    except FlowError as error:
        print(f"FAIL imagefacts: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
