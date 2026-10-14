#!/usr/bin/env python3
"""E2E for the classic-page section actions (EmbeddableContent).

Exercises the two custom page actions against a live instance, as a logged-in
editor, as a browser would: GET the rendered form, parse its hidden fields
(the CSRF token, form identifier and the target-section carrier), fill the
known inputs and POST it back.

1. `?action=appendsection` renders core's built-in edit surface (form id
   `editform`, `wpTextbox1`, Summary, Save / Show preview / Show changes) with
   NO "This is a minor edit" checkbox, and its save APPENDS the submitted
   wikitext to the END of the page (no heading added, existing content
   preserved);
2. Show preview and Show changes render the assembled page WITHOUT saving;
3. `?action=appendsection&wbsection=<n>` appends BELOW that section, and — with
   a blank Summary — the revision comment is auto-inferred from the topmost
   heading of the content (`/* Heading */`);
4. `?action=deletesection&section=<n>` shows a confirmation page bearing the
   section's wikitext, and its save removes the whole section;
5. the rendered section `[edit]` link carries the `section` number the
   controls and the actions rely on;
6. the controls + Add section action also load on a non-content classic page
   (the `Help:` namespace), which the pre-change scope excluded.

Self-cleaning: the scratch pages (and the item the page→item hook may have
auto-created) are deleted on the way out.

Usage::

    python3 tests/e2e/run_section_actions_e2e.py \\
        --base-url https://wikibase.ronzz.org \\
        --api-url https://wikibase.ronzz.org/api.php \\
        --user SeedBot --password-file seed/.seedbot.pass [--keep]

Exit code 0 = all checks passed. Requires an editor account (edit + delete).

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
from html.parser import HTMLParser

UA = "ronzz-wikibase-section-actions-e2e/1.0"


class FlowError(Exception):
    """Raised when a section-action check fails."""


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


def page_wikitext(op, api: str, title: str) -> tuple[str, str]:
    """The latest revision's (content, comment)."""
    r = api_call(op, api, {
        "action": "query", "prop": "revisions", "titles": title,
        "rvprop": "content|comment", "rvslots": "main", "rvlimit": 1, "format": "json",
    })
    pages = r["query"]["pages"]
    page = next(iter(pages.values()))
    if "revisions" not in page:
        raise FlowError(f"no revisions for {title} (page missing?): {r!r}")
    rev = page["revisions"][0]
    content = rev["slots"]["main"].get("*", "") if "slots" in rev else rev.get("*", "")
    return content, rev.get("comment", "")


def create_page(op, api: str, title: str, text: str) -> None:
    token = csrf_token(op, api)
    r = api_call(op, api, {
        "action": "edit", "title": title, "text": text, "token": token, "createonly": 1,
        "summary": "Section E2E scratch (run_section_actions_e2e.py)", "format": "json",
    }, post=True)
    if r.get("edit", {}).get("result") != "Success":
        raise FlowError(f"creation of {title} failed: {r!r}")


def delete_linked_item(op, api: str, title: str, token: str) -> None:
    """Delete the item the page→item hook auto-created for a new Main-namespace
    page (unlink first — the item delete alone only enqueues an async job)."""
    r = api_call(op, api, {"action": "wbgetentities", "sites": "wikibase",
                           "titles": title, "props": "sitelinks", "format": "json"})
    for eid in r.get("entities", {}):
        if eid.startswith("Q"):
            api_call(op, api, {"action": "wbsetsitelink", "id": eid,
                               "linksite": "wikibase", "linktitle": "", "token": token,
                               "summary": "E2E cleanup (page→item unlink)",
                               "format": "json"}, post=True)
            api_call(op, api, {"action": "delete", "title": f"Item:{eid}", "token": token,
                               "reason": "E2E cleanup (page→item)", "format": "json"},
                     post=True)


def delete_page(op, api: str, title: str) -> None:
    token = csrf_token(op, api)
    delete_linked_item(op, api, title, token)
    api_call(op, api, {
        "action": "delete", "title": title, "token": token,
        "reason": "Section E2E cleanup (run_section_actions_e2e.py)", "format": "json",
    }, post=True)


class _FormParser(HTMLParser):
    """Collects every <form>'s action + submittable field defaults."""

    def __init__(self) -> None:
        super().__init__()
        self.forms: list[tuple[str, dict]] = []
        self._action = ""
        self._fields: dict | None = None
        self._textarea: str | None = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "form":
            self._action = a.get("action", "")
            self._fields = {}
            return
        if self._fields is None:
            return
        if tag == "input":
            name = a.get("name")
            if not name:
                return
            if a.get("type") == "checkbox" and "checked" not in a:
                return
            self._fields[name] = a.get("value", "")
        elif tag == "textarea":
            name = a.get("name")
            if name:
                self._fields[name] = ""
                self._textarea = name
        elif tag == "select":
            name = a.get("name")
            if name:
                self._fields.setdefault(name, "")

    def handle_data(self, data):
        if self._fields is not None and self._textarea:
            self._fields[self._textarea] += data

    def handle_endtag(self, tag):
        if tag == "textarea":
            self._textarea = None
        elif tag == "form" and self._fields is not None:
            self.forms.append((self._action, self._fields))
            self._fields = None
            self._action = ""


