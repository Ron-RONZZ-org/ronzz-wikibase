#!/usr/bin/env python3
"""E2E for the issue-#7 Special pages + the v1 content form (page flows).

Drives the REAL Special-page flows against a live (login-gated) instance —
`Special:AddPerson` / `Special:AddSource` / `Special:AddCollective`
(search -> select -> review -> create, incl. harvest-on-pick and the issue-#12
hand-edition step), the manual-entry fallback (`Special:AddPerson/manual`)
and the v1 `Special:AddQuotation` form — then verifies the created items
carry the expected class, authority IDs, citation metadata and
import-provenance references. Issue #24 adds the cite-by-QID flow: a
self-cleaning scratch page with `<ref>{{#cite:…}}</ref>` + `<references/>` +
`{{#citations:}}`, asserting the footnotes and the deduped bibliography
(requires the stock Cite extension on the instance).

Usage::

    python3 tests/e2e/run_pages_e2e.py \\
        --base-url https://wikibase.ronzz.org \\
        --user SeedBot --password-file seed/.seedbot.pass \\
        [--keep] [--person "Grace Hopper"] [--doi 10.1371/journal.pbio.2001414] \\
        [--collective "The Beatles"]

Exit code 0 = all flows passed. Items created by this run are DELETED at the
end (create-or-skip makes re-runs safe; --keep retains them). Requires a
user with sysop rights (for cleanup) and edit rights (SeedBot / CIAdmin).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import base64
import http.cookiejar
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-pages-e2e/1.0"


class FlowError(Exception):
    """Raised when a page-flow check fails."""


# ---------------------------------------------------------------- plumbing


class HostRewritingRedirect(urllib.request.HTTPRedirectHandler):
    """Rewrites redirect targets to the base URL's host.

    MediaWiki redirects (login, form success, post-PRG) use the wiki's
    canonical $wgServer — which on a docker stack is an internal container
    hostname (e.g. http://wikibase) that the runner cannot resolve. Keep the
    path, swap the host back to the base URL.
    """

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
    # MUST go through the opener: urlopen() uses the default opener, which
    # has no cookie processor and the login token would never match.
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
        raise FlowError(f"login failed: {r.get('login', {})}")


def page_get(op, base: str, path: str) -> tuple[str, str]:
    """GET a page; returns (final url, body)."""
    req = urllib.request.Request(base + path, headers={"User-Agent": UA})
    with op.open(req, timeout=120) as resp:
        return resp.geturl(), resp.read().decode("utf-8", "replace")


def page_post(op, url: str, fields: dict) -> tuple[str, str]:
    req = urllib.request.Request(url, data=urllib.parse.urlencode(fields).encode(),
                                 headers={"User-Agent": UA})
    with op.open(req, timeout=180) as resp:
        return resp.geturl(), resp.read().decode("utf-8", "replace")


def page_post_multipart(op, url: str, fields: dict, files: dict) -> tuple[str, str]:
    """POST a form with file uploads (multipart/form-data). `files` maps the
    input name to (filename, bytes, content-type)."""
    boundary = "----ronzzwb" + "".join(str(i) for i in range(10))
    parts = []
    for name, value in fields.items():
        parts.append(
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n"
        )
    for name, (filename, content, ctype) in files.items():
        parts.append(
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"; "
            f"filename=\"{filename}\"\r\nContent-Type: {ctype}\r\n\r\n"
        )
        parts.append(content.decode("latin-1") if isinstance(content, bytes) else content)
        parts.append("\r\n")
    parts.append(f"--{boundary}--\r\n")
    body = "".join(parts).encode("latin-1")
    req = urllib.request.Request(url, data=body, headers={
        "User-Agent": UA,
        "Content-Type": f"multipart/form-data; boundary={boundary}",
    })
    with op.open(req, timeout=180) as resp:
        return resp.geturl(), resp.read().decode("utf-8", "replace")


def edit_token(body: str) -> str:
    m = re.search(r'value="([^"]+)"[^>]*name="wpEditToken"', body)
    if not m:
        m = re.search(r'name="wpEditToken"[^>]*value="([^"]+)"', body)
    if not m:
        raise FlowError("no wpEditToken in the form")
    return m.group(1)


def ooui_widget(body: str, widget_id: str) -> dict:
    """Full data-ooui JSON of an auto-infused OOUI widget."""
    m = re.search(r"id='" + widget_id + r"'[^>]*data-ooui='([^']*)'", body)
    if not m:
        m = re.search(r"data-ooui='([^']*)'[^>]*id='" + widget_id + r"'", body)
    if not m:
        raise FlowError(f"no data-ooui for {widget_id}")
    return json.loads(m.group(1).replace("&quot;", '"').replace("&#039;", "'"))


def ooui_options(body: str, widget_id: str) -> list[dict]:
    """Options of an auto-infused OOUI widget, parsed from data-ooui JSON."""
    return ooui_widget(body, widget_id).get("options", [])


def ooui_label(opt: dict) -> str:
    lbl = opt.get("label", "")
    return lbl.get("html", "") if isinstance(lbl, dict) else str(lbl)


def find_error(body: str) -> str:
    i = body.find("failed")
    if i >= 0:
        return " ".join(re.sub(r"<[^>]+>", " ", body[max(0, i - 120):i + 220]).split())[:260]
    return ""


# ------------------------------------------------------- vocabulary lookup


def resolve_label(op, api: str, label: str, entity_type: str) -> str | None:
    """Exact-label resolution (wbsearchentities ranks fuzzy hits first and
    returns the label in the display language — compare the match text)."""
    wanted = label.strip().lower()
    r = api_call(op, api, {
        "action": "wbsearchentities", "search": label, "language": "en",
        "type": entity_type, "limit": 5, "format": "json",
    })
    for hit in r.get("search", []):
        match_text = str(hit.get("match", {}).get("text", "")).strip().lower()
        if match_text == wanted or str(hit.get("label", "")).strip().lower() == wanted:
            return hit.get("id")
    return None


def entity_claims(op, api: str, qid: str) -> dict:
    r = api_call(op, api, {"action": "wbgetentities", "ids": qid,
                           "props": "labels|claims", "format": "json"})
    entity = r.get("entities", {}).get(qid, {})
    return entity.get("claims", {}), entity.get("labels", {}).get("en", {}).get("value", "")


def entity_descriptions(op, api: str, qid: str) -> dict:
    r = api_call(op, api, {"action": "wbgetentities", "ids": qid,
                           "props": "descriptions", "format": "json"})
    return r.get("entities", {}).get(qid, {}).get("descriptions", {})


def first_value(claims: dict, prop_id: str):
    for stmt in claims.get(prop_id, []):
        dv = stmt.get("mainsnak", {}).get("datavalue", {}).get("value")
        if isinstance(dv, dict):
            dv = dv.get("id", dv)
        return dv
    return None


def first_reference_url(claims: dict, prop_id: str) -> str | None:
    for stmt in claims.get(prop_id, []):
        for ref in stmt.get("references", []):
            for rp, snaks in ref.get("snaks", {}).items():
                for s in snaks:
                    if s.get("datavalue", {}).get("value", "").startswith("http"):
                        return s["datavalue"]["value"]
    return None


# ------------------------------------------------------------- page flows


def flow_search_select_create(op, base: str, api: str, special: str, search_fields: dict,
                              pick_index: int = 0) -> str:
    """Runs the three-step Special page flow (search -> select -> review ->
    create, issue #12); returns the created (or reused) item id."""
    url, body = page_get(op, base, f"/wiki/Special:{special}")
    if "does not have permission" in body or "wpEditToken" not in body:
        raise FlowError(f"Special:{special} not usable (logged-in? got {len(body)} bytes)")
    token = edit_token(body)

    fields = dict(search_fields)
    fields["wpEditToken"] = token
    fields["wpSubmit"] = "1"
    url, body = page_post(op, url, fields)

    m = re.search(rf"/wiki/Special:{special}/([0-9a-f]+)$", url)
    if not m:
        raise FlowError(f"Special:{special} search did not redirect to a selection page: {url} {find_error(body)}")
    token = m.group(1)

    # Selection step: detailed candidate table + radio + class picker.
    candidates = ooui_options(body, "mw-input-wpcandidates")
    if not candidates:
        raise FlowError(f"Special:{special} selection page rendered no candidates")
    # Class: a select when several classes exist, a HIDDEN single-class
    # field when the flow fixed the class (issue follow-up, class-first).
    try:
        cls = ooui_widget(body, "mw-input-wpclass").get("value")
    except FlowError:
        cls = None
    if cls is None:
        m = re.search(r'name="wpclass"[^>]*value="(Q\d+)"', body)
        cls = m.group(1) if m else ""
    index = str(min(pick_index, len(candidates) - 1))
    token2 = edit_token(body)

    url, body = page_post(op, url, {
        "wpcandidates": index,
        "wpclass": cls,
        "wpEditToken": token2,
        "wpSubmit": "1",
    })
    m = re.search(rf"/wiki/Special:{special}/{token}/review/{index}$", url)
    if not m:
        raise FlowError(
            f"Special:{special} select did not redirect to the review step: {url} {find_error(body)}")

    # Review step: pre-filled editable form; submit without changes (issue #12).
    token3 = edit_token(body)
    url, body = page_post(op, url, {
        "wpEditToken": token3,
        "wpSubmit": "1",
    })
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if not m:
        raise FlowError(f"Special:{special} review did not redirect to an item: {url} {find_error(body)}")
    return m.group(1)


def flow_manual(op, base: str, api: str, special: str, label: str, class_item: str) -> str:
    """Manual-entry fallback (issue #12): Special:<name>/manual creates from
    blank (no external record)."""
    url, body = page_get(op, base, f"/wiki/Special:{special}/manual")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wplabel": label,
        "wpclass": class_item,
        "wpEditToken": token,
        "wpSubmit": "1",
    })
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if not m:
        raise FlowError(f"Special:{special}/manual did not create an item: {url} {find_error(body)}")
    return m.group(1)


