#!/usr/bin/env python3
"""E2E for the GeoGebra extension (interactive [[File:x.ggb]] embeds).

Checks the full contract of a GeoGebra worksheet embed on a live instance:

1. the `GeoGebra` extension is loaded and `ggb` is an allowed upload extension
   (siteinfo),
2. a `.ggb` uploads and is typed `application/geogebra` (the MIME hooks),
3. a page embedding `[[File:name.ggb|600px]]` renders a sandboxed player
   iframe — the class, the configured player URL carrying the file URL and
   size, `sandbox`/`allow`/`loading` attributes,
4. the player origin serves the player page and the installed GeoGebra app
   (`GeoGebra/deployggb.js`),
5. the wiki serves the `.ggb` with a CORS header for the player origin (the
   cross-origin fetch the applet needs),
6. injection attempts do not survive rendering,
7. cleanup: the scratch page and file are deleted (self-cleaning).

Usage::

    python3 tests/e2e/run_geogebra_e2e.py \\
        --base-url https://wikibase.ronzz.org \\
        --api-url https://wikibase.ronzz.org/api.php \\
        --user SeedBot --password-file seed/.seedbot.pass \\
        [--player-url https://ggb.ronzz.org/player.html] [--keep]

Exit code 0 = all checks passed. Requires a user with upload + edit + delete
rights (SeedBot / CIAdmin). When `--player-url` is omitted the player checks
are skipped (only the embed contract is asserted).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import http.cookiejar
import io
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

UA = "ronzz-wikibase-geogebra-e2e/1.0"

IFRAME_RE = re.compile( r"<iframe[^>]*\bclass=\"ggb-embed\"[^>]*>", re.S )


class FlowError(Exception):
    """Raised when a GeoGebra check fails."""


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
        "summary": "GeoGebra E2E scratch (run_geogebra_e2e.py)", "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"creation of {title} failed: {r!r}")


def delete_page(op, api: str, title: str) -> None:
    token = csrf_token(op, api)
    api_call(op, api, {
        "action": "delete", "title": title, "token": token,
        "reason": "GeoGebra E2E cleanup (run_geogebra_e2e.py)", "format": "json",
    }, post=True)


def make_ggb() -> bytes:
    """A minimal but structurally valid .ggb (a ZIP with geogebra.xml)."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(
            "geogebra.xml",
            '<?xml version="1.0" encoding="utf-8"?>\n'
            '<geogebra format="5.0">\n'
            '<construction>\n'
            '<element type="point" label="A"><coords x="1" y="1" z="1"/></element>\n'
            '</construction>\n</geogebra>\n',
        )
        zf.writestr("geogebra_thumbnail.png", b"\x89PNG\r\n\x1a\n")
    return buf.getvalue()


def api_upload(op, api: str, filename: str, content: bytes) -> dict:
    """POST action=upload with the file as multipart/form-data."""
    token = csrf_token(op, api)
    boundary = "----ronzzggb" + "".join(str(i) for i in range(12))

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
             f'filename="{filename}"\r\nContent-Type: application/geogebra\r\n\r\n').encode()
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
    if "GeoGebra" not in extensions:
        raise FlowError(f"GeoGebra extension not loaded (siteinfo extensions: {extensions})")
    # siteinfo fileextensions is a list of dicts ({"ext": "ggb"}).
    exts = [e.get("ext") if isinstance(e, dict) else e for e in r["query"]["fileextensions"]]
    if "ggb" not in exts:
        raise FlowError(f"ggb is not an allowed upload extension (fileextensions: {exts})")
    print("[ok] GeoGebra loaded, ggb uploadable")


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


def assert_embed(body: str, page: str, player_url: str | None, file_url: str) -> str:
    match = IFRAME_RE.search(body)
    if match is None:
        raise FlowError(f"{page}: no ggb-embed iframe found in the rendered page")
    iframe = match.group(0)
    for attr in ("sandbox=", "allow=", "loading=", "referrerpolicy="):
        if attr not in iframe:
            raise FlowError(f"{page}: iframe missing {attr}: {iframe[:300]!r}")
    if 'sandbox="' not in iframe or "allow-scripts" not in iframe:
        raise FlowError(f"{page}: iframe sandbox does not allow scripts: {iframe[:300]!r}")
    if "<script" in iframe.lower():
        raise FlowError(f"{page}: unexpected <script> inside the iframe tag: {iframe[:300]!r}")

    src_match = re.search(r'src="([^"]+)"', iframe)
    if src_match is None:
        raise FlowError(f"{page}: iframe has no src")
    src = src_match.group(1).replace("&amp;", "&")
    parsed = urllib.parse.urlparse(src)
    query = urllib.parse.parse_qs(parsed.query)
    if player_url is not None:
        if not src.startswith(player_url):
            raise FlowError(f"{page}: iframe src does not start with the player URL: {src!r}")
    elif "player.html" not in src:
        raise FlowError(f"{page}: iframe src does not reference the player: {src!r}")
    if query.get("file", [None])[0] != file_url:
        raise FlowError(f"{page}: iframe file param {query.get('file')!r} != {file_url!r}")
    if "w" not in query or "h" not in query:
        raise FlowError(f"{page}: iframe src lacks size params: {src!r}")
    print(f"[ok] {page}: sandboxed player iframe ({src[:90]}…)")
    return src