def find_form(html: str, required_field: str) -> tuple[str, dict]:
    parser = _FormParser()
    parser.feed(html)
    for action, fields in parser.forms:
        if required_field in fields:
            return action, fields
    raise FlowError(f"no form carrying {required_field!r} found on the page")


def submit_form(op, base: str, path: str, required_field: str, overrides: dict) -> str:
    """GET the form, set the known inputs, POST it back (following redirects);
    returns the final response body (the destination page)."""
    html = page_get(op, base, path)
    action, fields = find_form(html, required_field)
    fields.update(overrides)
    url = urllib.parse.urljoin(base, action)
    req = urllib.request.Request(
        url, data=urllib.parse.urlencode(fields).encode(),
        headers={"User-Agent": UA, "Content-Type": "application/x-www-form-urlencoded"},
    )
    with op.open(req, timeout=90) as resp:
        return resp.read().decode("utf-8", "replace")


def section_number(html: str, anchor: str) -> int:
    """The `section=<n>` carried by the [edit] link of the heading with `id`."""
    m = re.search(r'id="%s".*?section=(\d+)' % re.escape(anchor), html, re.S)
    if not m:
        raise FlowError(f"no section [edit] link found for heading id {anchor!r}")
    return int(m.group(1))


def check_initial_controls(op, base: str, title: str) -> None:
    html = page_get(op, base, rendered_path(title))
    n_first = section_number(html, "First")
    n_second = section_number(html, "Second")
    if n_second <= n_first:
        raise FlowError(f"section numbers not increasing: First={n_first}, Second={n_second}")
    print(f"[ok] section [edit] links carry increasing section numbers ({n_first}, {n_second})")


def rendered_path(title: str) -> str:
    return "/wiki/" + urllib.parse.quote(title.replace(" ", "_"), safe="/:")


def append_save_fields(text: str, summary: str) -> dict:
    """The POST fields the EditPage-based append editor needs to SAVE.

    The editor is core's edit form: `wpSave` saves, while `wpPreview`/`wpDiff`
    take precedence, so a save must blank them (a browser only submits the
    clicked button)."""
    return {
        "wpTextbox1": text,
        "wpSummary": summary,
        "wpSave": "1",
        "wpPreview": "",
        "wpDiff": "",
    }


def assert_edit_parity(html: str) -> None:
    """The append editor is the built-in edit surface, minus minor-edit."""
    present = {
        "form id=editform": 'id="editform"' in html,
        "wpTextbox1": 'name="wpTextbox1"' in html or 'id="wpTextbox1"' in html,
        "wpSummary": 'name="wpSummary"' in html,
        "Save button": 'name="wpSave"' in html,
        "Show preview button": 'name="wpPreview"' in html,
        "Show changes button": 'name="wpDiff"' in html,
    }
    missing = [name for name, ok in present.items() if not ok]
    if missing:
        raise FlowError(f"the append editor is missing built-in edit elements: {missing}")
    if "wpMinoredit" in html:
        raise FlowError("the append editor must NOT render the 'minor edit' checkbox (wpMinoredit)")


def scope_expansion_flow(op, api: str, base: str, keep: bool) -> None:
    """The per-section controls + Add section action load on a non-content
    classic page (Help:), which the pre-change code excluded."""
    stamp = int(time.time())
    title = f"Help:Section actions E2E {stamp}"
    try:
        create_page(op, api, title, "Lead.\n\n== Alpha ==\n\nA.")
        html = page_get(op, base, rendered_path(title))
        if "wbReferenceSnippet" not in html:
            raise FlowError("section controls did not load on a Help: (non-content) page")
        if "ext.embeddableContent.sectionactions" not in html:
            raise FlowError("the section-actions module was not queued on a Help: page")
        if "action=appendsection" not in html:
            raise FlowError("the 'Add section' page action is missing on a Help: page")
        print("[ok] section controls + Add section action load on a non-content classic page (Help:)")
    finally:
        if keep:
            print(f"[keep] leaving scratch page {title}")
        else:
            try:
                delete_page(op, api, title)
                print(f"[ok] cleanup: {title} deleted")
            except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                print(f"[warn] cleanup of {title} failed: {exc}")