def flow_software_manual(op, base: str, api: str, label: str, class_item: str,
                         extra_fields: dict | None = None) -> tuple[str, str]:
    """Special:AddSoftware/manual — create from blank (no authority record),
    following the /complete/<qid> finalize redirect to the FOSS page.

    Unlike the search flow, the manual path ALWAYS creates a fresh item
    (create-or-skip cannot reuse a timestamped label), so it deterministically
    exercises the multi-value entity fields: extra_fields carries comma-
    separated item ids, e.g. {"wphasUse": "Q1, Q2"}.

    Returns (item qid, FOSS page title).
    """
    url, body = page_get(op, base, "/wiki/Special:AddSoftware/manual")
    token = edit_token(body)
    fields = {
        "wplabel": label,
        "wpclass": class_item,
        "wpEditToken": token,
        "wpSubmit": "1",
    }
    if extra_fields:
        fields.update(extra_fields)
    url, body = page_post(op, url, fields)
    m = re.search(r"/wiki/(FOSS:[^?#]+)$", url)
    if not m:
        raise FlowError(
            f"AddSoftware/manual did not redirect to a FOSS page: {url} {find_error(body)}")
    page_title = urllib.parse.unquote(m.group(1)).replace("_", " ")

    r = api_call(op, api, {"action": "query", "titles": page_title,
                           "prop": "pageprops", "format": "json"})
    qid = None
    for page in r.get("query", {}).get("pages", {}).values():
        qid = page.get("pageprops", {}).get("wikibase_item")
    if not qid:
        raise FlowError(f"AddSoftware/manual page {page_title} has no wikibase_item "
                        f"(finalize step did not map the sitelink)")
    return qid, page_title


