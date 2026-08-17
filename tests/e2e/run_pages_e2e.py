#!/usr/bin/env python3
"""E2E for the issue-#7 Special pages + the v1 content form (page flows).

Drives the REAL two-step page flows against a live (login-gated) instance —
`Special:AddPerson` / `Special:AddSource` / `Special:AddCollective`
(search -> select -> create, incl. harvest-on-pick) and the v1
`Special:AddQuotation` form — then verifies the created items carry the
expected class, authority IDs, citation metadata and import-provenance
references.

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


def make_opener() -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


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
    """Runs the two-step Special page flow; returns the created (or reused) item id."""
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
    sel_url = url

    candidates = ooui_options(body, "mw-input-wpcandidates")
    classes = ooui_options(body, "mw-input-wpclass")
    if not candidates or not classes:
        raise FlowError(f"Special:{special} selection page rendered no candidates/classes")
    index = str(min(pick_index, len(candidates) - 1))
    # Honor the inferred default class (the select's pre-selected value).
    cls = ooui_widget(body, "mw-input-wpclass").get("value") or classes[0]["data"]
    token2 = edit_token(body)

    url, body = page_post(op, sel_url, {
        "wpcandidates": index,
        "wpclass": cls,
        "wpEditToken": token2,
        "wpSubmit": "1",
    })
    m = re.search(r"/wiki/Item:(Q\d+)$", url)
    if not m:
        raise FlowError(f"Special:{special} create did not redirect to an item: {url} {find_error(body)}")
    return m.group(1)


def flow_person(op, base: str, api: str, name: str) -> str:
    return flow_search_select_create(op, base, api, "AddPerson", {"wpname": name})


def flow_source(op, base: str, api: str, doi: str) -> str:
    return flow_search_select_create(op, base, api, "AddSource", {"wpdoi": doi})


def flow_collective(op, base: str, api: str, name: str) -> str:
    return flow_search_select_create(op, base, api, "AddCollective", {"wpname": name})


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
    # The form's success response is not reliably a redirect — resolve the
    # created item by its (unique) label instead.
    for _ in range(10):
        qid = resolve_label(op, api, label, "item")
        if qid:
            return qid
        time.sleep(2)
    raise FlowError(f"Special:AddQuotation did not create an item: {url} {find_error(body)}")


def delete_item(op, api: str, qid: str) -> None:
    csrf = api_call(op, api, {"action": "query", "meta": "tokens", "format": "json"})
    token = csrf["query"]["tokens"]["csrftoken"]
    api_call(op, api, {"action": "delete", "title": f"Item:{qid}", "token": token,
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
    parser.add_argument("--doi", default="10.1371/journal.pbio.2001414")
    parser.add_argument("--collective", default="The Beatles")
    args = parser.parse_args()

    base = args.base_url.rstrip("/")
    api = args.api_url or base + "/api.php"
    password = open(args.password_file).read().strip()

    op = make_opener()
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
    wikidata_id_prop = resolve("Wikidata ID", "property")
    doi_prop = resolve("DOI", "property")
    source_url_prop = resolve("source URL", "property")
    agent_classes = {
        resolve("person", "item"),
        resolve("organization", "item"),
        resolve("group of humans", "item"),
    }
    print(f"[ok] vocabulary resolved (instance-of={instance_of}, person={person_class}, "
          f"scholarly article={scholarly_class})")

    created: list[str] = []
    # Monotonic id counter: only items created ABOVE this id were made by
    # this run (create-or-skip reuses older items — those must not be deleted).
    def max_item_id() -> int:
        r = api_call(op, api, {"action": "query", "list": "allpages", "apnamespace": 120,
                               "aplimit": 1, "apdir": "descending", "format": "json"})
        pages = r.get("query", {}).get("allpages", [])
        if not pages:
            return 0
        m = re.search(r"Q(\d+)$", pages[0].get("title", ""))
        return int(m.group(1)) if m else 0

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

        # 2. AddSource — DOI -> Crossref, class inference -> scholarly article,
        #    harvested citation metadata (container/publisher/volume/…)
        source = track(flow_source(op, base, api, args.doi))
        claims, label = entity_claims(op, api, source)
        assert first_value(claims, instance_of) == scholarly_class, \
            f"{source} instance-of != scholarly article ({first_value(claims, instance_of)})"
        assert first_value(claims, doi_prop) == args.doi, f"{source} DOI mismatch"
        assert first_reference_url(claims, doi_prop), f"{source} missing import reference"
        print(f"[ok] AddSource -> {source} ({label[:60]}…): scholarly article, DOI + import reference")

        # 3. AddCollective — harvest class hints; instance-of must be an agent class
        collective = track(flow_collective(op, base, api, args.collective))
        claims, label = entity_claims(op, api, collective)
        assert first_value(claims, instance_of) in agent_classes, \
            f"{collective} instance-of not an agent class ({first_value(claims, instance_of)})"
        assert first_value(claims, wikidata_id_prop), f"{collective} missing Wikidata ID"
        print(f"[ok] AddCollective -> {collective} ({label}): agent class + Wikidata ID")

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
    finally:
        if not args.keep:
            for qid in created:
                try:
                    delete_item(op, api, qid)
                except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                    print(f"  ! cleanup failed for {qid}: {exc}")

    print("\nPage-flow E2E: all checks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