def section_action_flow(op, api: str, base: str, keep: bool) -> None:
    stamp = int(time.time())
    title = f"Section actions E2E {stamp}"
    lead = "Lead paragraph.\n\n== First ==\n\nOne.\n\n== Second ==\n\nTwo."
    try:
        create_page(op, api, title, lead)
        check_initial_controls(op, base, title)

        # 0. The append editor IS the built-in edit surface (form id editform,
        #    wpTextbox1, Summary, Save/Show preview/Show changes), minus the
        #    "This is a minor edit" checkbox.
        form_html = page_get(op, base, rendered_path(title) + "?action=appendsection")
        assert_edit_parity(form_html)
        print("[ok] the append editor renders the built-in edit surface (no minor-edit checkbox)")

        # 1. Page-end append (?action=appendsection, no target section).
        submit_form(op, base, rendered_path(title) + "?action=appendsection", "wpTextbox1",
                    append_save_fields("Appended tail.", "E2E tail append"))
        text, _ = page_wikitext(op, api, title)
        if not text.rstrip().endswith("Appended tail."):
            raise FlowError(f"page-end append did not land at the end:\n{text!r}")
        if "== First ==" not in text or "== Second ==" not in text:
            raise FlowError(f"page-end append lost existing content:\n{text!r}")
        print("[ok] action=appendsection appends to the page end, preserving content")

        # 2. Show preview renders the assembled result without saving.
        before, _ = page_wikitext(op, api, title)
        preview_html = submit_form(op, base, rendered_path(title) + "?action=appendsection",
                                   "wpTextbox1",
                                   {"wpTextbox1": "Preview only text.", "wpSummary": "",
                                    "wpPreview": "1", "wpSave": "", "wpDiff": ""})
        if "wikiPreview" not in preview_html or "Preview only text." not in preview_html:
            raise FlowError("Show preview did not render the assembled content")
        after, _ = page_wikitext(op, api, title)
        if after != before:
            raise FlowError("Show preview saved the page (it must not)")
        print("[ok] Show preview renders the result without saving")

        # 3. Show changes renders a diff against the current page.
        diff_html = submit_form(op, base, rendered_path(title) + "?action=appendsection",
                                "wpTextbox1",
                                {"wpTextbox1": "Diff only text.", "wpSummary": "",
                                 "wpDiff": "1", "wpSave": "", "wpPreview": ""})
        if "wikiDiff" not in diff_html or "Diff only text." not in diff_html:
            raise FlowError("Show changes did not render the diff against the current page")
        print("[ok] Show changes renders a diff against the current page")

        # 4. Add section below (?action=appendsection&wbsection=<First>), blank
        #    Summary → auto-inferred `/* Sub */` comment.
        first = section_number(page_get(op, base, rendered_path(title)), "First")
        submit_form(op, base, f"{rendered_path(title)}?action=appendsection&wbsection={first}",
                    "wpTextbox1", append_save_fields("=== Sub ===\n\nSub body.", ""))
        text, comment = page_wikitext(op, api, title)
        sub_at = text.find("=== Sub ===")
        second_at = text.find("== Second ==")
        if sub_at == -1 or second_at == -1 or not sub_at < second_at:
            raise FlowError(f"'Add section below' did not insert below First:\n{text!r}")
        if "One." not in text[:sub_at] or "Two." not in text[second_at:]:
            raise FlowError(f"'Add section below' disturbed the surrounding sections:\n{text!r}")
        if comment != "/* Sub */":
            raise FlowError(f"auto-summary comment {comment!r} != '/* Sub */'")
        print("[ok] Add section below inserts below the section + auto-infers the summary")

        # 5. Delete section (confirmation page shows the text, save removes it).
        second = section_number(page_get(op, base, rendered_path(title)), "Second")
        confirm = page_get(op, base, f"{rendered_path(title)}?action=deletesection&section={second}")
        if "Two." not in confirm:
            raise FlowError("the delete confirmation page does not show the section's wikitext")
        submit_form(op, base, f"{rendered_path(title)}?action=deletesection&section={second}",
                    "wpsection", {"wpsection": str(second)})
        text, _ = page_wikitext(op, api, title)
        if "== Second ==" in text or "Two." in text:
            raise FlowError(f"'Delete section' left the section behind:\n{text!r}")
        if "== First ==" not in text or "One." not in text:
            raise FlowError(f"'Delete section' removed the wrong section:\n{text!r}")
        print("[ok] action=deletesection removes the whole section, keeps the others")
    finally:
        if keep:
            print(f"[keep] leaving scratch page {title}")
        else:
            try:
                delete_page(op, api, title)
                print(f"[ok] cleanup: {title} deleted")
            except Exception as exc:  # noqa: BLE001 — best-effort cleanup
                print(f"[warn] cleanup of {title} failed: {exc}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--api-url", required=True)
    ap.add_argument("--user", required=True)
    ap.add_argument("--password-file", required=True)
    ap.add_argument("--keep", action="store_true", help="keep the scratch page")
    args = ap.parse_args()

    with open(args.password_file, encoding="utf-8") as fh:
        password = fh.read().strip()

    op = make_opener(args.base_url)
    login(op, args.api_url, args.user, password)
    section_action_flow(op, args.api_url, args.base_url, args.keep)
    scope_expansion_flow(op, args.api_url, args.base_url, args.keep)
    print("section-actions E2E: all checks passed")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except FlowError as exc:
        print(f"section-actions E2E FAILED: {exc}")
        raise SystemExit(1)