def flow_software(op, base: str, api: str, name: str) -> tuple[str, str]:
    """Special:AddSoftware search -> select -> review -> create (issue #26).

    Unlike the other flows, success redirects to the created FOSS: page (the
    item + page + sitelink are created together). Returns (item qid, FOSS
    page title) — the qid is resolved from the page's wikibase_item property.
    """
    url, body = page_get(op, base, "/wiki/Special:AddSoftware")
    if "does not have permission" in body or "wpEditToken" not in body:
        raise FlowError(f"Special:AddSoftware not usable (logged-in? got {len(body)} bytes)")
    token = edit_token(body)
    url, body = page_post(op, url, {"wpname": name, "wpEditToken": token, "wpSubmit": "1"})
    m = re.search(r"/wiki/Special:AddSoftware/([0-9a-f]+)$", url)
    if not m:
        raise FlowError(
            f"AddSoftware search did not redirect to a selection page: {url} {find_error(body)}")
    token = m.group(1)

    # Selection step: detailed candidate table + radio + class picker.
    candidates = ooui_options(body, "mw-input-wpcandidates")
    if not candidates:
        raise FlowError("AddSoftware selection page rendered no candidates")
    # Class: a select when several classes exist, a HIDDEN single-class
    # field when the flow fixed the class (issue follow-up, class-first).
    try:
        cls = ooui_widget(body, "mw-input-wpclass").get("value")
    except FlowError:
        cls = None
    if cls is None:
        m = re.search(r'name="wpclass"[^>]*value="(Q\d+)"', body)
        cls = m.group(1) if m else ""
    token2 = edit_token(body)
    url, body = page_post(op, url, {
        "wpcandidates": "0",
        "wpclass": cls,
        "wpEditToken": token2,
        "wpSubmit": "1",
    })
    m = re.search(rf"/wiki/Special:AddSoftware/{token}/review/0$", url)
    if not m:
        raise FlowError(
            f"AddSoftware select did not redirect to the review step: {url} {find_error(body)}")

    # Review step: submit the pre-filled form unchanged.
    token3 = edit_token(body)
    url, body = page_post(op, url, {"wpEditToken": token3, "wpSubmit": "1"})
    m = re.search(r"/wiki/(FOSS:[^?#]+)$", url)
    if not m:
        raise FlowError(f"AddSoftware review did not redirect to a FOSS page: {url} {find_error(body)}")
    page_title = urllib.parse.unquote(m.group(1)).replace("_", " ")

    # The created item is the page's wikibase_item (pageproperty, set from
    # the sitelink at parse time).
    r = api_call(op, api, {"action": "query", "titles": page_title,
                           "prop": "pageprops", "format": "json"})
    qid = None
    for page in r.get("query", {}).get("pages", {}).values():
        qid = page.get("pageprops", {}).get("wikibase_item")
    if not qid:
        # Diagnostics: raw page state (existence, revisions, props) so a
        # failure pinpoints whether the finalize edit ran and the parse
        # mapped the page.
        diag = api_call(op, api, {"action": "query", "titles": page_title,
                                  "prop": "revisions|pageprops",
                                  "rvprop": "ids|timestamp|comment", "format": "json"})
        raise FlowError(f"FOSS page {page_title} has no wikibase_item page property "
                        f"(sitelink missing?); raw: {json.dumps(diag)}")
    return qid, page_title


def flow_software_logo(op, base: str, api: str, label: str, class_item: str) -> tuple[str, str]:
    """Special:AddSoftware/manual with a LOCAL LOGO upload (issue follow-up):
    posts a 1x1 PNG via multipart, then verifies the created item carries the
    image statement, the File:<label>-logo.png page exists and the FOSS page
    skeleton passes the logo to the infobox. Returns (qid, FOSS page title)."""
    # 1x1 transparent PNG.
    png = base64.b64decode(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
    )
    url, body = page_get(op, base, "/wiki/Special:AddSoftware/manual")
    token = edit_token(body)
    url, body = page_post_multipart(op, url, {
        "wplabel": label,
        "wpclass": class_item,
        "wplogoMode": "file",
        "wpEditToken": token,
        "wpSubmit": "1",
    }, {
        # HTMLForm keeps the field key's casing: "logoFile" -> "wpLogoFile".
        "wpLogoFile": ("logo.png", png, "image/png"),
    })
    m = re.search(r"/wiki/(FOSS:[^?#]+)$", url)
    if not m:
        # Diagnostics: surface the server-side logo-upload warning box, which
        # carries the verifyUpload/performUpload reason (e.g. uploads
        # disabled, MIME mismatch).
        diag = re.sub(r"<[^>]+>", " ", body)
        i = diag.find("Logo upload failed")
        hint = " ".join(diag[max(0, i - 60):i + 220].split())[:260] if i >= 0 else find_error(body)
        raise FlowError(
            f"AddSoftware/manual (logo) did not redirect to a FOSS page: {url} {hint}")
    page_title = urllib.parse.unquote(m.group(1)).replace("_", " ")

    r = api_call(op, api, {"action": "query", "titles": page_title,
                           "prop": "pageprops", "format": "json"})
    qid = None
    for page in r.get("query", {}).get("pages", {}).values():
        qid = page.get("pageprops", {}).get("wikibase_item")
    if not qid:
        raise FlowError(f"AddSoftware/manual (logo) page {page_title} has no wikibase_item")
    return qid, page_title


def flow_person(op, base: str, api: str, name: str) -> str:
    return flow_search_select_create(op, base, api, "AddPerson", {"wpname": name})


def flow_source_class_first(op, base: str, api: str, class_key: str, search_fields: dict,
                            review_extra: dict | None = None, pick_index: int = 0) -> str:
    """Class-first AddSource flow (issue follow-up): class picker ->
    class-scoped search -> selection (hidden class) -> review (authors +
    class extras) -> create. Returns the created (or reused) item id."""
    url, body = page_get(op, base, "/wiki/Special:AddSource")
    if "does not have permission" in body or "wpEditToken" not in body:
        raise FlowError(f"Special:AddSource not usable (logged-in? got {len(body)} bytes)")
    token = edit_token(body)
    url, body = page_post(op, url, {"wpclass": class_key, "wpEditToken": token, "wpSubmit": "1"})
    m = re.search(rf"/wiki/Special:AddSource/{class_key}$", url)
    if not m:
        raise FlowError(f"AddSource class picker did not route to {class_key}: {url} {find_error(body)}")
    token = edit_token(body)
    fields = dict(search_fields)
    fields["wpEditToken"] = token
    fields["wpSubmit"] = "1"
    url, body = page_post(op, url, fields)
    m = re.search(rf"/wiki/Special:AddSource/{class_key}/([0-9a-f]+)$", url)
    if not m:
        raise FlowError(
            f"AddSource/{class_key} search did not redirect to a selection page: {url} {find_error(body)}")
    token = m.group(1)

    candidates = ooui_options(body, "mw-input-wpcandidates")
    if not candidates:
        raise FlowError(f"AddSource/{class_key} selection page rendered no candidates")
    cls = re.search(r'name="wpclass"[^>]*value="(Q\d+)"', body)
    cls = cls.group(1) if cls else (ooui_widget(body, "mw-input-wpclass").get("value") or "")
    index = str(min(pick_index, len(candidates) - 1))
    token2 = edit_token(body)
    url, body = page_post(op, url, {
        "wpcandidates": index,
        "wpclass": cls,
        "wpEditToken": token2,
        "wpSubmit": "1",
    })
    m = re.search(rf"/wiki/Special:AddSource/{class_key}/{token}/review/{index}$", url)
    if not m:
        raise FlowError(
            f"AddSource/{class_key} select did not redirect to the review step: {url} {find_error(body)}")

    token3 = edit_token(body)
    review = {"wpEditToken": token3, "wpSubmit": "1"}
    if review_extra:
        review.update(review_extra)
    url, body = page_post(op, url, review)
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if not m:
        raise FlowError(f"AddSource/{class_key} review did not redirect to an item: {url} {find_error(body)}")
    return m.group(1)


