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
        # Standard redirect semantics (RFC 7231 §6.4): 301/302/303 convert a
        # POST to a GET and drop the body — a real browser does this, and
        # nginx answers 405 Method Not Allowed for a POST to a file URL
        # (Special:SourceFile's checked-download redirect to /images/…; the
        # CI stack's Apache tolerates the bodyless re-POST, masking the bug).
        # 307/308 preserve method AND body.
        if code in (301, 302, 303) and req.get_method() == "POST":
            stripped = {k: v for k, v in req.headers.items()
                        if k.lower() not in ("content-length", "content-type")}
            return urllib.request.Request(newurl, headers=stripped, method="GET")
        return urllib.request.Request(newurl, data=req.data, headers=req.headers,
                                      method=req.get_method())


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


def entity_descriptions(op, api: str, qid: str) -> str:
    """en description TERM of an item ('' when absent). wbgetentities
    returns terms as {lang: {language, value}} — dig out the value, same
    shape as the entity_claims label helper."""
    r = api_call(op, api, {"action": "wbgetentities", "ids": qid,
                           "props": "descriptions", "format": "json"})
    return r.get("entities", {}).get(qid, {}).get("descriptions", {}) \
        .get("en", {}).get("value", "")


def page_wikitext(op, api: str, page_title: str) -> str:
    """Current wikitext of a page ('' when missing). Reads via
    action=parse prop=wikitext — on a freshly created page the parse can
    lag the afterCreate redirect by a beat, so retry like flow_final_item."""
    for _ in range(15):
        r = api_call(op, api, {"action": "parse", "page": page_title,
                               "prop": "wikitext", "format": "json"})
        text = r.get("parse", {}).get("wikitext", {}).get("*", "")
        if text:
            return text
        time.sleep(2)
    return ""


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


# Classic pages (Source:/Person:/Collective:) created by the Add* flows.
# The cleanup deletes the ITEM pages (entities) but never the classic pages
# (pre-existing leak: a re-run's create-or-skip + afterCreate re-points the
# sitelink, but the stale wikibase_item page property still maps the page to
# the DELETED item, so flow_final_item resolves the wrong id and the
# instance-of assertion fails). flow_final_item records the page title here
# so the cleanup removes it too — the next run re-creates a fresh page with
# a fresh mapping.
CREATED_CLASSIC_PAGES: list[str] = []


def flow_final_item(op, base: str, api: str, url: str, body: str, special: str) -> str:
    """Resolves the created item id after a flow's final submit. Item-only
    kinds redirect to Item:<id>; page-creating kinds (Person:/Source:/
    Collective:, issue follow-up) redirect through the complete/<id> finalize
    round-trip to the classic page, whose wikibase_item page property maps it
    back to the item."""
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if m:
        return m.group(1)
    m = re.search(r"/wiki/((?:Person|Source|Collective):[^?#]+)$", url)
    if m:
        page_title = urllib.parse.unquote(m.group(1)).replace("_", " ")
        # Record the classic page for the cleanup (the item deletion alone
        # leaves the page mapped to a deleted item — the re-run hazard).
        if page_title not in CREATED_CLASSIC_PAGES:
            CREATED_CLASSIC_PAGES.append(page_title)
        # The wikibase_item page property is written at parse time but its
        # page_props table row lands via the deferred LinkUpdate. On the dev
        # stack the jobrunner processes it within seconds; PRODUCTION has
        # $wgJobRunRate = 0 + a 5-min cron draining runJobs.php — the
        # property fills up to one cron cycle after creation ("expected, not
        # a regression", repo AGENTS.md). Retry for up to ~6 min (24 × 15s)
        # so the suite is green on both.
        qid = None
        for _ in range(24):
            r = api_call(op, api, {"action": "query", "titles": page_title,
                                   "prop": "pageprops", "format": "json"})
            for page in r.get("query", {}).get("pages", {}).values():
                qid = page.get("pageprops", {}).get("wikibase_item")
                if qid:
                    return qid
            time.sleep(15)
        raise FlowError(f"{special} page {page_title} has no wikibase_item "
                        f"(finalize step did not map the sitelink)")
    raise FlowError(f"{special} did not redirect to an item or classic page: {url} {find_error(body)}")


# ------------------------------------------------------------- page flows


def flow_search_select_create(op, base: str, api: str, special: str, search_fields: dict,
                              pick_index: int = 0, review_prefill: bool = False) -> str:
    """Runs the three-step Special page flow (search -> select -> review ->
    create, issue #12); returns the created (or reused) item id.

    With review_prefill=True (AddPerson): the REVIEW form must show the
    harvested record's Given name / Family name (authority autofill, the
    issue follow-up) — the regression that the harvest-on-pick values reach
    the form at all."""
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

    # Selection step: detailed candidate table + radio (no class field —
    # the class is chosen on the REVIEW step, where the harvested record
    # pre-selects the inferred class).
    candidates = ooui_options(body, "mw-input-wpcandidates")
    if not candidates:
        raise FlowError(f"Special:{special} selection page rendered no candidates")
    index = str(min(pick_index, len(candidates) - 1))
    token2 = edit_token(body)

    url, body = page_post(op, url, {
        "wpcandidates": index,
        "wpEditToken": token2,
        "wpSubmit": "1",
    })
    m = re.search(rf"/wiki/Special:{special}/{token}/review/{index}$", url)
    if not m:
        raise FlowError(
            f"Special:{special} select did not redirect to the review step: {url} {find_error(body)}")

    # Review step: pre-filled editable form; submit without changes (issue #12).
    if review_prefill:
        given = input_value(body, "wpgivenName")
        family = input_value(body, "wpfamilyName")
        if not given and not family:
            raise FlowError(
                f"Special:{special} review form NOT prefilled from the harvested "
                f"authority record (given={given!r}, family={family!r})")
        # Place of birth (label-match flow, follow-up): the harvested
        # WIKIDATA QID must never reach the local combobox. A non-empty
        # default must be (a) an EXISTING local item — not a bare Wikidata
        # id written blindly — and (b) the result of the label-match flow,
        # which renders the [Yes]/[No] confirmation banner.
        pob = input_value(body, "wpplaceOfBirth")
        if pob:
            r = api_call(op, api, {"action": "wbgetentities", "ids": pob, "format": "json"})
            ent = r.get("entities", {}).get(pob, {})
            if not ent or "missing" in ent:
                raise FlowError(
                    f"Special:{special} place-of-birth default is not a LOCAL item: {pob!r} — "
                    f"the harvested Wikidata QID must be resolved to a label and matched "
                    f"against local items, never written blindly")
            assert "wb-entity-confirm" in body, \
                f"Special:{special} place-of-birth local match rendered without the confirmation banner"
    token3 = edit_token(body)
    url, body = page_post(op, url, {
        "wpEditToken": token3,
        "wpSubmit": "1",
    })
    return flow_final_item(op, base, api, url, body, f"Special:{special}")