def fetch_status(op, url: str, headers: dict | None = None) -> tuple[int, str, dict]:
    req = urllib.request.Request(url, headers={"User-Agent": UA, **(headers or {})})
    try:
        with op.open(req, timeout=90) as resp:
            return resp.status, resp.read().decode("utf-8", "replace"), dict(resp.headers)
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", "replace"), dict(exc.headers)


def check_player(op, player_url: str) -> None:
    status, body, _ = fetch_status(op, player_url)
    if status != 200:
        raise FlowError(f"player page {player_url} returned HTTP {status}")
    if "player.js" not in body:
        raise FlowError(f"player page {player_url} does not load player.js")
    base = player_url.rsplit("/", 1)[0]
    status, _, _ = fetch_status(op, base + "/GeoGebra/deployggb.js")
    if status != 200:
        raise FlowError(f"GeoGebra app {base}/GeoGebra/deployggb.js returned HTTP {status} "
                        "(run tools/install-geogebra.sh)")
    print(f"[ok] player origin serves the player + the GeoGebra app")


def check_cors(op, base: str, file_url: str, player_url: str) -> None:
    path = urllib.parse.urlparse(file_url).path
    origin = "{0.scheme}://{0.netloc}".format(urllib.parse.urlparse(player_url))
    status, _, headers = fetch_status(op, base + path, {"Origin": origin})
    if status != 200:
        raise FlowError(f"file fetch {base + path} returned HTTP {status}")
    acao = headers.get("Access-Control-Allow-Origin") or headers.get("access-control-allow-origin")
    if not acao:
        raise FlowError(
            "the wiki does not send Access-Control-Allow-Origin for .ggb — the cross-origin "
            "applet fetch will fail (production: nginx rule; CI: the enable-headers step)")
    print(f"[ok] .ggb served with Access-Control-Allow-Origin: {acao}")


def geogebra_flow(op, api: str, base: str, player_url: str | None, keep: bool) -> None:
    stamp = int(time.time())
    file_name = f"GeoGebra E2E {stamp}.ggb"
    file_title = f"File:{file_name}"
    page_title = f"GeoGebra E2E {stamp}"
    created: list[str] = []

    try:
        up = api_upload(op, api, file_name, make_ggb())
        if up.get("upload", {}).get("result") != "Success":
            raise FlowError(f"upload failed: {up!r}")
        print(f"[ok] uploaded {file_title}")

        info = file_info(op, api, file_title)
        if info.get("mime") != "application/geogebra":
            raise FlowError(f"wrong MIME for {file_title}: {info.get('mime')!r} "
                            "(the MimeMagic hooks did not register)")
        print("[ok] MIME is application/geogebra")

        create_page(op, api, page_title, f"GeoGebra E2E scratch.\n\n[[File:{file_name}|600px]]\n")
        created.append(page_title)
        body = page_get(op, base, rendered_path(page_title))
        assert_embed(body, page_title, player_url, info["url"])

        # An injection in the caption must not survive.
        xss_page = f"GeoGebra E2E xss {stamp}"
        create_page(op, api, xss_page,
                    f"XSS probe.\n\n[[File:{file_name}|600px|<script>alert(1)</script>]]\n")
        created.append(xss_page)
        xss_body = page_get(op, base, rendered_path(xss_page))
        if "<script>alert(1)</script>" in xss_body:
            raise FlowError(f"{xss_page}: script injection survived rendering")
        assert_embed(xss_body, xss_page, player_url, info["url"])
        print(f"[ok] {xss_page}: injection did not survive")

        if player_url is not None:
            check_player(op, player_url)
            check_cors(op, base, info["url"], player_url)
        else:
            print("[skip] --player-url not given — player/CORS checks skipped")
    finally:
        if keep:
            print(f"[keep] leaving {len(created)} scratch pages + {file_title}")
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
            print(f"[ok] cleanup: {len(created)} pages + {file_title} deleted")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--api-url", required=True)
    ap.add_argument("--user", required=True)
    ap.add_argument("--password-file", required=True)
    ap.add_argument("--player-url", default=None,
                    help="the configured $wgGeoGebraPlayerUrl (enables the player/CORS checks)")
    ap.add_argument("--keep", action="store_true", help="keep the scratch page + file")
    args = ap.parse_args()

    with open(args.password_file, encoding="utf-8") as fh:
        password = fh.read().strip()

    op = make_opener(args.base_url)
    login(op, args.api_url, args.user, password)
    check_siteinfo(op, args.api_url)
    geogebra_flow(op, args.api_url, args.base_url, args.player_url, args.keep)
    print("geogebra E2E: all checks passed")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except FlowError as exc:
        print(f"geogebra E2E FAILED: {exc}")
        raise SystemExit(1)