def flow_source_class_manual(op, base: str, api: str, class_key: str, fields: dict) -> str:
    """Class-first manual flow: Special:AddSource/<classKey>/manual creates
    from blank with the class-scoped fields (the class is a hidden field)."""
    url, body = page_get(op, base, f"/wiki/Special:AddSource/{class_key}/manual")
    token = edit_token(body)
    post = {"wpEditToken": token, "wpSubmit": "1"}
    post.update(fields)
    url, body = page_post(op, url, post)
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if not m:
        raise FlowError(f"AddSource/{class_key}/manual did not create an item: {url} {find_error(body)}")
    return m.group(1)


def flow_sitelink_tab(op, base: str, api: str, linked_page: str, linked_qid: str) -> None:
    """Sitelink tab rendering (issue follow-up): red (needs-set) on an
    unlinked content page, blue (is-set) on a sitelinked page."""
    csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
    token = csrf["query"]["tokens"]["csrftoken"]
    test_title = f"Page-flow E2E sitelink {int(time.time())}"
    api_call(op, api, {"action": "edit", "title": test_title, "text": "temporary page",
                       "token": token, "format": "json"}, post=True)
    try:
        _, body = page_get(op, base, "/wiki/" + urllib.parse.quote(test_title.replace(" ", "_")))
        if "ca-sitelink needs-set" not in body:
            raise FlowError(f"{test_title} missing the red Sitelink tab (needs-set)")
        print(f"[ok] Sitelink tab: red (needs-set) on unlinked page {test_title}")
    finally:
        try:
            api_call(op, api, {"action": "delete", "title": test_title, "token": token,
                               "reason": "page-flow E2E cleanup (run_pages_e2e.py)",
                               "format": "json"}, post=True)
        except Exception as exc:  # noqa: BLE001 — best-effort cleanup
            print(f"  ! cleanup failed for {test_title}: {exc}")

    _, body = page_get(op, base, "/wiki/" + urllib.parse.quote(linked_page.replace(" ", "_")))
    if "ca-sitelink is-set" not in body:
        raise FlowError(f"{linked_page} missing the blue Sitelink tab (is-set)")
    print(f"[ok] Sitelink tab: blue (is-set) on {linked_page} -> item {linked_qid}")


def flow_collective(op, base: str, api: str, name: str) -> str:
    return flow_search_select_create(op, base, api, "AddCollective", {"wpname": name})


def flow_math(op, base: str, api: str, label: str, latex: str, describes_qid: str) -> str:
    """Special:AddMath with the 'describes' subject field (issue follow-up)."""
    url, body = page_get(op, base, "/wiki/Special:AddMath")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wplabel": label,
        "wppayload": latex,
        "wpdescribes": describes_qid,
        "wpEditToken": token,
        "wpSubmit": "1",
    })
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if not m:
        raise FlowError(f"Special:AddMath did not redirect to an item: {url} {find_error(body)}")
    return m.group(1)


def flow_quotation(op, base: str, api: str, label: str, payload: str, person_qid: str) -> str:
    url, body = page_get(op, base, "/wiki/Special:AddQuotation")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wplabel": label,
        "wppayload": payload,
        "wplanguage": "en",
        # NOTE: HTMLForm request names keep the field key's casing — the
        # provenance field is "attributedTo" -> "wpattributedTo".
        "wpattributedTo": person_qid,
        "wpEditToken": token,
        "wpSubmit": "1",
    })
    # Success must redirect to the created item (issue follow-up). With a
    # unique label per run the redirect is deterministic; fall back to
    # label resolution only if create-or-skip reused a stale item.
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if m:
        return m.group(1)
    for _ in range(10):
        qid = resolve_label(op, api, label, "item")
        if qid:
            return qid
        time.sleep(2)
    raise FlowError(f"Special:AddQuotation did not redirect to an item: {url} {find_error(body)}")


def delete_item(op, api: str, qid: str) -> None:
    csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
    token = csrf["query"]["tokens"]["csrftoken"]
    api_call(op, api, {"action": "delete", "title": f"Item:{qid}", "token": token,
                       "reason": "page-flow E2E cleanup (run_pages_e2e.py)", "format": "json"}, post=True)