def flow_manual(op, base: str, api: str, special: str, label: str, class_item: str,
                extra_fields: dict | None = None) -> str:
    """Manual-entry fallback (issue #12): Special:<name>/manual creates from
    blank (no external record). extra_fields carries additional review-form
    fields (e.g. the person birth/death facts), keyed as wp<FieldName>.

    AddPerson (issue #35) has NO label field — the label is the full name
    derived from given/family. The flow splits the label mechanically
    (last word = family, the NameSplitter convention) so the derived label
    equals the input."""
    url, body = page_get(op, base, f"/wiki/Special:{special}/manual")
    token = edit_token(body)
    fields = {"wpclass": class_item, "wpEditToken": token, "wpSubmit": "1"}
    if special == "AddPerson":
        given, _, family = label.rpartition(" ")
        # HTMLForm keeps the field-name case: 'givenName' -> 'wpgivenName'.
        fields["wpgivenName"] = given
        fields["wpfamilyName"] = family
    else:
        fields["wplabel"] = label
    if extra_fields:
        fields.update(extra_fields)
    url, body = page_post(op, url, fields)
    return flow_final_item(op, base, api, url, body, f"Special:{special}/manual")


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

    # Selection step: detailed candidate table + radio (no class field —
    # the class is chosen on the REVIEW step, where the harvested record
    # pre-selects the inferred class).
    candidates = ooui_options(body, "mw-input-wpcandidates")
    if not candidates:
        raise FlowError("AddSoftware selection page rendered no candidates")
    token2 = edit_token(body)
    url, body = page_post(op, url, {
        "wpcandidates": "0",
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


def flow_software_logo(op, base: str, api: str, label: str, class_item: str,
                       license_qid: str) -> tuple[str, str]:
    """Special:AddSoftware/manual with a LOCAL LOGO upload (issue follow-up):
    posts a 1x1 PNG via multipart (behind the logoInclude toggle, with the
    now-mandatory license), then verifies the created item carries the
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
        # The logo section is opt-in behind the toggle (upload enhancements);
        # the license is mandatory when a logo is provided.
        "wplogoInclude": "1",
        "wplogoMode": "file",
        "wplogoLicense": license_qid,
        "wplogoAuthor": "E2E Logo Author",
        "wplogoLicenseInfo": "E2E license note",
        "wpEditToken": token,
        "wpSubmit": "1",
    }, {
        # The OOUI form renders wp + field key as-is: "logoFile" -> "wplogoFile".
        "wplogoFile": ("logo.png", png, "image/png"),
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
    # review_prefill=True (issue follow-up): the review form must show the
    # harvested authority record's given/family names — the end-to-end proof
    # of the "Given name / Family name auto-fill from external authority
    # records" behaviour on Special:AddPerson.
    return flow_search_select_create(op, base, api, "AddPerson", {"wpname": name},
                                     review_prefill=True)


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
    index = str(min(pick_index, len(candidates) - 1))
    token2 = edit_token(body)
    url, body = page_post(op, url, {
        "wpcandidates": index,
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
    # Issue follow-up: when the harvest fetched page content (abstract/
    # keywords/intro/plot/…), the review submit routes to the CONTENT step —
    # submit it too (the contributor confirms the fetched prose).
    m = re.search(rf"/wiki/Special:AddSource/{class_key}/{token}/review/{index}/content$", url)
    if m:
        token4 = edit_token(body)
        url, body = page_post(op, url, {"wpEditToken": token4, "wpSubmit": "1"})
    return flow_final_item(op, base, api, url, body, f"AddSource/{class_key}")


def flow_source_class_manual(op, base: str, api: str, class_key: str, fields: dict) -> str:
    """Class-first manual flow: Special:AddSource/<classKey>/manual creates
    from blank with the class-scoped fields (the class is a hidden field)."""
    url, body = page_get(op, base, f"/wiki/Special:AddSource/{class_key}/manual")
    token = edit_token(body)
    post = {"wpEditToken": token, "wpSubmit": "1"}
    post.update(fields)
    url, body = page_post(op, url, post)
    return flow_final_item(op, base, api, url, body, f"AddSource/{class_key}/manual")


def input_value(body: str, field: str) -> str:
    """value attribute of an <input name="wp<FieldName>">. OOUI php-mode
    inputs render with SINGLE-quoted attributes ('name=... value=...') while
    plain hidden inputs use double quotes — match either quote style and
    either attribute order."""
    for quote in ('"', "'"):
        for pattern in (
            r"name=" + quote + field + quote + r"[^>]*\bvalue=" + quote + r"([^" + quote + r"]*)" + quote,
            r"\bvalue=" + quote + r"([^" + quote + r"]*)" + quote + r"[^>]*name=" + quote + field + quote,
        ):
            m = re.search(pattern, body)
            if m:
                return m.group(1)
    return ""


def flow_manual_link_from(op, base: str, body: str, special: str) -> str:
    """The tokenised 'create manually' href in a search-result page (zero-hit
    AND selection pages both carry it since issue #35).

    Title::getFullURL() canonicalises SPECIAL pages to
    /w/index.php?title=Special:.../manual&token=<hex> (not the /wiki/ article
    path) — parse the href generically and return the path+query for page_get.
    """
    m = re.search(r'href="([^"]*Special:' + re.escape(special) + r'/manual[^"]*token=[0-9a-f]+[^"]*)"', body)
    if not m:
        raise FlowError(f"{special} search page offers no tokenised manual link: {find_error(body)}")
    href = m.group(1).replace("&amp;", "&")
    u = urllib.parse.urlparse(href)
    return (u.path or "/") + ("?" + u.query if u.query else "")


def flow_person_manual_autofill(op, base: str, api: str, name: str) -> tuple[str, str]:
    """Search autofill (issue #35): Special:AddPerson name search -> the page
    offers 'create manually' with a token; the manual form is prefilled
    (given = every word except the last, family = last word, per
    NameSplitter). Submits the prefilled form; returns (qid, derived label)."""
    url, body = page_get(op, base, "/wiki/Special:AddPerson")
    token = edit_token(body)
    url, body = page_post(op, url, {"wpname": name, "wpEditToken": token, "wpSubmit": "1"})
    manual_path = flow_manual_link_from(op, base, body, "AddPerson")
    url2, body2 = page_get(op, base, manual_path)
    expected_given, _, expected_family = name.rpartition(" ")
    given = input_value(body2, "wpgivenName")
    family = input_value(body2, "wpfamilyName")
    if given != expected_given or family != expected_family:
        raise FlowError(
            f"AddPerson manual form not autofilled from the name search: "
            f"given {given!r}/{family!r} != {expected_given!r}/{expected_family!r}")
    token2 = edit_token(body2)
    url3, body3 = page_post(op, url2, {
        "wpgivenName": given, "wpfamilyName": family,
        # A description with no fetched biography: the Person: page must
        # render it as the == Overview == placeholder (the description-
        # as-placeholder contract, see SpecialAddPerson::pageSkeleton).
        "wpdescription": "Person-flow E2E placeholder description",
        "wpEditToken": token2, "wpSubmit": "1",
    })
    qid = flow_final_item(op, base, api, url3, body3, "AddPerson/manual (autofill)")
    return qid, name


def flow_source_book_manual_autofill(op, base: str, api: str, title: str,
                                     author_qid: str) -> str:
    """Search autofill (issue #35): AddSource/book title+entity-author search
    -> tokenised manual link -> the manual form is prefilled (title and
    authors carried). Submits the prefilled form; returns the created qid."""
    url, body = page_get(op, base, "/wiki/Special:AddSource/book")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wptitle": title, "wpauthor": author_qid, "wpauthorMode": "entity",
        "wpEditToken": token, "wpSubmit": "1",
    })
    manual_path = flow_manual_link_from(op, base, body, "AddSource/book")
    url2, body2 = page_get(op, base, manual_path)
    # The manual title default carries the AddSource class-disambiguation
    # suffix (" (Book)" — the label convention): the autofilled search title
    # plus the class label.
    if input_value(body2, "wptitle") != title + " (Book)":
        raise FlowError(f"AddSource/book manual title not autofilled (with the (Book) "
                        f"suffix) from the search: {find_error(body2)}")
    if input_value(body2, "wpauthors") != author_qid:
        raise FlowError(f"AddSource/book manual authors not autofilled (entity mode): {find_error(body2)}")
    token2 = edit_token(body2)
    url3, body3 = page_post(op, url2, {
        "wptitle": title, "wpauthors": author_qid,
        "wpEditToken": token2, "wpSubmit": "1",
    })
    return flow_final_item(op, base, api, url3, body3, "AddSource/book/manual (autofill)")


def flow_source_manual_author_confirm(op, base: str, api: str, title: str,
                                      author_name: str) -> tuple[str, str]:
    """Autofill-confirm flow: AddSource/book FREE-TEXT author search — the
    typed name (wpauthorMode=text) fuzzy-matches an existing person item; the
    manual form prefills wpauthors with the matched Q-id AND renders the
    .wb-entity-confirm banner ("we think this corresponds to …"). Returns
    (created qid, matched author id)."""
    matched = resolve_label(op, api, author_name, "item")
    if not matched:
        raise FlowError(f"author {author_name!r} is not an existing item — the banner flow needs one")
    url, body = page_get(op, base, "/wiki/Special:AddSource/book")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wptitle": title, "wpauthor": author_name, "wpauthorMode": "text",
        "wpEditToken": token, "wpSubmit": "1",
    })
    manual_path = flow_manual_link_from(op, base, body, "AddSource/book")
    url2, body2 = page_get(op, base, manual_path)
    if input_value(body2, "wpauthors") != matched:
        raise FlowError(
            f"free-text author {author_name!r} not fuzzy-matched to {matched}: "
            f"got {input_value(body2, 'wpauthors')!r} {find_error(body2)}")
    if "wb-entity-confirm" not in body2:
        raise FlowError(f"manual form missing the entity-confirm banner for the author: {find_error(body2)}")
    token2 = edit_token(body2)
    url3, body3 = page_post(op, url2, {
        "wptitle": title, "wpauthors": matched,
        "wpEditToken": token2, "wpSubmit": "1",
    })
    qid = flow_final_item(op, base, api, url3, body3, "AddSource/book/manual (author confirm)")
    return qid, matched


def flow_update_button(op, base: str, qid: str, update_special: str) -> None:
    """Update-flow button (autofill-confirm-update): the Item page carries
    the wbUpdateBasicInfoUrl config pointing at the Special:Update* page
    (server-side class detection; updatebutton.js renders the button under
    the title)."""
    url, body = page_get(op, base, f"/wiki/Item:{qid}")
    m = re.search(r'"wbUpdateBasicInfoUrl"\s*:\s*"([^"]*Special:' + re.escape(update_special)
                  + r"/" + re.escape(qid) + r')"', body)
    if not m:
        raise FlowError(
            f"Item:{qid} page carries no update-button URL for {update_special}: "
            f"{find_error(body)}")
    print(f"[ok] Item:{qid} -> update button -> {m.group(1)}")


def flow_update_person(op, base: str, api: str, qid: str, new_description: str) -> str:
    """Update flow: Special:UpdatePerson/<qid> renders the AddPerson review
    fields prefilled from the item's statements; a changed description
    UPDATES the item (the other statements are preserved). Returns the final
    URL. The POST mirrors a browser: every visible form field is submitted
    (hide-if-hidden fields are not), so untouched statements survive."""
    url, body = page_get(op, base, f"/wiki/Special:UpdatePerson/{qid}")
    if "Update a person" not in body:
        raise FlowError(f"Special:UpdatePerson/{qid} did not render: {find_error(body)}")
    given = input_value(body, "wpgivenName")
    family = input_value(body, "wpfamilyName")
    if not given or not family:
        raise FlowError(f"UpdatePerson/{qid} did not prefill given/family: {find_error(body)}")
    class_item = input_value(body, "wpclass")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wpgivenName": given, "wpfamilyName": family,
        "wpdescription": new_description,
        "wpdateOfBirth": input_value(body, "wpdateOfBirth"),
        "wpplaceOfBirth": input_value(body, "wpplaceOfBirth"),
        "wpdeceased": "1",  # the item under test has death facts — toggle open
        "wpdateOfDeath": input_value(body, "wpdateOfDeath"),
        "wpplaceOfDeath": input_value(body, "wpplaceOfDeath"),
        "wpclass": class_item,
        "wpEditToken": token, "wpSubmit": "1",
    })
    if qid not in url:
        raise FlowError(f"UpdatePerson/{qid} did not redirect to the item: {url} {find_error(body)}")
    return url


def flow_update_person_no_clobber(op, base: str, api: str, qid: str) -> str:
    """Update no-clobber (upload-ux batch): Special:UpdatePerson/<qid> with
    a BLANKED description and a blanked place-of-death field must KEEP the
    existing values — 'basic information' never overwrites a field the user
    left empty (removal is an explicit item-page edit). Also asserts the
    update page renders the '(replacing existing)' portrait toggle wording.
    Returns the final URL."""
    url, body = page_get(op, base, f"/wiki/Special:UpdatePerson/{qid}")
    if "Update a person" not in body:
        raise FlowError(f"Special:UpdatePerson/{qid} did not render: {find_error(body)}")
    if "replacing existing" not in body:
        raise FlowError(f"UpdatePerson/{qid} include toggle lacks the "
                        f"'(replacing existing)' wording")
    given = input_value(body, "wpgivenName")
    family = input_value(body, "wpfamilyName")
    class_item = input_value(body, "wpclass")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wpgivenName": given, "wpfamilyName": family,
        # Blanked managed fields: the description and the place of death —
        # both must survive the update (no-clobber).
        "wpdescription": "",
        "wpdateOfBirth": input_value(body, "wpdateOfBirth"),
        "wpplaceOfBirth": input_value(body, "wpplaceOfBirth"),
        "wpdeceased": "1",
        "wpdateOfDeath": input_value(body, "wpdateOfDeath"),
        "wpplaceOfDeath": "",
        "wpclass": class_item,
        "wpEditToken": token, "wpSubmit": "1",
    })
    if qid not in url:
        raise FlowError(f"UpdatePerson/{qid} no-clobber did not redirect: {url} {find_error(body)}")
    return url


def flow_update_source(op, base: str, api: str, qid: str, new_description: str) -> str:
    """Update flow: Special:UpdateSource/<qid> renders the AddSource review
    fields prefilled from the item's statements; a changed description
    UPDATES the item. Returns the final URL."""
    url, body = page_get(op, base, f"/wiki/Special:UpdateSource/{qid}")
    if "Update a source" not in body:
        raise FlowError(f"Special:UpdateSource/{qid} did not render: {find_error(body)}")
    title = input_value(body, "wptitle")
    authors = input_value(body, "wpauthors")
    class_item = input_value(body, "wpclass")
    if not title or not authors:
        raise FlowError(f"UpdateSource/{qid} did not prefill title/authors: {find_error(body)}")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wptitle": title, "wpauthors": authors,
        "wpdescription": new_description,
        "wppublisher": input_value(body, "wppublisher"),
        "wppages": input_value(body, "wppages"),
        "wpissuedYear": input_value(body, "wpissuedYear"),
        "wpaccessMode": input_value(body, "wpaccessMode") or "na",
        "wpclass": class_item,
        "wpEditToken": token, "wpSubmit": "1",
    })
    if qid not in url:
        raise FlowError(f"UpdateSource/{qid} did not redirect to the item: {url} {find_error(body)}")
    return url


def flow_source_picker_route(op, base: str) -> str:
    """Class picker (issue follow-up): the manual-entry checkbox is GONE (the
    user decides on the next page); picking a class routes to its class-scoped
    first step (book → the /book search page)."""
    url, body = page_get(op, base, "/wiki/Special:AddSource")
    if "wpmanual" in body:
        raise FlowError("class picker still renders the removed manual checkbox")
    token = edit_token(body)
    url, body = page_post(op, url, {"wpclass": "book", "wpEditToken": token, "wpSubmit": "1"})
    if "/wiki/Special:AddSource/book" not in url:
        raise FlowError(f"picker did not route to /book: {url} {find_error(body)}")
    return url


def flow_source_url_entry(op, base: str, api: str, class_key: str, url: str,
                          author_qid: str, post_extra: dict | None = None) -> str:
    """URL-first flow for the manual-only website/webpage classes (issue
    follow-up): the first page is a URL entry (Special:AddSource/<classKey>);
    the metadata of the entered URL is fetched (SSRF-guarded) and prefills the
    manual form (/manual?token=). example.org serves a <title>Example
    Domain</title>, so the autofill must be visible before creation.

    post_extra carries additional manual-form fields (e.g. the inferred
    parent on the webpage flow), keyed as wp<FieldName>.
    """
    url_page, body = page_get(op, base, f"/wiki/Special:AddSource/{class_key}")
    if "mw-input-wpurl" not in body:
        raise FlowError(f"AddSource/{class_key} first page is not the URL entry: {find_error(body)}")
    token = edit_token(body)
    url_page, body = page_post(op, url_page, {
        "wpurl": url, "wpEditToken": token, "wpSubmit": "1",
    })
    m = re.search(r"(Special:AddSource/" + class_key + r"/manual)[^'\"]*token=([0-9a-f]+)", url_page)
    if not m:
        raise FlowError(
            f"AddSource/{class_key} URL entry did not redirect to /manual?token=: {url_page} {find_error(body)}")
    token = m.group(2)
    # Title::getFullURL() canonicalises SPECIAL pages to
    # /w/index.php?title=Special:.../manual&token=<hex> — reuse the redirect
    # target's path+query for the manual page GET.
    u = urllib.parse.urlparse(url_page)
    manual_path = (u.path or "/") + ("?" + u.query if u.query else "")
    manual_url, body = page_get(op, base, manual_path)
    # The fetched <title> prefills the manual title field (best-effort fetch:
    # example.org always answers). OOUI php renders single-quoted attributes.
    if "value='Example Domain'" not in body and "value='Example" not in body:
        raise FlowError(f"AddSource/{class_key} manual form not prefilled from the fetched "
                        f"metadata: {find_error(body)}")
    m = re.search(r"name='wptitle'[^>]*value='([^']*)'", body)
    prefilled_title = m.group(1) if m else ""
    token2 = edit_token(body)
    # wptitle is prefilled (a browser submits it); the author is still
    # required (agent-class). Append a run-unique marker to the fetched
    # title: example.org's <title> is FIXED ("Example Domain"), so without
    # it every run creates the same label and the next run's create-or-skip
    # REUSES the self-cleaned (deleted) item — a stale term-store hit that
    # fails the instance-of assertion on re-runs (seen on production). The
    # marker goes BEFORE the class disambiguation suffix (" (Website)" /
    # " (Webpage)" — the AddSource label convention), so the creation-time
    # suffix append stays idempotent: "<title> (E2E <ts>) (Website)".
    fields = {"wptitle": _unique_title(prefilled_title, int(time.time())),
              "wpauthors": author_qid, "wpEditToken": token2, "wpSubmit": "1"}
    if post_extra:
        fields.update(post_extra)
    url_page, body = page_post(op, manual_url, fields)
    # The fetched intro (site description) is reviewed on the content step
    # (/manual/content?token= — the redirect renders as
    # /w/index.php?title=Special:.../manual/content&token=) — submit it too.
    if re.search(r"Special:AddSource/" + class_key + r"/manual/content[^'\"]*token=", url_page):
        token3 = edit_token(body)
        url_page, body = page_post(op, url_page, {"wpEditToken": token3, "wpSubmit": "1"})
        # Debug aid: surface the content-step response when the creation did
        # not complete (the form re-renders without a redirect).
        if not re.search(r"/wiki/(?:Item:Q\d+|Source:[^?#]+)$", url_page):
            text = re.sub(r"<script.*?</script>", "", body, flags=re.S)
            text = re.sub(r"<[^>]+>", " ", text)
            text = re.sub(r"\s+", " ", text)
            i = text.lower().find("expired")
            snippet = text[max(0, i - 200):i + 200] if i >= 0 else text[-600:]
            raise FlowError(f"content-step submit did not complete: {url_page}\n{snippet}")
    return flow_final_item(op, base, api, url_page, body, f"AddSource/{class_key} (URL entry)")


def _unique_title(prefilled_title: str, ts: int) -> str:
    """Run-unique manual title for the URL-first flows: inserts " (E2E <ts>)"
    BEFORE a trailing parenthetical — the AddSource class disambiguation
    suffix (" (Website)", " (Webpage)") must stay at the very end so the
    creation-time suffix append is idempotent."""
    m = re.search(r" \(([^)]*)\)$", prefilled_title)
    if m:
        return prefilled_title[:m.start()] + f" (E2E {ts})" + m.group(0)
    return prefilled_title + f" (E2E {ts})"


def website_item_matching(op, api: str, site_name: str) -> str | None:
    """A website-class item whose label matches the site name, or None.
    Mirrors the server-side parent inference's search (wbsearchentities +
    an instance-of website filter) — used to decide which outcome the
    webpage parent inference must have produced (production may already
    have a real record for a well-known site, e.g. 'Example Domain')."""
    website_class = resolve_label(op, api, "website", "item")
    instance_of = resolve_label(op, api, "instance of", "property")
    if website_class is None or instance_of is None:
        raise FlowError("website class / instance-of property not resolvable")
    r = api_call(op, api, {"action": "wbsearchentities", "search": site_name,
                           "language": "en", "type": "item", "limit": 20, "format": "json"})
    for hit in r.get("search", []):
        qid = hit["id"]
        claims, _ = entity_claims(op, api, qid)
        if first_value(claims, instance_of) == website_class:
            return qid
    return None


def normalize_host(url: str) -> str:
    """Mirror of the server's SiteRootMatcher::normalizeHost: lowercase,
    trailing dot stripped, `www.` collapsed. '' for an unparseable URL. A
    scheme-less input (a bare host) is treated as https://host — urlparse
    would otherwise parse it as a path with no hostname."""
    if "://" not in url:
        url = "https://" + url
    try:
        host = (urllib.parse.urlparse(url).hostname or "").lower().rstrip(".")
    except ValueError:
        return ""
    return host[4:] if host.startswith("www.") else host


def website_item_by_root_host(op, api: str, host: str) -> str | None:
    """API-based host scan: a website-class item whose URL statement's
    normalized host equals the given host, or None. Mirrors the SERVER's
    host-match intent (root URL host against the URL statements of
    website-class items) but reads the TERM STORE + claims directly (no
    WDQS — deterministic; the server reads WDQS, which is eventually
    consistent, so the two agree for pre-existing records)."""
    website_class = resolve_label(op, api, "website", "item")
    instance_of = resolve_label(op, api, "instance of", "property")
    url_prop = resolve_label(op, api, "URL", "property")
    target = normalize_host(host)
    if website_class is None or instance_of is None or url_prop is None or not target:
        return None
    r = api_call(op, api, {"action": "wbsearchentities", "search": "Example Domain",
                           "language": "en", "type": "item", "limit": 20, "format": "json"})
    for hit in r.get("search", []):
        qid = hit["id"]
        claims, _ = entity_claims(op, api, qid)
        if first_value(claims, instance_of) != website_class:
            continue
        url_value = first_value(claims, url_prop)
        if url_value and normalize_host(str(url_value)) == target:
            return qid
    return None


def flow_source_webpage_parent_hint(op, base: str, api: str) -> None:
    """Webpage parent inference — asserted against the LIVE state, three
    branches (in server priority order):
      1. a website item whose URL host matches the root (WDQS host match) →
         the parent is AUTO-ASSIGNED silently — prefilled, NO confirmation
         banner (the host-match auto-assign, add-flow round-3 follow-up);
      2. else a website record matching the site NAME → the site-name
         fallback prefills with the confirmation banner;
      3. else → the "No record found for <root>" hint (the website is real,
         our record isn't). Never silently nothing."""
    url, body = page_get(op, base, "/wiki/Special:AddSource/webpage")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wpurl": "https://example.org/no-parent",
        "wpEditToken": token, "wpSubmit": "1",
    })
    m = re.search(r"(Special:AddSource/webpage/manual)[^'\"]*token=([0-9a-f]+)", url)
    if not m:
        raise FlowError(
            f"AddSource/webpage URL entry did not redirect to /manual?token=: {url} {find_error(body)}")
    u = urllib.parse.urlparse(url)
    manual_path = (u.path or "/") + ("?" + u.query if u.query else "")
    _, body = page_get(op, base, manual_path)
    parent = input_value(body, "wpparent")
    if website_item_by_root_host(op, api, "example.org") is not None:
        # Host match (e.g. production's Example Domain record) — the parent
        # must be prefilled SILENTLY (no confirmation banner).
        if not parent or "wb-entity-confirm" in body:
            raise FlowError(
                f"AddSource/webpage parent NOT auto-assigned from the root-URL host "
                f"match (parent={parent!r}, banner={'wb-entity-confirm' in body}): "
                f"{find_error(body)}")
        return
    if website_item_matching(op, api, "Example Domain") is not None:
        # A website record exists by NAME but no URL host matches — the
        # site-name fallback prefills with the confirmation banner.
        if not parent or "wb-entity-confirm" not in body:
            raise FlowError(
                f"AddSource/webpage parent NOT inferred via the site-name fallback "
                f"although a website record exists (parent={parent!r}): {find_error(body)}")
        return
    # No website record — the "No record found for" hint must render. The
    # parsed message autolinks the root URL (bare-URL autolink in the
    # message text) — assert the distinctive fragment, not a URL-contiguous
    # string.
    if "No record found for" not in body or "Add the website first" not in body:
        raise FlowError(
            f"AddSource/webpage manual form missing the no-record parent hint "
            f"(title={input_value(body, 'wptitle')!r}, parent={parent!r}): "
            f"{find_error(body)}")


def flow_source_webpage_parent_match(op, base: str, api: str,
                                     author_qid: str) -> tuple[str, str]:
    """Webpage parent inference — the HOST-MATCH branch: a website-class
    record with a URL statement host-matching the entered page's root
    exists on BOTH stacks (production's Q562 'Example Domain'; the dev/CI
    dogfood website, which reaches WDQS via the CI TTL preload — the
    fresh-stack WDQS updater is unreliable), so a webpage under that root
    must AUTO-ASSIGN the website as the parent: the manual form prefills
    wpparent with a website-class Q-id and NO confirmation banner, and the
    created webpage carries the `part of` statement.

    The server's host match reads WDQS (eventually consistent), so the
    flow RETRIES the URL entry until the server's own inference renders
    the silent prefill (bounded black-box wait).

    Returns (webpage qid, matched website qid). No fixture is created —
    the record pre-exists on both stacks."""
    if website_item_by_root_host(op, api, "example.org") is None:
        raise FlowError(
            "no website record with a URL host of example.org — the dogfood/"
            "production fixture is missing (host-match branch cannot run)")

    manual_url = None
    deadline = time.time() + 60
    while True:
        url, body = page_get(op, base, "/wiki/Special:AddSource/webpage")
        token = edit_token(body)
        url, body = page_post(op, url, {
            "wpurl": "https://example.org/e2e-page",
            "wpEditToken": token, "wpSubmit": "1",
        })
        m = re.search(r"(Special:AddSource/webpage/manual)[^'\"]*token=([0-9a-f]+)", url)
        if not m:
            raise FlowError(
                f"AddSource/webpage URL entry did not redirect to /manual?token=: {url} {find_error(body)}")
        u = urllib.parse.urlparse(url)
        manual_path = (u.path or "/") + ("?" + u.query if u.query else "")
        manual_url, body = page_get(op, base, manual_path)
        parent = input_value(body, "wpparent")
        if parent and "wb-entity-confirm" not in body:
            break  # the host-match auto-assign fired
        if "wb-entity-confirm" in body:
            # The site-name fallback fired (WDQS lagging) — retry until the
            # host match wins.
            parent = None
        if time.time() >= deadline:
            raise FlowError(
                f"AddSource/webpage host-match auto-assign did not fire within 60s "
                f"(last parent={parent!r}, banner={'wb-entity-confirm' in body}): "
                f"{find_error(body)}")
        time.sleep(10)
    # The prefilled parent must be a WEBSITE-class item (the server's
    # class-filtered matching contract), not any fuzzy false positive.
    website_class = resolve_label(op, api, "website", "item")
    instance_of = resolve_label(op, api, "instance of", "property")
    if website_class is None or instance_of is None:
        raise FlowError("website class / instance-of property not resolvable")
    claims, _ = entity_claims(op, api, parent)
    if first_value(claims, instance_of) != website_class:
        raise FlowError(f"AddSource/webpage inferred parent {parent} is not a website-class item")
    token2 = edit_token(body)
    # A browser submits every visible field. example.org 404s every non-root
    # path (only the site root answers 200), so the page fetch fails and the
    # title is NOT prefilled — the parent inference runs off the SITE ROOT
    # fetch, which succeeds. Post the title ourselves (with the run-unique
    # marker; the class suffix is appended at creation).
    fields = {
        "wptitle": f"Page-flow E2E webpage {int(time.time())}",
        "wpauthors": author_qid,
        "wpparent": parent,
        "wpEditToken": token2,
        "wpSubmit": "1",
    }
    url, body = page_post(op, manual_url, fields)
    # The fetched intro (site description) is reviewed on the content step.
    if re.search(r"Special:AddSource/webpage/manual/content[^'\"]*token=", url):
        token3 = edit_token(body)
        url, body = page_post(op, url, {"wpEditToken": token3, "wpSubmit": "1"})
    webpage_qid = flow_final_item(op, base, api, url, body,
                                  "AddSource/webpage (parent inference)")
    return webpage_qid, parent


def flow_source_content_step(op, base: str, api: str, doi: str, author_qid: str) -> str:
    """Scholarly-article fetched-content review step (issue follow-up):
    review → /review/<i>/content with the abstract/keywords textareas →
    create. Requires the OpenAlex harvest to have found content (live
    external authority — re-run on timeout per the E2E conventions)."""
    url, body = page_get(op, base, "/wiki/Special:AddSource")
    token = edit_token(body)
    url, body = page_post(op, url, {"wpclass": "scholarlyArticle", "wpEditToken": token, "wpSubmit": "1"})
    token = edit_token(body)
    url, body = page_post(op, url, {"wpdoi": doi, "wpEditToken": token, "wpSubmit": "1"})
    m = re.search(r"/wiki/Special:AddSource/scholarlyArticle/([0-9a-f]+)$", url)
    if not m:
        raise FlowError(f"scholarlyArticle DOI search did not redirect: {url} {find_error(body)}")
    token = m.group(1)

    url, body = page_get(op, base, f"/wiki/Special:AddSource/scholarlyArticle/{token}")
    candidates = ooui_options(body, "mw-input-wpcandidates")
    index = "0"
    token2 = edit_token(body)
    url, body = page_post(op, url, {
        "wpcandidates": index, "wpEditToken": token2, "wpSubmit": "1",
    })
    m = re.search(rf"/wiki/Special:AddSource/scholarlyArticle/{token}/review/{index}$", url)
    if not m:
        raise FlowError(f"scholarlyArticle select did not redirect to review: {url} {find_error(body)}")

    # Review (with the required author), then the content step must follow.
    token3 = edit_token(body)
    url, body = page_post(op, url, {"wpauthors": author_qid, "wpEditToken": token3, "wpSubmit": "1"})
    m = re.search(rf"/wiki/Special:AddSource/scholarlyArticle/{token}/review/{index}/content$", url)
    if not m:
        raise FlowError(f"scholarlyArticle review did not route to the content step: {url} {find_error(body)}")
    if "mw-input-wpabstract" not in body or "mw-input-wpkeywords" not in body:
        raise FlowError("content step missing the abstract/keywords textareas")

    token4 = edit_token(body)
    url, body = page_post(op, url, {"wpEditToken": token4, "wpSubmit": "1"})
    qid = flow_final_item(op, base, api, url, body, "AddSource/scholarlyArticle (content step)")
    # The Source: page must carry the Abstract section (dynamic sections).
    # The revision read can lag the page-property mapping by a beat — retry
    # like flow_final_item does. Capture the FULL prefixed title (the
    # namespace is part of the page name — a bare title resolves to NS 0).
    m = re.search(r"/wiki/(Source:[^?#]+)", url)
    if m:
        page_title = urllib.parse.unquote(m.group(1)).replace("_", " ")
        content = ""
        r2 = {}
        for attempt in range(15):
            r = api_call(op, api, {"action": "query", "titles": page_title, "prop": "revisions",
                                   "rvprop": "content", "format": "json"})
            for p in r.get("query", {}).get("pages", {}).values():
                content = p.get("revisions", [{}])[0].get("*", "")
            if "== Abstract ==" in content:
                break
            # Fallback read path: action=parse returns the wikitext of the
            # current revision regardless of the revisions-module state.
            if content == "":
                r2 = api_call(op, api, {"action": "parse", "page": page_title,
                                        "prop": "wikitext", "format": "json"})
                content = r2.get("parse", {}).get("wikitext", {}).get("*", "")
                if "== Abstract ==" in content:
                    break
            time.sleep(2)
        if "== Abstract ==" not in content:
            raise FlowError(f"{page_title} has no == Abstract == section "
                            f"(url={url!r}, content={content[:120]!r})")
    return qid


def flow_fictional_character(op, base: str, api: str) -> str:
    """Special:AddFictionalCharacter (issue follow-up): Wikidata name search →
    select → review → create. The label auto-generates as "{given} {family}
    (fictional character)"; the class is fixed to the fictional-character
    class (hidden field)."""
    url, body = page_get(op, base, "/wiki/Special:AddFictionalCharacter")
    if "wpEditToken" not in body:
        raise FlowError("Special:AddFictionalCharacter not usable")
    token = edit_token(body)
    url, body = page_post(op, url, {"wpname": "Sherlock Holmes", "wpEditToken": token, "wpSubmit": "1"})
    m = re.search(r"/wiki/Special:AddFictionalCharacter/([0-9a-f]+)$", url)
    if not m:
        raise FlowError(f"fictional-character search did not redirect: {url} {find_error(body)}")
    token = m.group(1)

    url, body = page_get(op, base, f"/wiki/Special:AddFictionalCharacter/{token}")
    candidates = ooui_options(body, "mw-input-wpcandidates")
    index = "0"
    token2 = edit_token(body)
    url, body = page_post(op, url, {
        "wpcandidates": index, "wpEditToken": token2, "wpSubmit": "1",
    })
    m = re.search(rf"/wiki/Special:AddFictionalCharacter/{token}/review/{index}$", url)
    if not m:
        raise FlowError(f"fictional-character select did not redirect to review: {url} {find_error(body)}")

    token3 = edit_token(body)
    url, body = page_post(op, url, {"wpEditToken": token3, "wpSubmit": "1"})
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if not m:
        raise FlowError(f"fictional-character did not create an item: {url} {find_error(body)}")
    return m.group(1)


def flow_source_book_access_file(op, base: str, api: str, label: str,
                                 author_qid: str, license_qid: str,
                                 content: bytes | None = None,
                                 filename: str = "original.png",
                                 ctype: str = "image/png") -> str:
    """Access field, local-file mode (issue #35): AddSource/book/manual with
    accessMode=file uploads a file (multipart), license required; the file
    lands as File:<label>.<ext> (auto-named, original name ignored) and the
    item carries the file + license statements. The default content is a
    1x1 PNG; pass content/filename/ctype for other types (e.g. a PDF)."""
    if content is None:
        content = base64.b64decode(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
        )
    url, body = page_get(op, base, "/wiki/Special:AddSource/book/manual")
    token = edit_token(body)
    url, body = page_post_multipart(op, url, {
        "wptitle": label,
        "wpauthors": author_qid,
        "wpaccessMode": "file",
        "wplicense": license_qid,
        "wpEditToken": token,
        "wpSubmit": "1",
    }, {
        # The OOUI form renders wp + field key as-is: "accessFile" -> "wpaccessFile".
        "wpaccessFile": (filename, content, ctype),
    })
    qid = flow_final_item(op, base, api, url, body, "AddSource/book/manual (access file)")
    return qid


# Minimal but well-formed PDF (one page with a text run) — enough for the
# upload allow-list, the MIME sniffing and the special page's iframe preview.
E2E_PDF = (b"%PDF-1.4\n"
           b"1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
           b"2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
           b"3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] "
           b"/Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n"
           b"4 0 obj\n<< /Length 44 >>\nstream\nBT /F1 12 Tf 72 260 Td (E2E) Tj ET\nendstream\nendobj\n"
           b"5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n"
           b"trailer\n<< /Root 1 0 R /Size 6 >>\n"
           b"%%EOF\n")


def flow_source_book_access_url(op, base: str, api: str, label: str,
                                author_qid: str, url: str) -> str:
    """Access field, URL mode (issue #35): a non-direct access URL statement
    is written (no license, no upload)."""
    page, body = page_get(op, base, "/wiki/Special:AddSource/book/manual")
    token = edit_token(body)
    page, body = page_post(op, page, {
        "wptitle": label,
        "wpauthors": author_qid,
        "wpaccessMode": "url",
        "wpaccessUrl": url,
        "wpEditToken": token,
        "wpSubmit": "1",
    })
    return flow_final_item(op, base, api, page, body, "AddSource/book/manual (access url)")


def flow_source_book_access_na(op, base: str, api: str, label: str,
                               author_qid: str, description: str = "") -> str:
    """Access field, N/A mode (issue #35): no access statement is written —
    the infobox access row must fall back to "N/A". When a description is
    given, the Source: page must carry it as the == Overview == placeholder
    (no fetched content -> the item description is the page lead)."""
    page, body = page_get(op, base, "/wiki/Special:AddSource/book/manual")
    token = edit_token(body)
    fields = {
        "wptitle": label,
        "wpauthors": author_qid,
        "wpaccessMode": "na",
        "wpEditToken": token,
        "wpSubmit": "1",
    }
    if description:
        fields["wpdescription"] = description
    page, body = page_post(op, page, fields)
    return flow_final_item(op, base, api, page, body, "AddSource/book/manual (access na)")


def source_access_cell(op, api: str, page_title: str) -> str:
    """Renders the {{#source-access:}} cell in the context of the given
    Source: page (the parser function resolves the page's sitelinked item
    and renders its access statements)."""
    r = api_call(op, api, {"action": "parse", "title": page_title,
                           "text": "{{#source-access:}}", "format": "json"})
    return r.get("parse", {}).get("text", {}).get("*", "")


def flow_source_book_access_download(op, base: str, api: str, label: str,
                                     author_qid: str, license_qid: str, url: str) -> str:
    """Access field, direct-download mode (regression): AddSource/book/manual
    with accessMode=download fetches the file SERVER-SIDE (UploadFromUrl).
    Before the fetchFile() fix the URL-mode uploads never downloaded the
    body — verifyUpload saw a zero-size temp file and rejected the upload
    as EMPTY_FILE ("verifyUpload rejected (3)"). The file lands as
    File:<label>.png (auto-named, original name ignored) and the item
    carries the file + license statements."""
    url_page, body = page_get(op, base, "/wiki/Special:AddSource/book/manual")
    token = edit_token(body)
    url_page, body = page_post(op, url_page, {
        "wptitle": label,
        "wpauthors": author_qid,
        "wpaccessMode": "download",
        "wpdownloadUrl": url,
        "wplicense": license_qid,
        "wpEditToken": token,
        "wpSubmit": "1",
    })
    qid = flow_final_item(op, base, api, url_page, body,
                          "AddSource/book/manual (access download)")
    return qid


def flow_person_manual_form(op, base: str) -> None:
    """AddPerson manual form rendering (regressions):
    - the OpenAlex author-ID field resolves its label (the
      embeddablecontent-field-openalexAuthor message was missing, so the
      field rendered the raw ⧼message-key⧽);
    - the Description field sits BELOW Given name / Family name.
    GET-only (page loads are not login-gated); no entity is created."""
    _, body = page_get(op, base, "/wiki/Special:AddPerson/manual")
    if "⧼" in body:
        i = body.find("⧼")
        raise FlowError(f"AddPerson/manual renders an unresolved message key: "
                        f"{body[max(0, i - 60):i + 80]!r}")
    if "OpenAlex ID</label>" not in body:
        raise FlowError("AddPerson/manual missing the resolved 'OpenAlex ID' field label")
    given = label_pos(body, "Given name")
    family = label_pos(body, "Family name")
    desc = label_pos(body, "Description")
    if -1 in (given, family, desc):
        raise FlowError(f"AddPerson/manual missing a name/description field label "
                        f"(given={given}, family={family}, description={desc})")
    if not (given < family < desc):
        raise FlowError(f"AddPerson/manual field order wrong: "
                        f"Given name={given}, Family name={family}, Description={desc} — "
                        f"the description must come below given/family")


def label_pos(body: str, text: str) -> int:
    """Position of an OOUI field label (<label …>Text</label>) in the form
    HTML, or -1. OOUI php-mode renders the label text directly inside the
    <label class="oo-ui-labelElement-label"> element."""
    m = re.search(r"<label[^>]*>\s*" + re.escape(text) + r"\s*</label>", body)
    return m.start() if m else -1


def flow_person_portrait(op, base: str, api: str, label: str, license_qid: str) -> tuple[str, str]:
    """Special:AddPerson/manual + a LOCAL PORTRAIT upload (upload
    enhancements): the portrait section is collapsed behind the
    portraitInclude toggle — this flow checks the box, uploads a 1x1 PNG
    via multipart with the mandatory license + free-text author / license
    info. Returns (qid, File: page title)."""
    png = base64.b64decode(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
    )
    given, _, family = label.rpartition(" ")
    url, body = page_get(op, base, "/wiki/Special:AddPerson/manual")
    token = edit_token(body)
    url, body = page_post_multipart(op, url, {
        "wpgivenName": given,
        "wpfamilyName": family,
        "wpportraitInclude": "1",
        "wpportraitMode": "file",
        "wpportraitLicense": license_qid,
        "wpportraitAuthor": "E2E Portrait Author",
        "wpportraitLicenseInfo": "E2E portrait license note",
        "wpEditToken": token,
        "wpSubmit": "1",
    }, {
        "wpportraitFile": ("portrait.png", png, "image/png"),
    })
    qid = flow_final_item(op, base, api, url, body, "AddPerson/manual (portrait)")
    return qid, f"File:{label}-portrait.png"


def flow_upload_special_form(op, base: str) -> None:
    """Special:Upload form rendering (upload enhancements, logged-in):
    the semantic license combobox replaces the core dropdown, the author +
    license-info fields exist, the "Maximum file size" note appears ONCE,
    and the validate-button wiring span is present on the URL field.
    GET-only; nothing is uploaded."""
    _, body = page_get(op, base, "/wiki/Special:Upload")
    if "wpEditToken" not in body:
        raise FlowError(f"Special:Upload not usable (logged-in? got {len(body)} bytes)")
    # The semantic license combobox: the OOUIComboboxField forces the OOUI
    # widget inside the php-mode UploadForm — an infusable
    # ComboBoxInputWidget carrying the wb-entity-combobox class + data-ooui
    # (entity-suggest infuses it client-side) with the preseed license
    # options. A plain `combobox` type would render an <input>+<datalist>
    # with no entity autocomplete ("native formatting" regression).
    if "oo-ui-comboBoxInputWidget" not in body:
        raise FlowError("Special:Upload license field is not an OOUI combobox widget")
    if "wb-entity-combobox" not in body:
        raise FlowError("Special:Upload license field is not the semantic combobox")
    if "data-ooui" not in body:
        raise FlowError("Special:Upload license combobox missing data-ooui (not infusable)")
    if "CC BY-SA 4.0" not in body:
        raise FlowError("Special:Upload license combobox missing the preseed license options")
    # Author + additional-license-info fields.
    if 'id="wpUploadAuthor"' not in body:
        raise FlowError("Special:Upload missing the image-author field")
    if 'id="wpUploadLicenseInfo"' not in body:
        raise FlowError("Special:Upload missing the additional-license-info field")
    # The license help must render as the translated TEXT, never the raw
    # message key — MW 1.46 treats 'help' as raw HTML; the field uses
    # 'help-message' (a bare key string rendered verbatim before the fix).
    if "embeddablecontent-upload-license-help" in body:
        raise FlowError("Special:Upload renders the raw license-help message key")
    if "Pick the license of the file" not in body:
        raise FlowError("Special:Upload license help text not rendered "
                        "(raw key instead of translated help)")
    # The single "Maximum file size" note (the duplicated parentheticals
    # are gone — the URL field's note slot carries the wiring span + the
    # URL-cap note).
    if body.count("Maximum file size") != 1:
        raise FlowError(
            f"Special:Upload 'Maximum file size' note appears {body.count('Maximum file size')} times, "
            f"expected exactly once")
    if "Maximum URL upload" not in body:
        raise FlowError("Special:Upload URL field missing the 100 MB URL-cap note")
    if body.count("wb-uploadmeta") < 1:
        raise FlowError("Special:Upload URL field missing the validate-button wiring span")
    # The source radio defaults to Url on a FRESH load (upload-ux batch):
    # URL uploads are the common case — the File radio is only checked once
    # the user picks it (or posts wpSourceType). The core UploadSourceField
    # radios are named wpSourceType with values 'File'/'Url'.
    def _radio_checked(radio_id: str) -> bool:
        m = re.search(r"<input[^>]*id=\"" + radio_id + r"\"[^>]*>", body)
        return bool(m) and "checked" in m.group(0)
    if not _radio_checked("wpSourceTypeurl"):
        raise FlowError("Special:Upload does not default to the Url source radio")
    if _radio_checked("wpSourceTypeFile"):
        raise FlowError("Special:Upload defaults to the File radio instead of Url")


def flow_uploadmeta_module_source(op, base: str) -> None:
    """Regression guards on the SERVED uploadmeta module source. The three
    upload follow-up fixes are JS-side (browser blob fallback, dest-name
    normalization, description cap) — a curl E2E cannot execute them, so
    assert the shipped source (load.php?debug=true = unminified): the
    submit-time HOSTNAME parse (the Wikimedia 429 fceb99d fix — passing the
    full URL to isWikimediaHost made the blob fallback never fire), the
    2000-char description cap, and the normalizeDestName helper."""
    _, body = page_get(op, base,
        "/load.php?modules=ext.embeddableContent.uploadmeta&lang=en&skin=vector&debug=true")
    if "new URL( url ).hostname" not in body:
        raise FlowError("uploadmeta module: submit handler missing the hostname parse "
                        "(Wikimedia 429 blob-fallback fix)")
    if "isWikimediaHost( url )" in body:
        raise FlowError("uploadmeta module: submit handler still passes the FULL URL to "
                        "isWikimediaHost (Wikimedia 429 blob-fallback regression)")
    if "DESCRIPTION_CAP = 2000" not in body:
        raise FlowError("uploadmeta module: description cap not raised to 2000")
    if "normalizeDestName" not in body:
        raise FlowError("uploadmeta module: dest-name normalization helper missing")
    # The blob-fallback resubmit must replicate the submit BUTTON's
    # name/value: native submit() drops it, and Special:Upload's core gates
    # processing on getCheck('wpUpload') — without the hidden replication the
    # converted file upload re-renders the form ("page refreshes, nothing
    # uploaded").
    if 'input[type="submit"]' not in body or "wbUploadmetaSourceUrl" not in body:
        raise FlowError("uploadmeta module: blob-fallback submit-button replication missing "
                        "(Special:Upload wpUpload gate regression)")
    # The Commons imageinfo request must carry iiprop=mime — without it the
    # payload has no MIME type and the validate preview cannot distinguish
    # images (shown as <img>) from other file types (shown as a file-icon
    # badge).
    if "iiprop=extmetadata%7Csize%7Curl%7Cmime" not in body:
        raise FlowError("uploadmeta module: Commons imageinfo request missing iiprop=mime "
                        "(preview/file-icon regression)")
    if "extensionForMime" not in body or "wb-uploadmeta-fileicon" not in body:
        raise FlowError("uploadmeta module: dest-name extension / file-icon helpers missing")
    # The validate-clicked-multiple-times fixes (upload-ux batch): the
    # latest-wins generation guard (a stale async license match must never
    # overwrite a newer fetch) and the confirmation-banner dedupe (only the
    # LATEST "logo license" dialog may stay).
    if "validateSeq" not in body:
        raise FlowError("uploadmeta module: latest-wins validate sequencing missing "
                        "(double-validate stale-overwrite regression)")
    if ".wb-entity-confirm[data-field=" not in body:
        raise FlowError("uploadmeta module: license-confirm banner dedupe missing "
                        "(multiple logo-license dialogs regression)")


def flow_upload_special_item(op, base: str, api: str, license_qid: str) -> str:
    """Special:Upload form submission (upload enhancements): uploads a 1x1
    PNG with the license combobox value (item id), author + license info,
    then verifies the item-per-upload — a sitelinked image item carrying the
    semantic statements — and the attribution block on the File page.
    Returns the image item qid."""
    png = base64.b64decode(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
    )
    dest = f"E2E upload {int(time.time())}"
    url, body = page_get(op, base, "/wiki/Special:Upload")
    token = edit_token(body)
    url, body = page_post_multipart(op, url, {
        "wpDestFile": dest + ".png",
        "wpUploadDescription": "Uploaded by the page-flow E2E.",
        "wpLicense": license_qid,
        "wpUploadAuthor": "E2E Upload Author",
        "wpUploadLicenseInfo": "E2E upload license note",
        "wpUploadmetaItemize": "1",
        # The fixture is the same 1x1 PNG as the access-flow fixtures — the
        # duplicate-file WARNING would re-render the form; the E2E is not
        # testing duplicate handling.
        "wpIgnoreWarning": "1",
        "wpEditToken": token,
        "wpUpload": "1",
    }, {
        "wpUploadFile": (dest + ".png", png, "image/png"),
    })
    if "/wiki/File:" not in url and "File:" not in body:
        raise FlowError(f"Special:Upload did not land on the File page: {url} {find_error(body)}")

    # The image item: label = the file name without extension.
    item_label = dest
    r = api_call(op, api, {"action": "wbsearchentities", "search": item_label,
                           "language": "en", "type": "item", "limit": 1, "format": "json"})
    qid = r.get("search", [{}])[0].get("id") if r.get("search") else None
    if not qid:
        raise FlowError(f"Special:Upload created no image item for {item_label!r}")
    claims, _ = entity_claims(op, api, qid)
    # instance of -> the image class, image -> the File: URL, license +
    # author + license info statements, sitelinked to the File page.
    # Self-contained label resolution (main()'s resolve is out of scope here).
    def resolve(label: str, etype: str) -> str:
        rid = resolve_label(op, api, label, etype)
        if not rid:
            raise FlowError(f"upload flow: vocabulary label not found: {label!r}")
        return rid
    image_class = resolve("image", "item")
    instance_of_prop = resolve("instance of", "property")
    image_prop = resolve("image", "property")
    license_prop = resolve("license", "property")
    author_prop = resolve("image author", "property")
    info_prop = resolve("additional license information", "property")
    assert first_value(claims, instance_of_prop) == image_class, \
        f"{qid} instance-of != image class ({first_value(claims, instance_of_prop)})"
    image_url = first_value(claims, image_prop)
    # The File: URL is title-normalized — match the underscore form.
    assert image_url and "File:" in str(image_url) and dest.replace(" ", "_") in str(image_url), \
        f"{qid} missing image statement pointing at the uploaded file ({image_url!r})"
    license_value = first_value(claims, license_prop)
    assert license_value == license_qid, f"{qid} license statement mismatch ({license_value!r})"
    author_value = first_value(claims, author_prop)
    assert author_value == "E2E Upload Author", f"{qid} image-author statement missing ({author_value!r})"
    info_value = first_value(claims, info_prop)
    assert info_value == "E2E upload license note", \
        f"{qid} additional-license-info statement missing ({info_value!r})"
    # Sitelinked to the File: page.
    r = api_call(op, api, {"action": "wbgetentities", "ids": qid, "props": "sitelinks", "format": "json"})
    links = r.get("entities", {}).get(qid, {}).get("sitelinks", {})
    assert links.get("wikibase", {}).get("title") == f"File:{dest}.png", \
        f"{qid} not sitelinked to File:{dest}.png ({links})"

    # The File page carries the attribution block (author + license info +
    # the semantic license reference — never a {{Q42}} template call).
    # File DB keys normalize spaces to underscores — match the stored form.
    _, raw = page_get(op, base, "/wiki/" + urllib.parse.quote(("File:" + dest + ".png").replace(" ", "_")) + "?action=raw")
    assert "Attribution" in raw and "E2E Upload Author" in raw, \
        f"File:{dest}.png missing the attribution block; raw: {raw[:400]!r}"
    assert "{{" + license_qid + "}}" not in raw, \
        f"File:{dest}.png renders the license item id as a template call"
    return qid

def create_api_item(op, api: str, label: str) -> str:
    """Creates a bare item via the API (labels only) — e.g. the publisher
    entity for the AddSource publisher-field test. This instance's Wikibase
    requires the FULL term form ({"en": {"language": "en", "value": …}});
    the short form {"en": "…"} is rejected (not-recognized-array)."""
    csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
    token = csrf["query"]["tokens"]["csrftoken"]
    r = api_call(op, api, {
        "action": "wbeditentity", "new": "item",
        "data": json.dumps({"labels": {"en": {"language": "en", "value": label}}}),
        "token": token, "format": "json",
    }, post=True)
    if "entity" not in r:
        raise FlowError(f"wbeditentity(new=item) failed: {r}")
    return r["entity"]["id"]


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


def flow_collective_logo(op, base: str, api: str, label: str, class_item: str,
                         license_qid: str) -> str:
    """Special:AddCollective/manual with a LOCAL LOGO upload (issue
    follow-up + upload enhancements): posts a 1x1 PNG via multipart behind
    the logoInclude toggle (license required, author + license info free
    text), verifies the image + license + attribution statements. Returns
    the item id."""
    png = base64.b64decode(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
    )
    url, body = page_get(op, base, "/wiki/Special:AddCollective/manual")
    token = edit_token(body)
    url, body = page_post_multipart(op, url, {
        "wplabel": label,
        "wpclass": class_item,
        "wplogoInclude": "1",
        "wplogoMode": "file",
        "wplogoLicense": license_qid,
        "wplogoAuthor": "E2E Collective Logo Author",
        "wplogoLicenseInfo": "E2E collective license note",
        "wpEditToken": token,
        "wpSubmit": "1",
    }, {
        # The OOUI form renders wp + field key as-is: "logoFile" -> "wplogoFile".
        "wplogoFile": ("logo.png", png, "image/png"),
    })
    return flow_final_item(op, base, api, url, body, "AddCollective/manual (logo)")


def flow_collective_logo_reuse(op, base: str, api: str, label: str, class_item: str,
                               license_qid: str, existing_file: str) -> str:
    """Special:AddCollective/manual with mode=existing — the new "reuse an
    existing file on this wiki" image option (upload-ux batch): the logo
    field points at a File: title uploaded EARLIER in the run instead of
    uploading new bytes; no new File page is created. Verifies the image
    statement references the SAME File: page (and the infobox logo param
    carries it — asserted by the caller)."""
    url, body = page_get(op, base, "/wiki/Special:AddCollective/manual")
    token = edit_token(body)
    url, body = page_post(op, url, {
        "wplabel": label,
        "wpclass": class_item,
        "wplogoInclude": "1",
        "wplogoMode": "existing",
        "wplogoExisting": existing_file,  # "File:<name>-logo.png"
        "wplogoLicense": license_qid,
        "wplogoAuthor": "E2E Collective Reuse Author",
        "wplogoLicenseInfo": "E2E reuse license note",
        "wpEditToken": token,
        "wpSubmit": "1",
    })
    return flow_final_item(op, base, api, url, body, "AddCollective/manual (logo reuse)")


def flow_item_image_renders(op, base: str, api: str, qid: str, expect: str) -> None:
    """The {{#item-image:}} parser function renders the item's image
    statement (the statement-driven infobox cell, upload-ux follow-up): a
    scratch page transcluding {{#item-image:<qid>}} must show the uploaded
    file. This is the regression the production report hit — an image
    statement present on the item but the logo not displayed on classic
    pages whose skeleton predates the logo param (the cell now reads the
    item, not a creation-time page param). Self-cleaning: the scratch page
    is deleted afterwards."""
    scratch = f"ItemImage scratch {int(time.time())}"
    csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
    token = csrf["query"]["tokens"]["csrftoken"]
    r = api_call(op, api, {
        "action": "edit", "title": scratch, "text": f"{{{{#item-image:{qid}}}}}",
        "token": token, "summary": "page-flow E2E scratch ({{#item-image:}})",
        "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"{{{{#item-image:}}}} scratch page creation failed: {r!r}")
    try:
        _, rendered = page_get(op, base, "/wiki/" + urllib.parse.quote(scratch.replace(" ", "_")))
        if "<span class=\"error\"" in rendered or "errorbox" in rendered:
            raise FlowError(f"{{{{#item-image:{qid}}}}} scratch page rendered parser errors")
        if expect not in rendered or "mw-broken-media" in rendered:
            # Diagnose: show the parser-output region + any markers that hint
            # at what happened (a Template:Item-image redlink = the magic word
            # did not resolve; the file title = the cell rendered but the
            # assertion string mismatched; an error box = a thrown exception;
            # mw-broken-media = the cell rendered a broken file link, e.g.
            # the doubled 'File:File:' prefix).
            region = rendered
            m = rendered.find("mw-parser-output")
            if m != -1:
                region = rendered[m:m + 1200]
            markers = []
            for marker in re.finditer(r"(Item-image|item-image|error|logo|portrait|File:|Q\d+|mw-broken-media)", region):
                s = max(0, marker.start() - 60)
                markers.append(region[s:marker.end() + 100].replace("\n", " "))
            raise FlowError(
                f"{{{{#item-image:{qid}}}}} did not render a working file link "
                f"(expect {expect!r}, broken-media {'YES' if 'mw-broken-media' in rendered else 'no'}); "
                f"parser-output region: {region[:1200]!r}"
                f"{' | markers: ' + ' || '.join(markers[:5]) if markers else ''}")
    finally:
        csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
        token = csrf["query"]["tokens"]["csrftoken"]
        api_call(op, api, {"action": "delete", "title": scratch, "token": token,
                           "reason": "page-flow E2E cleanup (run_pages_e2e.py)", "format": "json"}, post=True)


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
        resolve("private company", "item"),
        resolve("public company", "item"),
        resolve("non-profit organization", "item"),
        resolve("governmental agency", "item"),
        resolve("music band", "item"),
        resolve("educational institution", "item"),
        resolve("research institute", "item"),
        resolve("political party", "item"),
        resolve("trade union", "item"),
        resolve("religious organization", "item"),
        resolve("sports team", "item"),
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

        # 1b. AddPerson manual with the birth/death facts: day-precision date
        #     fields + entity-combobox places, with the deceased toggle open.
        date_of_birth_prop = resolve("date of birth", "property")
        place_of_birth_prop = resolve("place of birth", "property")
        date_of_death_prop = resolve("date of death", "property")
        place_of_death_prop = resolve("place of death", "property")
        official_website_prop = resolve("official website", "property")
        person_manual_label = f"Page-flow E2E person {int(time.time())}"
        person_manual = track(flow_manual(op, base, api, "AddPerson", person_manual_label,
                                          person_class, {
                                              "wpdateOfBirth": "1960-01-02",
                                              "wpplaceOfBirth": person,
                                              "wpdeceased": "1",
                                              "wpdateOfDeath": "2015-03-04",
                                              "wpplaceOfDeath": person,
                                              "wpwebsite": "https://example.org/person",
                                          }))
        claims, _ = entity_claims(op, api, person_manual)
        assert first_value(claims, date_of_birth_prop) is not None and \
            first_value(claims, date_of_birth_prop).get("time", "").startswith("+1960-01-02"), \
            f"{person_manual} date of birth statement not written"
        assert first_value(claims, place_of_birth_prop) == person, \
            f"{person_manual} place of birth statement not written"
        assert first_value(claims, date_of_death_prop).get("time", "").startswith("+2015-03-04"), \
            f"{person_manual} date of death statement not written (deceased toggle)"
        assert first_value(claims, place_of_death_prop) == person, \
            f"{person_manual} place of death statement not written"
        assert first_value(claims, official_website_prop) == "https://example.org/person", \
            f"{person_manual} official-website statement not written " \
            f"({first_value(claims, official_website_prop)})"
        print(f"[ok] AddPerson/manual -> {person_manual}: birth/death dates + places, "
              f"deceased toggle, official website")

        # 1b1. Update flows (autofill-confirm-update): the Item page offers
        #     "Update basic information"; Special:UpdatePerson/<qid> renders
        #     the AddPerson fields prefilled from the item and UPDATES it —
        #     the new description lands, the birth/death statements survive.
        flow_update_button(op, base, person_manual, "UpdatePerson")
        new_description = f"Updated by the update-flow E2E {int(time.time())}"
        flow_update_person(op, base, api, person_manual, new_description)
        claims, _ = entity_claims(op, api, person_manual)
        assert entity_descriptions(op, api, person_manual) == new_description, \
            f"{person_manual} description not updated"
        assert first_value(claims, date_of_birth_prop) is not None and \
            first_value(claims, date_of_birth_prop).get("time", "").startswith("+1960-01-02"), \
            f"{person_manual} date of birth lost by the update"
        assert first_value(claims, place_of_birth_prop) == person, \
            f"{person_manual} place of birth lost by the update"
        print(f"[ok] Special:UpdatePerson/{person_manual}: description updated, "
              f"birth/death statements preserved")

        # 1b1b. Update no-clobber (upload-ux batch): blanked managed fields
        #     (description + place of death) keep the existing values.
        old_description = entity_descriptions(op, api, person_manual)
        flow_update_person_no_clobber(op, base, api, person_manual)
        assert entity_descriptions(op, api, person_manual) == old_description, \
            f"{person_manual} blanked description overwrote the existing one (no-clobber)"
        claims, _ = entity_claims(op, api, person_manual)
        assert first_value(claims, place_of_death_prop) == person, \
            f"{person_manual} blanked place-of-death field lost the existing statement (no-clobber)"
        print(f"[ok] Special:UpdatePerson/{person_manual}: blanked fields keep existing "
              f"description + place-of-death (no-clobber)")

        # 1b2. AddPerson manual form rendering (regressions): the OpenAlex
        #     author-ID field must resolve its label (no ⧼message-key⧽) and
        #     the Description field must sit below Given name / Family name.
        flow_person_manual_form(op, base)
        print("[ok] AddPerson/manual form: 'OpenAlex ID' label resolved, "
              "description below given/family")

        # 1c. AddPerson search autofill (issue #35): a name search offers the
        #     tokenised manual link (selection AND zero-hit pages); the manual
        #     form is prefilled — given = every word except the last, family =
        #     last word — and the label is the derived full name (no label
        #     field on the form).
        autofill_name = f"Page-flow E2E autofill {int(time.time())}"
        autofill_person, derived = flow_person_manual_autofill(op, base, api, autofill_name)
        autofill_person = track(autofill_person)
        claims, label = entity_claims(op, api, autofill_person)
        assert label == derived, \
            f"{autofill_person} label not derived from given/family ({label!r} != {derived!r})"
        expected_given, _, expected_family = autofill_name.rpartition(" ")
        assert first_value(claims, resolve("given name", "property")) == expected_given and \
            first_value(claims, resolve("family name", "property")) == expected_family, \
            f"{autofill_person} given/family statements not written from the autofill"
        # Description-as-placeholder: no fetched biography -> the Person:
        # page carries the item description as == Overview == (was a
        # biography-only/empty scaffold before).
        person_page = page_wikitext(op, api, f"Person:{derived}")
        assert "== Overview ==" in person_page and \
            "Person-flow E2E placeholder description" in person_page, \
            f"Person:{derived} missing the description placeholder: {person_page[:200]!r}"
        print(f"[ok] AddPerson manual autofill -> {autofill_person}: name search prefilled "
              f"given/family, label derived, description placeholder on the page")

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
        #     auto-links it with a `part of` statement. Blank description /
        #     year / authors are auto-generated / inferred from the parent
        #     book: description -> "Pages a-b (Volume c) of {book}", year
        #     and authors copied from the parent's date / attributed-to
        #     statements. Also a regression: the description TERM and the
        #     year STATEMENT are persisted (both were previously discarded).
        book = resolve("Notes by the Translator", "item")  # seed dogfood book
        book_author = resolve("Ada Lovelace", "item")  # dogfood book's author
        excerpt_label = f"Page-flow E2E book excerpt {int(time.time())}"
        excerpt = track(flow_source_class_manual(op, base, api, "bookExcerpt", {
            "wptitle": excerpt_label, "wpparent": book,
            "wppages": "12-30", "wpvolume": "2"}))
        claims, _ = entity_claims(op, api, excerpt)
        assert first_value(claims, instance_of) == book_excerpt_class, \
            f"{excerpt} instance-of != book excerpt ({first_value(claims, instance_of)})"
        assert first_value(claims, part_of_prop) == book, \
            f"{excerpt} missing the part-of link to the parent book"
        assert entity_descriptions(op, api, excerpt) == \
            "Pages 12-30 (Volume 2) of Notes by the Translator", \
            f"{excerpt} description not auto-generated from pages/volume + parent"
        date_claim = first_value(claims, resolve("date", "property"))
        assert date_claim is not None and str(date_claim.get("time", "")).startswith("+1843"), \
            f"{excerpt} year not inferred from the parent book ({date_claim})"
        assert first_value(claims, resolve("attributed to", "property")) == book_author, \
            f"{excerpt} authors not inferred from the parent book"
        # Caveat: bookExcerpt is part of a book — it must NOT create a
        # Source: page (unlike the other source classes).
        page_q = api_call(op, api, {"action": "query", "titles": f"Source:{excerpt_label}",
                                    "format": "json"})
        pages = list(page_q.get("query", {}).get("pages", {}).values())
        assert not pages or "missing" in pages[0], \
            f"bookExcerpt {excerpt} unexpectedly created a Source: page"
        print(f"[ok] AddSource (bookExcerpt) -> {excerpt}: child class + part-of -> {book}; "
              f"description autogen + year/authors inferred from parent, no Source: page")

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

        # 2g. AddSource/book publisher — entity-only (issue #35): the manual
        #     form takes a publisher ITEM; the created item carries the entity
        #     publisher statement and NO string statement.
        publisher_qid = create_api_item(op, api, f"Page-flow E2E publisher {int(time.time())}")
        book_pub_label = f"Page-flow E2E book publisher {int(time.time())}"
        book_pub = track(flow_source_class_manual(op, base, api, "book", {
            "wptitle": book_pub_label, "wpauthors": person,
            "wppublisher": publisher_qid}))
        claims, _ = entity_claims(op, api, book_pub)
        publisher_prop = resolve("publisher (entity)", "property")
        string_publisher_prop = resolve("publisher", "property")
        assert first_value(claims, publisher_prop) == publisher_qid, \
            f"{book_pub} entity publisher statement missing ({first_value(claims, publisher_prop)})"
        assert claims.get(string_publisher_prop) is None, \
            f"{book_pub} unexpectedly carries a string publisher statement"
        print(f"[ok] AddSource/book publisher -> {book_pub}: entity publisher {publisher_qid}, "
              f"no string statement")

        # 2h. AddSource/book access field, local-file mode (issue #35): the
        #     upload lands as File:<label>.png (auto-named from the item
        #     label, original filename ignored) with the license + file
        #     statements. The license item is created by the test itself —
        #     the CI stack does not run the preseed phase, so no preseeded
        #     license item is guaranteed to exist.
        license_qid = create_api_item(op, api, f"Page-flow E2E license {int(time.time())}")
        access_label = f"Page-flow E2E access {int(time.time())}"
        access_book = track(flow_source_book_access_file(
            op, base, api, access_label, person, license_qid))
        claims, _ = entity_claims(op, api, access_book)
        file_prop = resolve("file", "property")
        license_prop = resolve("license", "property")
        file_val = first_value(claims, file_prop)
        assert file_val and access_label.replace(" ", "_") in file_val, \
            f"{access_book} file statement missing or not auto-named from the label ({file_val})"
        assert first_value(claims, license_prop) == license_qid, \
            f"{access_book} license statement missing ({first_value(claims, license_prop)})"
        file_page = api_call(op, api, {"action": "query", "titles": f"File:{access_label} (Book).png",
                                       "format": "json"})
        pages = list(file_page.get("query", {}).get("pages", {}).values())
        assert pages and "missing" not in pages[0], \
            f"File:{access_label} (Book).png not created"
        print(f"[ok] AddSource/book access (local file) -> {access_book}: "
              f"File:{access_label} (Book).png + license + file statements")

        # 2h2. Access field, DIRECT-DOWNLOAD mode (regression): the file is
        #     fetched server-side (UploadFromUrl + fetchFile). Before the fix
        #     every URL-mode upload died on verifyUpload rejected (3)
        #     (EMPTY_FILE — the body was never downloaded). A stable public
        #     PNG (w3.org, 200) is fetched by the wiki server; the license
        #     item is created by the test itself like the local-file mode.
        download_license_qid = create_api_item(op, api, f"Page-flow E2E download license {int(time.time())}")
        download_label = f"Page-flow E2E download {int(time.time())}"
        download_book = track(flow_source_book_access_download(
            op, base, api, download_label, person, download_license_qid,
            "https://www.w3.org/assets/logos/w3c-2025-transitional/w3c-72x48.png"))
        claims, _ = entity_claims(op, api, download_book)
        download_file_val = first_value(claims, file_prop)
        assert download_file_val and download_label.replace(" ", "_") in download_file_val, \
            f"{download_book} file statement missing or not auto-named from the label " \
            f"({download_file_val}) — URL-mode upload did not land"
        assert first_value(claims, license_prop) == download_license_qid, \
            f"{download_book} license statement missing ({first_value(claims, license_prop)})"
        download_page = api_call(op, api, {"action": "query", "titles": f"File:{download_label} (Book).png",
                                           "format": "json"})
        pages = list(download_page.get("query", {}).get("pages", {}).values())
        assert pages and "missing" not in pages[0], \
            f"File:{download_label} (Book).png not created"
        print(f"[ok] AddSource/book access (download) -> {download_book}: "
              f"server-side fetch landed File:{download_label} (Book).png + license + file statements")

        # 2h3. Source: access row (issue follow-up): the {{#source-access:}}
        #     parser function renders the infobox "Access" cell from the
        #     item's statements — file (linked to Special:SourceFile with the
        #     owning item id) > access URL (clickable) > N/A. The Source:
        #     page titles carry the class disambiguation suffix (" (Book)").
        access_cell = source_access_cell(op, api, f"Source:{access_label} (Book)")
        assert "Special:SourceFile" in access_cell and f"item={access_book}" in access_cell, \
            f"file-mode access row not rendered as a Special:SourceFile link: {access_cell}"
        url_label = f"Page-flow E2E access-url {int(time.time())}"
        url_book = track(flow_source_book_access_url(
            op, base, api, url_label, person, "https://example.org/e2e-access"))
        url_cell = source_access_cell(op, api, f"Source:{url_label} (Book)")
        assert "https://example.org/e2e-access" in url_cell and "external" in url_cell, \
            f"url-mode access row not rendered as a clickable link: {url_cell}"
        na_label = f"Page-flow E2E access-na {int(time.time())}"
        na_desc = "A regression-test book with no fetched content."
        na_book = track(flow_source_book_access_na(op, base, api, na_label, person, na_desc))
        na_cell = source_access_cell(op, api, f"Source:{na_label} (Book)")
        assert "N/A" in na_cell, f"na-mode access row not 'N/A': {na_cell}"
        # Description-as-placeholder: no fetched content -> the item
        # description is the Source: page's == Overview == lead (was a
        # template-only page before).
        na_page = page_wikitext(op, api, f"Source:{na_label} (Book)")
        assert "== Overview ==" in na_page and na_desc in na_page, \
            f"Source:{na_label} (Book) missing the description placeholder: {na_page[:200]!r}"
        print("[ok] Source: access row: file -> Special:SourceFile link, "
              "URL -> clickable link, none -> N/A")

        # 2h4. Special:SourceFile (issue follow-up): the PNG copy from 2h
        #     renders the licence + the gated download (no iframe for a
        #     non-PDF). Unchecked download is rejected server-side; the
        #     checked download redirects to the file.
        special_path = ("/wiki/Special:SourceFile?item=%s&file=File%%3A%s.png"
                        % (access_book, urllib.parse.quote(access_label + " (Book)")))
        special_url, body = page_get(op, base, special_path)
        assert "wb-sourcefile-licence" in body, "Special:SourceFile missing the licence block"
        assert license_qid in body, "Special:SourceFile missing the licence label"
        # OOUI php-mode checkboxes render with SINGLE-quoted attributes.
        assert "name='wpaccept'" in body or 'name="wpaccept"' in body, \
            "Special:SourceFile missing the licence-accept checkbox"
        assert "wb-sourcefile-download" in body, "Special:SourceFile missing the download submit"
        assert "<iframe" not in body, "non-PDF must not render an iframe preview"
        token = edit_token(body)
        url, body = page_post(op, special_url, {"wpEditToken": token, "wpSubmit": "1"})
        assert "required" in body.lower(), \
            f"unchecked download not rejected: {find_error(body) or url}"
        token = edit_token(body)
        url, body = page_post(op, special_url, {"wpaccept": "1", "wpEditToken": token,
                                                "wpSubmit": "1"})
        assert "/images/" in url, f"checked download did not redirect to the file: {url}"
        print("[ok] Special:SourceFile: licence + gated download (unchecked rejected, "
              "checked -> file)")

        # 2h5. Special:SourceFile PDF preview (issue follow-up): a PDF copy
        #     renders an embedded iframe preview on the special page.
        pdf_license_qid = create_api_item(op, api, f"Page-flow E2E pdf license {int(time.time())}")
        pdf_label = f"Page-flow E2E pdf {int(time.time())}"
        pdf_book = track(flow_source_book_access_file(
            op, base, api, pdf_label, person, pdf_license_qid,
            content=E2E_PDF, filename="original.pdf", ctype="application/pdf"))
        pdf_special = ("/wiki/Special:SourceFile?item=%s&file=File%%3A%s.pdf"
                       % (pdf_book, urllib.parse.quote(pdf_label + " (Book)")))
        _, body = page_get(op, base, pdf_special)
        assert "<iframe" in body and "wb-sourcefile-preview" in body, \
            "PDF special page missing the embedded iframe preview"
        print("[ok] Special:SourceFile: PDF copy renders an embedded preview")

        # 2h6. Entity descriptions up to 2000 chars (issue follow-up): the
        #     term-store column was widened (VARBINARY(2000)) and the
        #     validator raised ($wgWBRepoSettings['string-limits']['multilang']
        #     ['length']) — a description longer than Wikibase's default 250
        #     must save via the API and read back intact.
        long_desc = "Page-flow E2E long description " + "x" * 300
        csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
        desc_token = csrf["query"]["tokens"]["csrftoken"]
        desc_item = api_call(op, api, {
            "action": "wbeditentity", "new": "item",
            "data": json.dumps({
                "labels": {"en": {"language": "en", "value": f"Page-flow E2E desc {int(time.time())}"}},
                "descriptions": {"en": {"language": "en", "value": long_desc}},
            }),
            "token": desc_token, "format": "json",
        }, post=True)
        assert "entity" in desc_item, f"long-description item creation failed: {desc_item}"
        desc_qid = desc_item["entity"]["id"]
        desc_read = api_call(op, api, {"action": "wbgetentities", "ids": desc_qid,
                                       "props": "descriptions", "format": "json"})
        assert desc_read["entities"][desc_qid]["descriptions"]["en"]["value"] == long_desc, \
            "long description not persisted intact"
        print(f"[ok] entity descriptions up to 2000 chars: {desc_qid} saved "
              f"a {len(long_desc)}-char description")

        # 2i. AddSource/book manual autofill (issue #35): title + entity-mode
        #     author search -> the tokenised manual link prefills title and
        #     authors.
        book_autofill_title = f"Page-flow E2E book autofill {int(time.time())}"
        book_autofill = track(flow_source_book_manual_autofill(
            op, base, api, book_autofill_title, person))
        claims, label = entity_claims(op, api, book_autofill)
        # The AddSource label convention: the class disambiguation suffix
        # (" (Book)") is appended at creation.
        assert label == book_autofill_title + " (Book)", \
            f"{book_autofill} label mismatch ({label!r})"
        print(f"[ok] AddSource/book manual autofill -> {book_autofill}: "
              f"title + author carried from the search, label suffixed (Book)")

        # 2i1. Free-text author autofill-confirm (autofill-confirm-update):
        #     a NAME author search fuzzy-matches an existing person item —
        #     the manual form prefills wpauthors with the Q-id AND renders
        #     the .wb-entity-confirm banner.
        author_confirm_title = f"Page-flow E2E book author-confirm {int(time.time())}"
        author_confirm_qid, matched_author = flow_source_manual_author_confirm(
            op, base, api, author_confirm_title, "Ada Lovelace")
        track(author_confirm_qid)
        print(f"[ok] AddSource/book free-text author -> {author_confirm_qid}: "
              f"fuzzy-matched {matched_author} + confirmation banner")

        # 2i2. Source update flow (autofill-confirm-update):
        #     Special:UpdateSource/<qid> prefills the book's review fields
        #     and updates the description; the title/authors survive.
        flow_update_button(op, base, book_autofill, "UpdateSource")
        new_book_description = f"Updated source by the update-flow E2E {int(time.time())}"
        flow_update_source(op, base, api, book_autofill, new_book_description)
        claims, label = entity_claims(op, api, book_autofill)
        # The update keeps the stored label as-is (the suffix is a
        # creation-time convention — it stays because it is already part of
        # the label).
        assert label == book_autofill_title + " (Book)", \
            f"{book_autofill} label changed by the update"
        assert entity_descriptions(op, api, book_autofill) == new_book_description, \
            f"{book_autofill} description not updated"
        assert first_value(claims, resolve("attributed to", "property")) is not None, \
            f"{book_autofill} authors lost by the update"
        print(f"[ok] Special:UpdateSource/{book_autofill}: description updated, "
              f"title + authors preserved")

        # 2j. Class picker (issue follow-up): the manual checkbox is gone;
        #     picking a class routes to its class-scoped first step.
        manual_pick_url = flow_source_picker_route(op, base)
        print(f"[ok] AddSource picker -> {manual_pick_url.rsplit('/wiki/', 1)[-1]}")

        # 2j1. Webpage → website parent inference (add-flow round + host-match
        #     follow-up): the site root of the entered webpage URL is
        #     auto-matched against website-class items — asserted against the
        #     LIVE state: a URL-host record → the parent is AUTO-ASSIGNED
        #     silently (no banner); only a name match → prefilled +
        #     confirmation banner; no record → the "No record found for
        #     <root>" hint. The created webpage carries the `part of`
        #     statement.
        flow_source_webpage_parent_hint(op, base, api)
        print("[ok] AddSource/webpage parent inference: hint / banner / silent "
              "host auto-assign rendered on the manual form")
        webpage_child, webpage_parent = \
            flow_source_webpage_parent_match(op, base, api, person)
        track(webpage_child)
        claims, label = entity_claims(op, api, webpage_child)
        assert first_value(claims, part_of_prop) == webpage_parent, \
            f"{webpage_child} part-of != inferred website {webpage_parent} " \
            f"({first_value(claims, part_of_prop)})"
        # The webpage label carries its own class suffix (the label
        # convention) — " (Webpage)", distinct from the parent's " (Website)".
        assert label.endswith(" (Webpage)"), \
            f"{webpage_child} label without the (Webpage) suffix ({label!r})"
        print(f"[ok] AddSource/webpage parent inference -> {webpage_child}: "
              f"parent prefilled + confirmed, part-of -> {webpage_parent}, "
              f"label suffixed (Webpage)")

        # 2k. Website URL-first flow (issue follow-up): the first page is a
        #     URL entry; the fetched metadata prefills the manual form.
        website_url_item = track(flow_source_url_entry(
            op, base, api, "website", "https://example.org/e2e", person))
        claims, _ = entity_claims(op, api, website_url_item)
        assert first_value(claims, instance_of) == website_class, \
            f"{website_url_item} instance-of != website ({first_value(claims, instance_of)})"
        print(f"[ok] AddSource/website URL entry -> {website_url_item}: "
              f"metadata autofill + website class")

        # 2l. Scholarly-article content step (issue follow-up): review routes
        #     to /review/<i>/content with the fetched abstract/keywords
        #     textareas; the Source: page carries == Abstract ==. A DIFFERENT
        #     DOI than flow 2a so create-or-skip creates a fresh item+page
        #     (reusing 2a's page would read a possibly fetch-less skeleton).
        article_item = track(flow_source_content_step(
            op, base, api, "10.1038/35057062", person))
        print(f"[ok] AddSource/scholarlyArticle content step -> {article_item}: "
              f"abstract/keywords reviewed + written to the page")

        # 2m. Special:AddFictionalCharacter (issue follow-up): Wikidata
        #     search; label auto-generates with the "(fictional character)"
        #     suffix; class fixed to the fictional-character class.
        character_class = resolve("fictional character", "item")
        character = track(flow_fictional_character(op, base, api))
        claims, label = entity_claims(op, api, character)
        assert first_value(claims, instance_of) == character_class, \
            f"{character} instance-of != fictional character ({first_value(claims, instance_of)})"
        assert "(fictional character)" in label, \
            f"{character} label not auto-generated with the suffix ({label!r})"
        print(f"[ok] AddFictionalCharacter -> {character} ({label[:60]}…): "
              f"fictional-character class + autogen label")

        # 3. AddCollective — harvest class hints; instance-of must be an agent class
        collective = track(flow_collective(op, base, api, args.collective))
        claims, label = entity_claims(op, api, collective)
        assert first_value(claims, instance_of) in agent_classes, \
            f"{collective} instance-of not an agent class ({first_value(claims, instance_of)})"
        assert first_value(claims, wikidata_id_prop), f"{collective} missing Wikidata ID"
        # Description-as-placeholder: a collective carries no fetched page
        # content, so its page shows the item description as == Overview ==
        # (when the item has one) instead of a template-only page.
        collective_desc = entity_descriptions(op, api, collective)
        collective_page = page_wikitext(op, api, f"Collective:{label}")
        if collective_desc:
            assert "== Overview ==" in collective_page and collective_desc in collective_page, \
                f"Collective:{label} missing the description placeholder: {collective_page[:200]!r}"
        else:
            assert "== Overview ==" not in collective_page, \
                f"Collective:{label} has an Overview without a description: {collective_page[:200]!r}"
        print(f"[ok] AddCollective -> {collective} ({label}): agent class + Wikidata ID, "
              f"description placeholder on the page")

        # 3a. AddCollective manual — the optional "Parent organization"
        #     entity field (issue follow-up): a referenced item lands as a
        #     `parent organization` statement; an empty field writes none.
        parent_org_qid = create_api_item(op, api, f"Page-flow E2E parent org {int(time.time())}")
        collective_manual_label = f"Page-flow E2E collective {int(time.time())}"
        collective_manual = track(flow_manual(op, base, api, "AddCollective",
                                              collective_manual_label,
                                              resolve("organization", "item"),
                                              {"wpparentOrganization": parent_org_qid,
                                               "wpwebsite": "https://example.org/collective"}))
        claims, _ = entity_claims(op, api, collective_manual)
        assert first_value(claims, resolve("parent organization", "property")) == parent_org_qid, \
            f"{collective_manual} parent-organization statement missing or wrong " \
            f"({first_value(claims, resolve('parent organization', 'property'))})"
        assert first_value(claims, official_website_prop) == "https://example.org/collective", \
            f"{collective_manual} official-website statement not written " \
            f"({first_value(claims, official_website_prop)})"
        print(f"[ok] AddCollective/manual -> {collective_manual}: optional parent "
              f"organization + official website statements written "
              f"({parent_org_qid})")

        # 3a2. AddCollective logo (issue follow-up): the optional logo
        #     uploads as File:<label>-logo.png (AddSoftware pattern) with a
        #     mandatory license; the image + license statements are written.
        collective_logo_license_qid = create_api_item(
            op, api, f"Page-flow E2E collective logo license {int(time.time())}")
        collective_logo_label = f"Page-flow E2E collective logo {int(time.time())}"
        collective_logo = track(flow_collective_logo(
            op, base, api, collective_logo_label, resolve("organization", "item"),
            collective_logo_license_qid))
        claims, _ = entity_claims(op, api, collective_logo)
        collective_image_url = first_value(claims, resolve("image", "property"))
        assert collective_image_url and "logo.png" in str(collective_image_url), \
            f"{collective_logo} missing image statement pointing at the logo ({collective_image_url!r})"
        assert first_value(claims, resolve("license", "property")) == collective_logo_license_qid, \
            f"{collective_logo} missing logo license statement " \
            f"({first_value(claims, resolve('license', 'property'))})"
        assert first_value(claims, resolve("image author", "property")) == "E2E Collective Logo Author", \
            f"{collective_logo} missing the collective logo image-author statement"
        # The Collective: page skeleton passes the logo to the infobox (the
        # AddSoftware/FOSS pattern — upload-ux batch).
        _, raw = page_get(op, base, "/wiki/" + urllib.parse.quote(
            f"Collective:{collective_logo_label}") + "?action=raw")
        assert f"logo=[[File:{collective_logo_label.replace(' ', '_')}-logo.png" in raw, \
            f"Collective:{collective_logo_label} skeleton does not pass the logo to the infobox; raw: {raw[:200]!r}"
        # The infobox image cell renders from the ITEM's image statement
        # ({{#item-image:}} — upload-ux follow-up): the statement-driven
        # cell, so pages whose skeleton predates the logo param still show
        # the image. This is the production report's exact regression.
        flow_item_image_renders(op, base, api, collective_logo, "-logo.png")
        print(f"[ok] AddCollective/manual (logo) -> {collective_logo}: "
              f"File:{collective_logo_label}-logo.png + image/license/attribution statements, "
              f"infobox logo on Collective:{collective_logo_label}")

        # 3a3. AddCollective/manual + mode=existing (upload-ux batch): the
        #     logo is REUSED from the file uploaded in 3a2 — no new upload,
        #     the image statement points at the same File: page and the
        #     infobox param carries it. Also asserts Special:UpdateCollective
        #     renders the "(replacing existing)" include wording.
        collective_reuse_label = f"Page-flow E2E collective reuse {int(time.time())}"
        collective_reuse = track(flow_collective_logo_reuse(
            op, base, api, collective_reuse_label, resolve("organization", "item"),
            collective_logo_license_qid, f"File:{collective_logo_label}-logo.png"))
        claims, _ = entity_claims(op, api, collective_reuse)
        reuse_image_url = str(first_value(claims, resolve("image", "property")))
        assert collective_logo_label.replace(" ", "_") + "-logo.png" in reuse_image_url, \
            f"{collective_reuse} image statement does not reference the REUSED file " \
            f"({reuse_image_url!r})"
        # No second File page was created for the reuse label.
        r = api_call(op, api, {"action": "query",
                               "titles": f"File:{collective_reuse_label}-logo.png", "format": "json"})
        assert all("missing" in p for p in r["query"]["pages"].values()), \
            f"mode=existing created a NEW File page for the reuse label"
        _, raw = page_get(op, base, "/wiki/" + urllib.parse.quote(
            f"Collective:{collective_reuse_label}") + "?action=raw")
        assert f"logo=[[File:{collective_logo_label.replace(' ', '_')}-logo.png" in raw, \
            f"Collective:{collective_reuse_label} infobox does not carry the reused logo"
        print(f"[ok] AddCollective/manual (mode=existing) -> {collective_reuse}: "
              f"reused File:{collective_logo_label}-logo.png, no new upload")

        # 3a4. Special:UpdateCollective (upload-ux batch): the update page
        #     renders the "(replacing existing)" logo toggle wording.
        url, body = page_get(op, base, f"/wiki/Special:UpdateCollective/{collective_reuse}")
        if "replacing existing" not in body:
            raise FlowError(f"UpdateCollective/{collective_reuse} include toggle lacks the "
                            f"'(replacing existing)' wording")

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
        # The page carries the {{FOSS}} skeleton (raw content check). The
        # template may be invoked WITH a logo parameter on hand-edited pages
        # ({{FOSS|logo=…}} — create-or-skip reuses such pages) — the check is
        # a transclusion check, not an exact-skeleton match.
        _, raw = page_get(op, base, "/wiki/" + urllib.parse.quote(foss_page.replace(" ", "_")) + "?action=raw")
        assert re.search(r"\{\{FOSS(?:\|[^}]*)?\}\}", raw), \
            f"{foss_page} does not transclude {{FOSS}}"
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

        # 3e. AddSoftware/manual + logo upload (issue follow-up + upload
        #     enhancements): a local PNG is uploaded as File:<label>-logo.png
        #     behind the logoInclude toggle, linked from the item via the
        #     image statement, and passed to the FOSS page infobox. The
        #     license is now mandatory and the free-text attribution lands as
        #     item statements.
        image_prop = resolve("image", "property")
        license_prop = resolve("license", "property")
        license_item = resolve("CC BY-SA 4.0", "item")  # preseed license
        logo_label = f"Page-flow E2E logo software {int(time.time())}"
        logo_qid, logo_page = flow_software_logo(op, base, api, logo_label, foss_class, license_item)
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
        assert first_value(claims, license_prop) == license_item, \
            f"{logo_qid} missing the logo license statement"
        assert first_value(claims, resolve("image author", "property")) == "E2E Logo Author", \
            f"{logo_qid} missing the image-author statement"
        assert first_value(claims, resolve("additional license information", "property")) == "E2E license note", \
            f"{logo_qid} missing the additional-license-info statement"
        _, raw = page_get(op, base, "/wiki/" + urllib.parse.quote(logo_page.replace(" ", "_")) + "?action=raw")
        # File DB keys normalize spaces to underscores — match the stored form.
        assert f"logo=[[File:{logo_label.replace(' ', '_')}-logo.png" in raw, \
            f"{logo_page} skeleton does not pass the logo to the infobox; raw: {raw[:200]!r}"
        # Statement-driven infobox cell ({{#item-image:}}) renders the file
        # even without the skeleton param (upload-ux follow-up).
        flow_item_image_renders(op, base, api, logo_qid, "-logo.png")
        print(f"[ok] AddSoftware/manual (logo) -> {logo_qid}: File:{logo_label}-logo.png uploaded, "
              f"image/license/attribution statements + infobox logo on {logo_page}")
        if logo_qid in created:
            created_pages.append(logo_page)

        # 3e2. AddPerson/manual + portrait (upload enhancements): the
        #     portrait section collapsed behind the toggle, local PNG upload
        #     with the mandatory license + author / license info.
        person_portrait_label = f"Page-flow E2E portrait {int(time.time())}"
        portrait_qid, portrait_file = flow_person_portrait(op, base, api, person_portrait_label, license_item)
        portrait_qid = track(portrait_qid)
        r = api_call(op, api, {"action": "query", "titles": portrait_file, "format": "json"})
        assert any("missing" not in p for p in r["query"]["pages"].values()), \
            f"{portrait_file} was not uploaded"
        claims, _ = entity_claims(op, api, portrait_qid)
        assert first_value(claims, license_prop) == license_item, \
            f"{portrait_qid} missing the portrait license statement"
        assert first_value(claims, resolve("image author", "property")) == "E2E Portrait Author", \
            f"{portrait_qid} missing the portrait image-author statement"
        # The Person: page skeleton passes the portrait to the infobox (the
        # AddSoftware/FOSS pattern — upload-ux batch).
        _, raw = page_get(op, base, "/wiki/" + urllib.parse.quote(
            f"Person:{person_portrait_label}") + "?action=raw")
        assert f"portrait=[[File:{person_portrait_label.replace(' ', '_')}-portrait.png" in raw, \
            f"Person:{person_portrait_label} skeleton does not pass the portrait to the infobox; raw: {raw[:200]!r}"
        # Statement-driven infobox cell ({{#item-image:}}) renders the file
        # even without the skeleton param (upload-ux follow-up).
        flow_item_image_renders(op, base, api, portrait_qid, "-portrait.png")
        print(f"[ok] AddPerson/manual (portrait) -> {portrait_qid}: File uploaded with "
              f"license + author statements, infobox portrait on Person:{person_portrait_label}")
        if portrait_qid in created:
            created_pages.append(portrait_file)

        # 3e3. Special:Upload (upload enhancements): the semantic license
        #     combobox + attribution fields + single size note render, and a
        #     real submission creates the sitelinked image item.
        flow_upload_special_form(op, base)
        print("[ok] Special:Upload form: semantic license combobox, author/license-info "
              "fields, single 'Maximum file size' note, validate wiring")
        flow_uploadmeta_module_source(op, base)
        print("[ok] uploadmeta module source: hostname parse (429 fix), 2000-char cap, "
              "dest-name normalization, validate latest-wins + banner dedupe")
        upload_qid = flow_upload_special_item(op, base, api, license_item)
        upload_qid = track(upload_qid)
        print(f"[ok] Special:Upload -> {upload_qid}: image item + statements + "
              f"File-page attribution (item-per-upload)")

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
            for page in created_pages + CREATED_CLASSIC_PAGES:
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