def flow_cite_by_qid(op, base: str, api: str, book_qid: str, quote_qid: str, extra_source_qid: str) -> None:
    """Issue #24/#25: on-wiki cite-by-QID on a self-cleaning scratch page.

    Creates a scratch page with `<ref>{{#cite:…}}</ref>` + `<references/>` +
    `{{#citations:}}`, asserts the rendered footnotes and the deduped
    bibliography (source DOI present — guards the self-cite fix), plus the
    v2 features: a multi-entity ref (book + quotation in one footnote),
    the explicit-args bibliography (`{{#citations:Qbook|Qquote}}`) and the
    embed auto-collect (an embedded source item joins the accumulated
    bibliography). Requires the stock Cite extension (installed on the CI
    stack / production).
    """
    page_title = f"Cite-by-QID E2E {int(time.time())}"
    wikitext = f"""E2E scratch page for cite-by-QID (issue #24/#25).

A quotation, citing its source item through the content item.<ref>{{{{#cite:{quote_qid}}}}}</ref>

The same source, cited directly (self-cite).<ref name="book">{{{{#cite:{book_qid}|style=vancouver}}}}</ref>
Another ref to the same book.<ref name="book2">{{{{#cite:{book_qid}}}}}</ref>

A multi-entity ref, book + quotation in one footnote.<ref>{{{{#cite:{book_qid}|{quote_qid}}}}}</ref>

An embedded source item (v2 auto-collect): {base}/wiki/Special:Embed/{extra_source_qid}

== References ==
<references/>

== Sources ==
{{{{#citations:}}}}

Explicit bibliography (v2): {{{{#citations:{book_qid}|{quote_qid}}}}}
"""
    csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
    token = csrf["query"]["tokens"]["csrftoken"]
    r = api_call(op, api, {
        "action": "edit", "title": page_title, "text": wikitext,
        "token": token, "summary": "page-flow E2E scratch (issue #24/#25, cite-by-QID)",
        "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"scratch page creation failed: {r!r}")

    try:
        _, body = page_get(op, base, "/wiki/" + urllib.parse.quote(page_title.replace(" ", "_")))
        if '<span class="error"' in body or "errorbox" in body:
            raise FlowError("scratch page rendered parser errors (cite-by-QID)")

        # 1. Footnotes: the ref content holds the citation (self-cite guard:
        #    the book's harvested DOI must appear even though no content item
        #    stands between the page and the source item).
        refs = re.findall(r'<span class="reference-text">(.*?)</span>', body, re.S)
        if not refs:
            raise FlowError("no footnote rendered — is the stock Cite extension loaded?")
        if not any("10.1000/notes" in ref for ref in refs):
            raise FlowError("footnote missing the source DOI (self-cite fix broken)")

        # 2. v2 multi-entity ref: ONE footnote holds BOTH citations
        #    (book DOI + quotation author).
        if not any("10.1000/notes" in ref and "Lovelace" in ref for ref in refs):
            raise FlowError("multi-entity ref did not render both citations in one footnote")

        # 3. Bibliographies: the accumulated {{#citations:}} (book + the
        #    embed-auto-collected article = 2 entries) and the explicit
        #    {{#citations:Qbook|Qquote}} (both resolve to the book = 1 entry).
        ols = re.findall(r'<ol class="wikibasecitation-sources">(.*?)</ol>', body, re.S)
        if len(ols) != 2:
            raise FlowError(f"expected 2 bibliography lists (accumulated + explicit), got {len(ols)}")
        accumulated, explicit = ols
        if accumulated.count("<li>") != 2:
            raise FlowError(f"accumulated bibliography has {accumulated.count('<li>')} entries, expected 2 (book + embed-collected article)")
        if "10.1371/journal.pbio.2001414" not in accumulated:
            raise FlowError("embed auto-collect did not add the embedded article to the bibliography")
        if explicit.count("<li>") != 1:
            raise FlowError(f"explicit bibliography has {explicit.count('<li>')} entries, expected 1 (dedupe by source item)")
        if "10.1000/notes" not in explicit:
            raise FlowError("explicit bibliography entry missing the source DOI")
        print(f"[ok] cite-by-QID scratch page: footnotes + multi-entity ref + explicit + embed auto-collect")
    finally:
        csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
        token = csrf["query"]["tokens"]["csrftoken"]
        api_call(op, api, {"action": "delete", "title": page_title, "token": token,
                           "reason": "page-flow E2E cleanup (run_pages_e2e.py)", "format": "json"}, post=True)


# ------------------------------------------------------------------- main


def main() -> int:
    parser = argparse.ArgumentParser(description="E2E page flows for the issue-#7 Special pages")
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    parser.add_argument("--api-url", default=None, help="defaults to <base-url>/api.php")
    parser.add_argument("--user", default="SeedBot")
    parser.add_argument("--password-file", required=True)
    parser.add_argument("--keep", action="store_true", help="retain the created test items")
    parser.add_argument("--person", default="Grace Hopper")
    parser.add_argument("--author", default="Grace Hopper",
                        help="free-text author for the AddSource author search flow")
    parser.add_argument("--author-entity", default="Q42",
                        help="Wikidata author Q-id for the AddSource entity search flow (Q42 = Douglas Adams)")
    parser.add_argument("--doi", default="10.1371/journal.pbio.2001414")
    parser.add_argument("--collective", default="The Beatles")
    parser.add_argument("--software", default="Flameshot")
    args = parser.parse_args()

    base = args.base_url.rstrip("/")
    api = args.api_url or base + "/api.php"
    password = open(args.password_file).read().strip()

    op = make_opener(base)
    login(op, api, args.user, password)
    print(f"[ok] logged in as {args.user}")

    # resolve the vocabulary (instance-specific ids) by exact label
    def resolve(label: str, etype: str) -> str:
        qid = resolve_label(op, api, label, etype)
        if not qid:
            raise FlowError(f"vocabulary label not found: {label!r} (seed the instance first)")
        return qid

    instance_of = resolve("instance of", "property")
    person_class = resolve("person", "item")
    scholarly_class = resolve("scholarly article", "item")
    quotation_class = resolve("quotation content", "item")
    math_class = resolve("mathematical expression", "item")
    describes_prop = resolve("describes", "property")
    wikidata_id_prop = resolve("Wikidata ID", "property")
    doi_prop = resolve("DOI", "property")
    # Class-first AddSource vocabulary (issue follow-up).
    part_of_prop = resolve("part of", "property")
    duration_prop = resolve("duration", "property")
    url_prop = resolve("URL", "property")
    youtube_channel_id_prop = resolve("YouTube channel ID", "property")
    website_class = resolve("website", "item")
    book_excerpt_class = resolve("book excerpt", "item")
    youtube_channel_class = resolve("YouTube channel", "item")
    youtube_video_class = resolve("YouTube video", "item")
    agent_classes = {
        resolve("person", "item"),
        resolve("organization", "item"),
        resolve("group of humans", "item"),
    }
    print(f"[ok] vocabulary resolved (instance-of={instance_of}, person={person_class}, "
          f"scholarly article={scholarly_class})")

    created: list[str] = []
    created_pages: list[str] = []
    # Monotonic id counter: only items created ABOVE this id were made by
    # this run (create-or-skip reuses older items — those must not be
    # deleted). allpages sorts titles LEXICALLY ("Q99" > "Q180"), so the
    # max must be computed numerically across the full namespace.
    def max_item_id() -> int:
        highest = 0
        apcontinue = None
        while True:
            params = {"action": "query", "list": "allpages", "apnamespace": 120,
                      "aplimit": 500, "format": "json"}
            if apcontinue:
                params["apcontinue"] = apcontinue
            r = api_call(op, api, params)
            pages = r.get("query", {}).get("allpages", [])
            for page in pages:
                m = re.search(r"Q(\d+)$", page.get("title", ""))
                if m:
                    highest = max(highest, int(m.group(1)))
            apcontinue = r.get("continue", {}).get("apcontinue")
            if not apcontinue:
                return highest

    max_before = max_item_id()

    def track(qid: str) -> str:
        m = re.search(r"Q(\d+)$", qid)
        if m and int(m.group(1)) > max_before:
            created.append(qid)
        return qid

    try:
        # 1. AddPerson — harvest-on-pick (ORCID/VIAF/ISNI where present)
        person = track(flow_person(op, base, api, args.person))
        claims, label = entity_claims(op, api, person)
        assert first_value(claims, instance_of) == person_class, \
            f"{person} instance-of != person ({first_value(claims, instance_of)})"
        assert first_value(claims, wikidata_id_prop), f"{person} missing Wikidata ID"
        assert first_reference_url(claims, wikidata_id_prop), f"{person} missing import reference"
        print(f"[ok] AddPerson -> {person} ({label}): instance-of person, Wikidata ID + import reference")

        # 2. AddSource (class-first) — DOI -> Crossref, scholarly article
        #    class fixed by the picker, harvested citation metadata, and the
        #    required author entity written as attributed-to.
        source = track(flow_source_class_first(
            op, base, api, "scholarlyArticle",
            {"wpdoi": args.doi}, review_extra={"wpauthors": person}))
        claims, label = entity_claims(op, api, source)
        assert first_value(claims, instance_of) == scholarly_class, \
            f"{source} instance-of != scholarly article ({first_value(claims, instance_of)})"
        assert first_value(claims, doi_prop) == args.doi, f"{source} DOI mismatch"
        assert first_value(claims, resolve("attributed to", "property")) == person, \
            f"{source} missing the required author entity"
        assert first_reference_url(claims, doi_prop), f"{source} missing import reference"
        print(f"[ok] AddSource (class-first, DOI) -> {source} ({label[:60]}…): "
              f"scholarly article, author entity, DOI + import reference")

        # 2a. AddSource author search, free-text mode (class-first): the
        #     author filter narrows the candidates; the class stays fixed.
        source_author = track(flow_source_class_first(
            op, base, api, "scholarlyArticle",
            {"wpauthor": args.author, "wpauthorMode": "text"},
            review_extra={"wpauthors": person}))
        claims, label = entity_claims(op, api, source_author)
        assert first_value(claims, instance_of) == scholarly_class, \
            f"{source_author} instance-of != scholarly article ({first_value(claims, instance_of)})"
        assert first_value(claims, resolve("attributed to", "property")) == person, \
            f"{source_author} missing the required author entity"
        print(f"[ok] AddSource (class-first, author text search) -> {source_author} "
              f"({label[:60]}…): scholarly article + author entity")

        # 2b. AddSource author search, semantic-entity mode (class-first).
        source_entity = track(flow_source_class_first(
            op, base, api, "scholarlyArticle",
            {"wpauthor": args.author_entity, "wpauthorMode": "entity"},
            review_extra={"wpauthors": person}))
        claims, label = entity_claims(op, api, source_entity)
        assert first_value(claims, instance_of) == scholarly_class, \
            f"{source_entity} instance-of != scholarly article ({first_value(claims, instance_of)})"
        print(f"[ok] AddSource (class-first, author entity search) -> {source_entity} "
              f"({label[:60]}…): scholarly article")

        # 2c. Manual-only class: website goes straight to the adapted manual
        #     form (Special:AddSource/website -> /website/manual) and writes
        #     the URL + author statements.
        website_label = f"Page-flow E2E website {int(time.time())}"
        website = track(flow_source_class_manual(op, base, api, "website", {
            "wptitle": website_label, "wpauthors": person,
            "wpurl": "https://example.org/e2e"}))
        claims, _ = entity_claims(op, api, website)
        assert first_value(claims, instance_of) == website_class, \
            f"{website} instance-of != website ({first_value(claims, instance_of)})"
        assert first_value(claims, url_prop) == "https://example.org/e2e", \
            f"{website} missing the URL statement"
        print(f"[ok] AddSource (website, manual-only) -> {website}: website class + URL")

        # 2d. Child class: bookExcerpt requires an existing book parent,
        #     auto-links it with a `part of` statement. Regression: the
        #     description TERM and the year STATEMENT (date property, year
        #     precision) are persisted — both were previously discarded.
        book = resolve("Notes by the Translator", "item")  # seed dogfood book
        excerpt_label = f"Page-flow E2E book excerpt {int(time.time())}"
        excerpt = track(flow_source_class_manual(op, base, api, "bookExcerpt", {
            "wptitle": excerpt_label, "wpauthors": person,
            "wpparent": book, "wppages": "12-30",
            "wpdescription": "Pages 12-30 of a test book.",
            "wpissuedYear": "1967"}))
        claims, _ = entity_claims(op, api, excerpt)
        assert first_value(claims, instance_of) == book_excerpt_class, \
            f"{excerpt} instance-of != book excerpt ({first_value(claims, instance_of)})"
        assert first_value(claims, part_of_prop) == book, \
            f"{excerpt} missing the part-of link to the parent book"
        assert entity_descriptions(op, api, excerpt).get("en", "") == "Pages 12-30 of a test book.", \
            f"{excerpt} description term not persisted"
        date_claim = first_value(claims, resolve("date", "property"))
        assert date_claim is not None and str(date_claim.get("time", "")).startswith("+1967"), \
            f"{excerpt} year statement (date property) not persisted"
        print(f"[ok] AddSource (bookExcerpt) -> {excerpt}: child class + part-of -> {book}; "
              f"description term + year statement persisted")

        # 2e. YouTube chain: a channel (URL -> ID derived server-side), then a
        #     youtubeVideo child of that channel with a duration in seconds.
        channel_label = f"Page-flow E2E channel {int(time.time())}"
        channel = track(flow_source_class_manual(op, base, api, "youtubeChannel", {
            "wptitle": channel_label, "wpauthors": person,
            "wpurl": "https://www.youtube.com/channel/UCE2E2e2e2e2e2e2e2e2e2e2"}))
        claims, _ = entity_claims(op, api, channel)
        assert first_value(claims, instance_of) == youtube_channel_class, \
            f"{channel} instance-of != YouTube channel ({first_value(claims, instance_of)})"
        assert first_value(claims, youtube_channel_id_prop) == "UCE2E2e2e2e2e2e2e2e2e2e2", \
            f"{channel} YouTube channel ID not derived from the URL"
        video_label = f"Page-flow E2E youtube video {int(time.time())}"
        video = track(flow_source_class_manual(op, base, api, "youtubeVideo", {
            "wptitle": video_label, "wpauthors": person,
            "wpparent": channel, "wpduration": "1:02:30"}))
        claims, _ = entity_claims(op, api, video)
        assert first_value(claims, instance_of) == youtube_video_class, \
            f"{video} instance-of != YouTube video ({first_value(claims, instance_of)})"
        assert first_value(claims, part_of_prop) == channel, \
            f"{video} missing the part-of link to the parent channel"
        amount = first_value(claims, duration_prop)
        assert isinstance(amount, dict) and amount.get("amount") == "+3750", \
            f"{video} duration not stored as 3750 seconds ({amount!r})"
        print(f"[ok] AddSource (youtubeVideo) -> {video}: child class + part-of -> {channel}, "
              f"duration 1:02:30 = 3750s")

        # 2f. Author requirement: creation without an author is rejected with
        #     a form error (a source needs at least one author).
        noauthor_label = f"Page-flow E2E noauthor {int(time.time())}"
        url, body = page_get(op, base, "/wiki/Special:AddSource/youtubeVideo/manual")
        token = edit_token(body)
        url2, body2 = page_post(op, url, {
            "wptitle": noauthor_label, "wpEditToken": token, "wpSubmit": "1"})
        if "/wiki/Item:Q" in url2:
            raise FlowError("AddSource/youtubeVideo/manual created an item WITHOUT authors (must fail)")
        if "at least one author" not in body2:
            raise FlowError("AddSource/youtubeVideo/manual without authors produced no author error")
        print("[ok] AddSource author requirement: creation without authors rejected")

        # 3. AddCollective — harvest class hints; instance-of must be an agent class
        collective = track(flow_collective(op, base, api, args.collective))
        claims, label = entity_claims(op, api, collective)
        assert first_value(claims, instance_of) in agent_classes, \
            f"{collective} instance-of not an agent class ({first_value(claims, instance_of)})"
        assert first_value(claims, wikidata_id_prop), f"{collective} missing Wikidata ID"
        print(f"[ok] AddCollective -> {collective} ({label}): agent class + Wikidata ID")

        # 3b. Manual-entry fallback (issue #12): Special:AddPerson/manual
        #     creates from blank, no external record, no import reference.
        manual_label = f"Manual E2E person {int(time.time())}"
        manual = track(flow_manual(op, base, api, "AddPerson", manual_label, person_class))
        claims, label = entity_claims(op, api, manual)
        assert first_value(claims, instance_of) == person_class, \
            f"{manual} instance-of != person ({first_value(claims, instance_of)})"
        assert label == manual_label, f"{manual} label mismatch ({label!r})"
        assert first_value(claims, wikidata_id_prop) is None, \
            f"{manual} must not carry authority IDs (manual entry)"
        print(f"[ok] AddPerson/manual -> {manual}: created from blank, no import reference")

        # 3c. Special:AddSoftware — search -> select -> review -> create:
        #     item + FOSS:<Name> page + sitelink in one flow (issue #26).
        foss_class = resolve("free and open-source software", "item")
        official_website = resolve("official website", "property")
        software_qid, foss_page = flow_software(op, base, api, args.software)
        software = track(software_qid)
        claims, label = entity_claims(op, api, software)
        assert first_value(claims, instance_of) == foss_class, \
            f"{software} instance-of != free and open-source software ({first_value(claims, instance_of)})"
        assert first_value(claims, wikidata_id_prop), f"{software} missing Wikidata ID"
        # The page carries the {{FOSS}} skeleton (raw content check).
        _, raw = page_get(op, base, "/wiki/" + urllib.parse.quote(foss_page.replace(" ", "_")) + "?action=raw")
        assert "{{FOSS}}" in raw, f"{foss_page} does not transclude {{FOSS}}"
        # The item is sitelinked to the FOSS page (site id 'wikibase';
        # sitelink page names are stored with spaces, per the repo store).
        r = api_call(op, api, {"action": "wbgetentities", "ids": software,
                               "props": "sitelinks", "format": "json"})
        sitelinks = r.get("entities", {}).get(software, {}).get("sitelinks", {})
        if not any(sl.get("site") == "wikibase" and sl.get("title") == foss_page
                   for sl in sitelinks.values()):
            raise FlowError(f"{software} missing wikibase sitelink to {foss_page}; "
                            f"actual sitelinks: {json.dumps(sitelinks)}")
        # Volatile facts come from the harvest — present for Flameshot, but
        # only a warning (not a failure) when the authority lacks them.
        website = first_value(claims, official_website)
        print(f"[ok] AddSoftware -> {software} ({label}): FOSS class + Wikidata ID, "
              f"page {foss_page}, sitelinked" + (f", website={website}" if website else ", no harvested website"))
        if software in created:  # only delete pages this run actually created
            created_pages.append(foss_page)

        # 3d. AddSoftware/manual + multi-value entity fields (follow-up):
        #     the manual path always creates a FRESH item (create-or-skip
        #     cannot reuse a timestamped label), so it deterministically
        #     exercises multi-value: two item ids in the 'has use' field
        #     must produce one statement PER id on the created item.
        has_use = resolve("has use", "property")
        software_manual_label = f"Page-flow E2E software {int(time.time())}"
        software_manual, foss_manual_page = flow_software_manual(
            op, base, api, software_manual_label, foss_class,
            extra_fields={"wphasUse": f"{foss_class}, {person}"})
        software_manual = track(software_manual)
        claims, _ = entity_claims(op, api, software_manual)
        has_use_statements = claims.get(has_use, [])
        assert len(has_use_statements) == 2, \
            f"{software_manual} expected 2 has-use statements for two item ids, " \
            f"got {len(has_use_statements)}"
        print(f"[ok] AddSoftware/manual -> {software_manual}: multi-value has-use "
              f"({len(has_use_statements)} statements)")
        if software_manual in created:
            created_pages.append(foss_manual_page)

        # 3e. AddSoftware/manual + logo upload (issue follow-up): a local PNG
        #     is uploaded as File:<label>-logo.png, linked from the item via
        #     the image statement, and passed to the FOSS page infobox.
        image_prop = resolve("image", "property")
        logo_label = f"Page-flow E2E logo software {int(time.time())}"
        logo_qid, logo_page = flow_software_logo(op, base, api, logo_label, foss_class)
        logo_qid = track(logo_qid)
        # Upload first (the File: page) — an upload failure aborts the flow
        # with the server-side reason; only then check the statement wiring.
        file_title = f"File:{logo_label}-logo.png"
        r = api_call(op, api, {"action": "query", "titles": file_title, "format": "json"})
        assert any("missing" not in p for p in r["query"]["pages"].values()), \
            f"{file_title} was not uploaded"
        claims, _ = entity_claims(op, api, logo_qid)
        image_url = first_value(claims, image_prop)
        assert image_url and "logo.png" in str(image_url), \
            f"{logo_qid} missing image statement pointing at the logo ({image_url!r})"
        _, raw = page_get(op, base, "/wiki/" + urllib.parse.quote(logo_page.replace(" ", "_")) + "?action=raw")
        # File DB keys normalize spaces to underscores — match the stored form.
        assert f"logo=[[File:{logo_label.replace(' ', '_')}-logo.png" in raw, \
            f"{logo_page} skeleton does not pass the logo to the infobox; raw: {raw[:200]!r}"
        print(f"[ok] AddSoftware/manual (logo) -> {logo_qid}: File:{logo_label}-logo.png uploaded, "
              f"image statement + infobox logo on {logo_page}")
        if logo_qid in created:
            created_pages.append(logo_page)

        # 3f. Sitelink tab (issue follow-up): red (needs-set) on a page
        #     without a sitelink, blue (is-set) on the sitelinked FOSS page.
        flow_sitelink_tab(op, base, api, foss_page, software)

        # 4. v1 content form — Special:AddQuotation with provenance.
        # Unique label per run: create-or-skip would otherwise reuse a stale
        # quotation from an earlier (failed) run and fail the assertions.
        quote_label = f"Page-flow E2E quotation {args.person} {int(time.time())}"
        quotation = track(flow_quotation(op, base, api, quote_label, "An E2E test quotation.", person))
        claims, label = entity_claims(op, api, quotation)
        assert first_value(claims, instance_of) == quotation_class, \
            f"{quotation} instance-of != quotation content"
        assert first_value(claims, resolve("attributed to", "property")) == person, \
            f"{quotation} attributed-to mismatch"
        assert first_value(claims, resolve("content text", "property")) is not None, \
            f"{quotation} missing content payload"
        print(f"[ok] Special:AddQuotation -> {quotation}: quotation class + payload + attribution")

        # 5. Special:AddMath with the 'describes' subject field (issue
        #    follow-up) + delimiter stripping: a $$…$$-wrapped payload must be
        #    stored as bare TeX (the stored content is what KaTeX renders).
        math_label = f"Page-flow E2E math {int(time.time())}"
        math_item = track(flow_math(op, base, api, math_label, "$$E = mc^2$$", person))
        claims, label = entity_claims(op, api, math_item)
        assert first_value(claims, instance_of) == math_class, \
            f"{math_item} instance-of != mathematical expression"
        assert first_value(claims, describes_prop) == person, \
            f"{math_item} describes mismatch (wanted {person})"
        latex_prop = resolve("LaTeX source", "property")
        assert first_value(claims, latex_prop) == "E = mc^2", \
            f"{math_item} math payload not delimiter-stripped ({first_value(claims, latex_prop)!r})"
        print(f"[ok] Special:AddMath -> {math_item}: math class + describes statement, "
              f"payload delimiter-stripped")

        # 6. Cite-by-QID (issue #24 v1 + #25 v2): {{#cite}} inside <ref>,
        #    {{#citations:}} accumulated + explicit, embed auto-collect.
        #    The dogfood book must be a source-class item with harvested
        #    metadata (seed enrichment) for the self-cite assertions; the
        #    AddSource-created article feeds the embed auto-collect.
        book_class = resolve("book", "item")
        book = resolve("Notes by the Translator", "item")
        claims, label = entity_claims(op, api, book)
        assert first_value(claims, instance_of) == book_class, \
            f"{book} instance-of != book ({first_value(claims, instance_of)}) — dogfood book not source-class"
        quote_dogfood = resolve("The Analytical Engine has no pretensions whatever to originate anything", "item")
        flow_cite_by_qid(op, base, api, book, quote_dogfood, source)
    finally:
        if not args.keep:
            for page in created_pages:
                try:
                    csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
                    token = csrf["query"]["tokens"]["csrftoken"]
                    api_call(op, api, {"action": "delete", "title": page, "token": token,
                                       "reason": "page-flow E2E cleanup (run_pages_e2e.py)", "format": "json"},
                             post=True)
                except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                    print(f"  ! cleanup failed for {page}: {exc}")
            for qid in created:
                try:
                    delete_item(op, api, qid)
                except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                    print(f"  ! cleanup failed for {qid}: {exc}")

    print("\nPage-flow E2E: all checks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
