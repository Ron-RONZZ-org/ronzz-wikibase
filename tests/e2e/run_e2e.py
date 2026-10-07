#!/usr/bin/env python3
"""E2E acceptance + XSS suite for the v1 instance (issue #6, D5).

Runs against a live (dev) instance with D1–D4 deployed and seeded. Three
surfaces, five citation styles, SPARQL, plus the mandatory XSS suite for
EmbeddableContent (§9.4): payload injections (<script>, onerror=,
javascript:) must never survive into rendered fragments.

Usage::

    python3 tests/e2e/run_e2e.py check --base-url https://wikibase.ronzz.org \\
        --quote Q5 --code Q6 --math Q7 --instance-of P1 --quotation-class Q1

    python3 tests/e2e/run_e2e.py xss --api-url ... --user 'User@bot' --password '***'

Exit code 0 = all checks passed.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent.parent))
from seed.wikibase_api import WikibaseApi  # noqa: E402

XSS_INJECTIONS = [
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    'javascript:alert(1)',
    '<svg onload=alert(1)>',
    '"><script>alert(1)</script>',
]
XSS_LABEL = 'XSS injection test item'


class CheckFailed(Exception):
    pass


def http_get(url: str, timeout: int = 60) -> tuple[int, bytes, str]:
    request = urllib.request.Request(url, headers={"User-Agent": "ronzz-wikibase-e2e/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as resp:
            return resp.status, resp.read(), resp.headers.get("Content-Type", "")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read(), exc.headers.get("Content-Type", "")
    except urllib.error.URLError as exc:
        raise CheckFailed(f"cannot reach {url}: {exc.reason}") from exc


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise CheckFailed(message)


def check(args: argparse.Namespace) -> int:
    api = args.api_url
    base = args.base_url
    sparql = args.sparql_url
    failures = []

    def run(name: str, fn) -> None:
        try:
            fn()
            print(f"  [ok] {name}")
        except CheckFailed as exc:
            failures.append(name)
            print(f"  [FAIL] {name}: {exc}")

    def check_addsource_fields(api: str) -> None:
        """The action=addsource-fields discovery endpoint — anonymous. The
        regression the field maps exist for: every class must expose authors,
        and the child classes must require parent."""
        params = {"action": "addsource-fields", "format": "json", "formatversion": "2"}
        status, body, _ = http_get(f"{api}?{urllib.parse.urlencode(params)}")
        expect(status == 200, f"addsource-fields: HTTP {status}")
        payload = json.loads(body.decode("utf-8", "replace"))
        fields = payload.get("sourcefields", {})
        expect("classes" in fields and "propertyIds" in fields,
               f"addsource-fields: unexpected shape: {payload.get('error')!r}")
        by_key = {c["classKey"]: c for c in fields["classes"]}
        for class_key in ("book", "website", "webpage", "youtube-channel", "youtube-video"):
            expect(class_key in by_key, f"addsource-fields: class {class_key} missing")
            expect("authors" in by_key[class_key]["fields"],
                   f"addsource-fields: {class_key} must expose authors (the drift regression)")
        expect("parent" in by_key["webpage"]["requiredOnCreate"],
               "addsource-fields: webpage must require a website parent")
        expect("propertyIds" in fields and "instanceOf" in fields["propertyIds"],
               "addsource-fields: missing property id map")

    def check_field_contract_parity() -> None:
        """The canonical-contract guard: every field a `<flow>-fields`
        discovery endpoint advertises must be an accepted parameter of the
        flow's WRITE module (action=paraminfo). This is the drift class the
        field maps and the contract artifact exist to stop — a field
        advertised but silently dropped by the write module (the math `note`,
        the fictional-character `alias`). The API modules derive their params
        from the maps, so the discovery contract and the write path cannot
        disagree."""
        flows = [
            ("addsource-fields", "sourcefields", "classes", "addsource"),
            ("addspecialcontent-fields", "contentfields", "kinds", "addspecialcontent"),
            ("addsemanticentity-fields", "semanticfields", "kinds", "addsemanticentity"),
        ]
        for discovery, envelope, group_key, module in flows:
            discovery_params = {"action": discovery, "format": "json", "formatversion": "2"}
            status, body, _ = http_get(f"{api}?{urllib.parse.urlencode(discovery_params)}")
            expect(status == 200, f"{discovery}: HTTP {status}")
            payload = json.loads(body.decode("utf-8", "replace"))
            group = payload.get(envelope, {}).get(group_key, [])
            expect(bool(group), f"{discovery}: no {group_key}: {payload.get('error')!r}")

            paraminfo_params = {"action": "paraminfo", "modules": module,
                                "format": "json", "formatversion": "2"}
            status, body, _ = http_get(f"{api}?{urllib.parse.urlencode(paraminfo_params)}")
            expect(status == 200, f"paraminfo({module}): HTTP {status}")
            modules = json.loads(body.decode("utf-8", "replace")).get("paraminfo", {}).get("modules", [])
            expect(bool(modules), f"paraminfo({module}): module not found")
            accepted = {p["name"] for p in modules[0].get("parameters", [])}

            advertised: set[str] = set()
            for entry in group:
                advertised.update(entry.get("fields", []))
            missing = sorted(advertised - accepted)
            expect(not missing,
                   f"{module}: discovery advertises field(s) the write module does not accept: {missing}")

    def embed_html(entity: str, **extra) -> str:
        params = {"action": "embed", "entity": entity, "output": "html", "format": "json", **extra}
        status, body, _ = http_get(f"{api}?{urllib.parse.urlencode(params)}")
        expect(status == 200, f"embed {entity}: HTTP {status}")
        payload = json.loads(body.decode("utf-8", "replace"))
        expect("embed" in payload, f"embed {entity}: API error: {payload.get('error')!r}")
        return payload["embed"]["html"]

    def check_embed_surfaces() -> None:
        for entity in (args.quote, args.code, args.math):
            html = embed_html(entity)
            expect(html.strip() != "", f"empty fragment for {entity}")
            # Special:Embed — the canonical /embed/QN path is an nginx rewrite
            # of this page (ops-level, validated on production). Must serve the
            # BARE fragment (no wiki skin chrome) — that is what an iframe on
            # a third-party site shows.
            status, body, ctype = http_get(f"{base}/wiki/Special:Embed/{entity}")
            page = body.decode("utf-8", "replace")
            expect(status == 200, f"Special:Embed/{entity}: HTTP {status}")
            expect(
                'class="wb-embed' in page and 'id="mw-head"' not in page,
                f"Special:Embed/{entity}: expected a bare fragment, got wiki chrome",
            )
            status, _, ctype = http_get(
                f"{base}/wiki/Special:Embed/oembed?url={urllib.parse.quote(f'{base}/wiki/Item:{entity}')}"
            )
            expect(status == 200 and "json" in ctype, f"oembed for {entity}: HTTP {status}")

    def check_embed_negotiation() -> None:
        html_fr = embed_html(args.quote, lang="fr")
        html_eo = embed_html(args.quote, lang="eo")
        expect(html_fr != html_eo or "lang=" in html_fr, "language negotiation did not vary output")
        # lang=all: every available payload language is rendered (multi-lang
        # embed), each blockquote carrying its own lang attribute.
        html_all = embed_html(args.quote, lang="all")
        expect(
            html_all.count('class="wb-embed') >= 2 and 'lang="fr"' in html_all and 'lang="eo"' in html_all,
            "lang=all did not render multiple languages",
        )

    def check_citation_styles() -> None:
        for style in ("json", "apa", "vancouver", "bibtex", "ris"):
            params = {"action": "citation", "entity": args.quote, "style": style, "output": "text", "format": "json"}
            status, body, ctype = http_get(f"{api}?{urllib.parse.urlencode(params)}")
            expect(status == 200, f"citation {style}: HTTP {status}")
            text = body.decode("utf-8", "replace")
            # Fail on API error payloads for EVERY style (a fatalling
            # formatter used to slip through as HTTP 200 + non-empty text).
            try:
                payload = json.loads(text)
            except json.JSONDecodeError as exc:
                raise CheckFailed(
                    f"citation {style}: not JSON (HTTP {status}): {text[:300]!r}"
                ) from exc
            if "error" in payload:
                raise CheckFailed(f"citation {style}: API error: {payload['error']!r}")
            if style == "json":
                expect(payload.get("citation", {}).get("type"), "json citation missing type")
            else:
                expect(
                    isinstance(payload.get("citation"), str) and payload["citation"].strip() != "",
                    f"citation {style}: empty output",
                )

    def check_page_citation() -> None:
        """A classic content page is cited as the PAGE itself — type webpage,
        title, canonical URL and last-revision date (the page's 'last
        edition') — not via a sitelinked item."""
        page = "Main Page"
        for style in ("json", "apa", "vancouver"):
            params = {
                "action": "citation", "page": page, "style": style,
                "output": "text", "format": "json",
            }
            status, body, _ = http_get(f"{api}?{urllib.parse.urlencode(params)}")
            expect(status == 200, f"page citation {style}: HTTP {status}")
            payload = json.loads(body.decode("utf-8", "replace"))
            expect("error" not in payload, f"page citation {style}: {payload.get('error')!r}")
            if style == "json":
                csl = payload["citation"]
                expect(csl.get("type") == "webpage",
                       f"page citation json: type {csl.get('type')!r}")
                expect(csl.get("title") == page,
                       f"page citation json: title {csl.get('title')!r}")
                expect("URL" in csl, "page citation json: missing URL")
                expect("issued" in csl, "page citation json: missing issued (last edition)")
                expect("author" not in csl, "page citation json: must have no author")
            else:
                citation = payload.get("citation", "")
                expect(isinstance(citation, str) and citation.strip() != "",
                       f"page citation {style}: empty output")
                expect("http" in citation,
                       f"page citation {style}: URL missing from the text: {citation!r}")

    def check_sparql() -> None:
        # Prefixes must be declared explicitly: the store defaults for
        # wd:/wdt: point at wikidata.org, not at this instance.
        query = (
            "PREFIX wd: <" + args.concept_uri + "/entity/> PREFIX wdt: <"
            + args.concept_uri + "/prop/direct/> "
            + "SELECT ?item WHERE { ?item wdt:" + args.instance_of + " wd:" + args.quotation_class + " } LIMIT 10"
        )

        def once() -> bool:
            status, body, ctype = http_get(f"{sparql}?query={urllib.parse.quote(query)}&format=json")
            if status != 200 or "json" not in ctype:
                return False
            return args.quote in body.decode("utf-8", "replace")

        deadline = time.time() + args.sparql_wait
        while not once():
            if time.time() >= deadline:
                raise CheckFailed(
                    f"dogfood quotation missing from SPARQL within {args.sparql_wait}s "
                    "(WDQS updater sync?)"
                )
            time.sleep(10)

    def check_entity_creation_pages() -> None:
        # Issue #7: the three external-authority pages are registered and
        # login-gated (anonymous must not trigger server-side fetches).
        # The login redirect points at the wiki's canonical hostname
        # (unresolvable from the runner), so redirects are NOT followed.
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                return None

        opener = urllib.request.build_opener(NoRedirect())
        for page in ("AddPerson", "AddSource", "AddCollective"):
            url = f"{base}/wiki/Special:{page}"
            request = urllib.request.Request(url, headers={"User-Agent": "ronzz-wikibase-e2e/1.0"})
            try:
                with opener.open(request, timeout=60) as resp:
                    status = resp.status
            except urllib.error.HTTPError as exc:
                status = exc.code
            except urllib.error.URLError as exc:
                raise CheckFailed(f"cannot reach {url}: {exc.reason}") from exc
            expect(
                status == 302 or status == 200,
                f"Special:{page}: expected login redirect (302) or render (200), got HTTP {status}",
            )

    def check_specialpages_listing() -> None:
        # Regression (issue #11): all EmbeddableContent special pages must be
        # listed on Special:SpecialPages under their i18n description, and the
        # non-login-gated ones must render a non-empty page title.
        pages = {
            "Embed": "Embed",
            "AddQuotation": "Add quotation",
            "AddCodeSnippet": "Add code snippet",
            "AddMath": "Add mathematical expression",
            "AddPerson": "Add person",
            "AddSource": "Add source",
            "AddCollective": "Add collective",
            "QuotationsOf": "Quotations of a source",
            "ChildItemsOf": "Child items of a source",
        }
        status, body, _ = http_get(f"{base}/wiki/Special:SpecialPages")
        html = body.decode("utf-8", "replace")
        expect(status == 200, f"Special:SpecialPages: HTTP {status}")
        for page, description in pages.items():
            marker = f'href="/wiki/Special:{page}" title="Special:{page}">{description}</a>'
            expect(
                marker in html,
                f"Special:SpecialPages: {page} ('{description}') not listed",
            )
        # Non-login-gated pages: non-empty <h1>/<title> (they used to render
        # an empty title because execute() never called setHeaders()).
        for page in ("AddQuotation", "AddCodeSnippet", "AddMath"):
            status, body, _ = http_get(f"{base}/wiki/Special:{page}")
            html = body.decode("utf-8", "replace")
            expect(status == 200, f"Special:{page}: HTTP {status}")
            m = re.search(r"<h1[^>]*>(.*?)</h1>", html, re.S)
            heading = re.sub(r"<[^>]+>", "", m.group(1)).strip() if m else ""
            expect(
                heading != "",
                f"Special:{page}: empty page title (setHeaders/getDescription regression)",
            )

    def check_embed_error_paths() -> None:
        # Regression (issue #11): Special:Embed with no/invalid subpage must
        # render an error page (200), not throw (undefined showErrorPage).
        for path in ("Special:Embed", "Special:Embed/NotAnId"):
            status, body, _ = http_get(f"{base}/wiki/{path}")
            html = body.decode("utf-8", "replace")
            expect(status == 200, f"{path}: HTTP {status} (was 500)")
            expect(
                "Invalid entity id" in html or "Invalid" in html,
                f"{path}: expected an error message, got: {html[:200]!r}",
            )
        # Valid entity: still renders the embed.
        status, body, _ = http_get(f"{base}/wiki/Special:Embed/{args.quote}")
        expect(status == 200, f"Special:Embed/{args.quote}: HTTP {status}")

    def check_entitysearch_fulltext() -> None:
        """action=entitysearch (the combobox fulltext search): a CONTAINS
        match must find labels that wbsearchentities' exact/prefix search
        never could — "AGPL" inside "GNU AGPL-3.0", a mid-word fragment of
        the dogfood person "Ada Lovelace". Read-only, anonymous."""
        def search(text: str) -> list[dict]:
            params = {"action": "entitysearch", "search": text,
                      "language": "en", "limit": 10, "format": "json"}
            status, body, _ = http_get(f"{api}?{urllib.parse.urlencode(params)}")
            expect(status == 200, f"entitysearch {text!r}: HTTP {status}")
            payload = json.loads(body.decode("utf-8", "replace"))
            expect("search" in payload, f"entitysearch {text!r}: API error: {payload.get('error')!r}")
            return payload["search"]

        agpl = search("AGPL")
        expect(any("AGPL" in (r.get("label") or "") for r in agpl),
               f"entitysearch 'AGPL' did not find the AGPL license (labels: "
               f"{[r.get('label') for r in agpl]})")
        lovelace = search("ovelace")  # mid-word fragment — prefix can never match it
        expect(any("Ada Lovelace" == r.get("label") for r in lovelace),
               f"entitysearch 'ovelace' did not find Ada Lovelace (labels: "
               f"{[r.get('label') for r in lovelace]})")
        einstein = search("einstein")  # lowercase variant of a mid-name fragment
        expect(isinstance(einstein, list), "entitysearch 'einstein' must not error")
        # The wbsearchentities prefix search CANNOT find these — the module
        # exists precisely to cover that gap (informational, not a gate).
        print(f"      (wbsearchentities prefix would miss 'AGPL'/'ovelace' — "
              f"fulltext found {len(agpl)}/{len(lovelace)} hits)")

    run("embed surfaces (api + /embed/ + oEmbed)", check_embed_surfaces)
    run("language negotiation (?lang=)", check_embed_negotiation)
    run("citation styles (json/apa/vancouver/bibtex/ris)", check_citation_styles)
    run("page citation (classic content page)", check_page_citation)
    run("entitysearch fulltext (combobox search)", check_entitysearch_fulltext)
    run("sparql instance-of check", check_sparql)
    run("entity-creation pages registered + login-gated (issue #7)", check_entity_creation_pages)
    run("special pages listed on Special:SpecialPages + non-empty titles (issue #11)", check_specialpages_listing)
    run("Special:Embed error paths render 200 (issue #11)", check_embed_error_paths)
    run("addsource-fields contract (webpage exposes authors — the drift regression)", lambda: check_addsource_fields(api))
    run("field-contract parity (-fields ⊆ write-module params)", check_field_contract_parity)

    if args.allow_sparql_fail and "sparql instance-of check" in failures:
        print(
            "\n[warning] sparql check failed but --allow-sparql-fail is set: "
            "the WDQS 0.3.156 updater's backoff polling skips changes created "
            "mid-catch-up on a fresh instance; on a caught-up (production) "
            "instance normal polling picks up seeded edits."
        )
        failures.remove("sparql instance-of check")

    if failures:
        print(f"\nE2E FAILED: {len(failures)} check(s): {', '.join(failures)}")
        return 1
    print("\nE2E: all checks passed.")
    return 0


def xss(args: argparse.Namespace) -> int:
    api = WikibaseApi(args.api_url, args.user, args.password)
    api.login()

    # Create (or reuse) an XSS test item — one quotation whose payload carries
    # every injection. Idempotent by label (skip-existing-label).
    existing = api.search_entities(XSS_LABEL, "item", "en")
    if existing:
        item_id = existing[0]["id"]
        print(f"reusing XSS item {item_id}")
    else:
        payload = " | ".join(XSS_INJECTIONS)
        item_id = api.create_item(
            {lang: XSS_LABEL for lang in ("en", "fr", "eo")},
            {lang: "XSS test item" for lang in ("en", "fr", "eo")},
            "E2E XSS suite: create test item",
        )
        # instance-of + payload — resolve property ids by label via the map.
        content_text = api.search_entities("content text", "property", "en")[0]["id"]
        instance_of = api.search_entities("instance of", "property", "en")[0]["id"]
        quotation_class = api.search_entities("quotation content", "item", "en")[0]["id"]
        api.add_claims(
            item_id,
            {
                instance_of: [
                    {
                        "mainsnak": {
                            "snaktype": "value",
                            "property": instance_of,
                            "datavalue": {
                                "value": {
                                    "entity-type": "item",
                                    "numeric-id": int(quotation_class[1:]),
                                    "id": quotation_class,
                                },
                                "type": "wikibase-entityid",
                            },
                        },
                        "type": "statement",
                        "rank": "normal",
                    }
                ],
                content_text: [
                    {
                        "mainsnak": {
                            "snaktype": "value",
                            "property": content_text,
                            "datavalue": {
                                "value": {"text": payload, "language": "en"},
                                "type": "monolingualtext",
                            },
                        },
                        "type": "statement",
                        "rank": "normal",
                    }
                ],
            },
            "E2E XSS suite: populate payload",
        )
        print(f"created XSS item {item_id}")

    # Fetch the rendered fragment through both surfaces. The API surface uses
    # the module's `output=html` — the `format` API parameter is the RESPONSE
    # format, and `format=html` is invalid (it returned an error page, making
    # the injection assertions vacuous).
    failures = []
    api_params = { "action": "embed", "entity": item_id, "output": "html", "format": "json" }
    api_status, api_body, _ = http_get(f"{args.api_url}?{urllib.parse.urlencode(api_params)}")
    surfaces = []
    if api_status == 200:
        try:
            api_payload = json.loads(api_body.decode("utf-8", "replace"))
        except json.JSONDecodeError:
            api_payload = {}
        surfaces.append(("api", api_payload.get("embed", {}).get("html", "")))
    else:
        failures.append(f"api: HTTP {api_status}")
    page_status, page_body, _ = http_get(f"{args.base_url}/wiki/Special:Embed/{item_id}")
    if page_status == 200:
        surfaces.append(("page", page_body.decode("utf-8", "replace")))
    else:
        failures.append(f"page: HTTP {page_status}")

    for label, html in surfaces:
        for injection in XSS_INJECTIONS:
            # Real XSS semantics: markup-like injections (tags, event
            # handlers) must be escaped away entirely; a bare `javascript:`
            # as *escaped text* is inert — only an attribute-context
            # occurrence (inside a tag) is dangerous.
            if injection.startswith("<") or "onerror" in injection:
                if injection in html:
                    failures.append(f"{label}: raw injection survived: {injection!r}")
            elif injection.startswith("javascript:"):
                if re.search(r"<[^>]*javascript:", html):
                    failures.append(f"{label}: javascript: survived in an attribute: {injection!r}")

    if failures:
        print(f"XSS FAILED ({len(failures)}):")
        for failure in failures:
            print(f"  - {failure}")
        print(f"  (test item kept for inspection: {item_id})")
        return 1

    print(f"XSS: all {len(XSS_INJECTIONS)} injections escaped on both surfaces (item {item_id}).")
    return 0


RICH_QUOTE_LABEL = "E2E rich-content quotation"
RICH_MATH_LABEL = "E2E math-note item"
RICH_CACHE_LABEL = "E2E embed-cache refresh item"
NOTE_MARKER = "E2E-NOTE-MARKER"
QUOTE_MARKER = "E2E-QUOTE-MARKER"


def _parse_wikitext(api_url: str, text: str) -> str:
    """Renders wikitext through action=parse (anonymous) and returns the HTML."""
    params = {
        "action": "parse",
        "contentmodel": "wikitext",
        "prop": "text",
        "format": "json",
        "text": text,
    }
    status, body, _ = http_get(f"{api_url}?{urllib.parse.urlencode(params)}")
    expect(status == 200, f"action=parse HTTP {status} for {text!r}")
    payload = json.loads(body.decode("utf-8", "replace"))
    if "error" in payload:
        raise CheckFailed(f"action=parse error for {text!r}: {payload['error']!r}")
    return payload.get("parse", {}).get("text", {}).get("*", "")


def rich(args: argparse.Namespace) -> int:
    """Rich-content E2E (self-cleaning): the quotation payload and the math
    note are full wikitext — links/emphasis/$…$ parse on the embed surfaces
    and via {{#content:}}, and {{#content:Q|noNote}} hides the note. Also
    asserts an injection in the note never survives."""
    api = WikibaseApi(args.api_url, args.user, args.password)
    api.login()

    def property_id(label: str) -> str:
        hits = api.search_entities(label, "property", "en")
        expect(bool(hits), f"property {label!r} not found (re-seed required?)")
        return hits[0]["id"]

    def class_id(label: str) -> str:
        hits = api.search_entities(label, "item", "en")
        expect(bool(hits), f"class {label!r} not found (re-seed required?)")
        return hits[0]["id"]

    quote_id = None
    math_id = None
    cache_math_id = None
    try:
        # Purge leftovers from an interrupted run (idempotent re-runs).
        for label in (RICH_QUOTE_LABEL, RICH_MATH_LABEL, RICH_CACHE_LABEL):
            for hit in api.search_entities(label, "item", "en"):
                if hit.get("label") == label:
                    try:
                        api.delete_item(hit["id"], "E2E rich suite: stale cleanup")
                    except WikibaseApiError:
                        pass

        instance_of = property_id("instance of")
        content_text = property_id("content text")
        translation_property = property_id("translation")
        latex_source = property_id("LaTeX source")
        note_property = property_id("note")
        source_property = property_id("source")
        attributed_to_property = property_id("attributed to")
        # The source item (the math item below) also carries a URL + a
        # publication year, so the citation can read them from the SOURCE.
        url_property = property_id("URL")
        date_property = property_id("date")
        quotation_class = class_id("quotation content")
        math_class = class_id("mathematical expression")

        def item_claim(prop: str, value: dict) -> list[dict]:
            return [ {
                "mainsnak": { "snaktype": "value", "property": prop, "datavalue": value },
                "type": "statement",
                "rank": "normal",
            } ]

        def item_value(item_id: str) -> dict:
            return {
                "value": {
                    "entity-type": "item",
                    "numeric-id": int(item_id[1:]),
                    "id": item_id,
                },
                "type": "wikibase-entityid",
            }

        quote_id = api.create_item(
            { "en": RICH_QUOTE_LABEL }, { "en": "E2E rich content" },
            "E2E rich suite: create quotation",
        )
        api.add_claims(quote_id, {
            instance_of: item_claim(instance_of, item_value(quotation_class)),
            content_text: item_claim(content_text, {
                "value": {
                    "text": f"''italic'' [[Main Page]] {QUOTE_MARKER} <script>alert(1)</script>",
                    "language": "en",
                },
                "type": "monolingualtext",
            }),
            # The added translation (the "Add translation" model): a separate
            # monolingual `translation` claim in French. It carries the same
            # rich markers as the original so the embed surface renders them
            # whichever language the config fallback order negotiates
            # (fr, en, eo).
            translation_property: item_claim(translation_property, {
                "value": {
                    "text": f"''italic'' [[Main Page]] TRADUCTION {QUOTE_MARKER}",
                    "language": "fr",
                },
                "type": "monolingualtext",
            }),
        }, "E2E rich suite: quotation claims")

        math_id = api.create_item(
            { "en": RICH_MATH_LABEL }, { "en": "E2E math note" },
            "E2E rich suite: create math item",
        )
        api.add_claims(math_id, {
            instance_of: item_claim(instance_of, item_value(math_class)),
            latex_source: item_claim(latex_source, { "value": "x^2", "type": "string" }),
            note_property: item_claim(note_property, {
                "value": f"{NOTE_MARKER} $x$ <script>alert(1)</script>",
                "type": "string",
            }),
            # Source-level citation metadata read by the citation (the source
            # of the quotation below): the URL property + the publication year.
            url_property: item_claim(url_property, {
                "value": "https://example.org/e2e-source",
                "type": "string",
            }),
            date_property: item_claim(date_property, {
                "value": {
                    "time": "+2013-01-01T00:00:00Z", "timezone": 0, "before": 0, "after": 0,
                    "precision": 9, "calendarmodel": "http://www.wikidata.org/entity/Q1985727",
                },
                "type": "time",
            }),
        }, "E2E rich suite: math claims")

        # The quotation cites the math item as its source, so it shows up on
        # Special:QuotationsOf/<math_id> (the listing surface), and is
        # ATTRIBUTED to it too, so the author listing
        # (Special:QuotationsOf/<math_id>/author) and {{#quotations-by:}}
        # have data to find.
        api.add_claims(quote_id, {
            source_property: item_claim(source_property, item_value(math_id)),
            attributed_to_property: item_claim(attributed_to_property, item_value(math_id)),
        }, "E2E rich suite: quotation source + attribution")

        # A scratch math item for the embed-cache regression (below): created
        # WITHOUT a note so a later note-adding edit changes the rendered
        # fragment.
        cache_math_id = api.create_item(
            { "en": RICH_CACHE_LABEL }, { "en": "E2E embed cache refresh" },
            "E2E rich suite: create embed-cache item",
        )
        api.add_claims(cache_math_id, {
            instance_of: item_claim(instance_of, item_value(math_class)),
            latex_source: item_claim(latex_source, { "value": "y^3", "type": "string" }),
        }, "E2E rich suite: embed-cache claims")

        failures: list[str] = []

        def run_check(name: str, fn) -> None:
            try:
                fn()
                print(f"  [ok] {name}")
            except CheckFailed as exc:
                failures.append(name)
                print(f"  [FAIL] {name}: {exc}")

        def embed(entity: str) -> str:
            params = { "action": "embed", "entity": entity, "output": "html", "format": "json" }
            status, body, _ = http_get(f"{args.api_url}?{urllib.parse.urlencode(params)}")
            expect(status == 200, f"embed {entity}: HTTP {status}")
            payload = json.loads(body.decode("utf-8", "replace"))
            expect("embed" in payload, f"embed {entity}: API error: {payload.get('error')!r}")
            return payload["embed"]["html"]

        def quotation_is_rich() -> None:
            html = embed(quote_id)
            expect(QUOTE_MARKER in html, "quotation marker missing")
            expect("<i>italic</i>" in html, f"quotation wikitext not parsed: {html[:200]!r}")
            expect("Main_Page" in html, "quotation [[Main Page]] link missing")

        def quotation_rich_in_parser_function() -> None:
            html = _parse_wikitext(args.api_url, f"{{{{#content:{quote_id}}}}}")
            expect("<i>italic</i>" in html, "{{#content:}} quotation wikitext not parsed")
            # {{#content:}} is the UNFORMATTED path: no .wb-embed chrome
            # (the reported broken left border on an inline math span) and no
            # auto blockquote — the consumer page wraps it.
            expect("wb-embed" not in html, "{{#content:}} quotation still carries the embed chrome")

        def math_note_renders() -> None:
            html = embed(math_id)
            expect("wb-embed-note" in html, "math note wrapper missing")
            expect(NOTE_MARKER in html, "math note marker missing")

        def no_note_suppresses_it() -> None:
            with_note = _parse_wikitext(args.api_url, f"{{{{#content:{math_id}}}}}")
            expect(NOTE_MARKER in with_note, "{{#content:Q}} did not render the note")
            without = _parse_wikitext(args.api_url, f"{{{{#content:{math_id}|noNote}}}}")
            expect(NOTE_MARKER not in without, "{{#content:Q|noNote}} still rendered the note")
            # The math expands to plain display-math wikitext ($$…$$), never
            # the framed .wb-embed KaTeX span.
            expect("x^2" in without, "{{#content:Q|noNote}} dropped the math")
            expect("wb-embed" not in without, "{{#content:}} still carries the .wb-embed embed chrome")

        def quotation_translations_render() -> None:
            # {{#content:Q|fr|eo}}: the original + the fr translation block,
            # then the "eo translation not found" fallback (no eo claim).
            html = _parse_wikitext(args.api_url, f"{{{{#content:{quote_id}|fr|eo}}}}")
            expect("fr translation:" in html, "{{#content:Q|fr}} translation header missing")
            expect("TRADUCTION" in html, "{{#content:Q|fr}} translated text missing")
            expect(
                "eo translation not found" in html,
                "{{#content:Q|eo}} missing-translation fallback absent",
            )
            # No language arguments keeps the original-only rendering.
            plain = _parse_wikitext(args.api_url, f"{{{{#content:{quote_id}}}}}")
            expect("TRADUCTION" not in plain, "{{#content:Q}} leaked a translation block")

        def injections_do_not_survive() -> None:
            # Check the EXACT injection (the wiki pages carry their own
            # ResourceLoader <script> tags, so a bare "<script>" substring is
            # not a leak signal on the full-page surfaces).
            injection = "<script>alert(1)</script>"
            for label, html in [
                ("math embed", embed(math_id)),
                ("math parse", _parse_wikitext(args.api_url, f"{{{{#content:{math_id}}}}}")),
                ("quotation embed", embed(quote_id)),
                ("quotation parse", _parse_wikitext(args.api_url, f"{{{{#content:{quote_id}}}}}")),
            ]:
                expect(injection not in html, f"{label}: raw injection survived")

        def quotation_listing_renders_rich() -> None:
            # Special:QuotationsOf is a live WDQS listing — the updater is
            # eventually consistent, so poll for the fresh quotation.
            url = f"{args.base_url}/wiki/Special:QuotationsOf/{math_id}"
            deadline = time.time() + 180
            html = ""
            while True:
                status, body, _ = http_get(url)
                expect(status == 200, f"QuotationsOf HTTP {status}")
                html = body.decode("utf-8", "replace")
                if QUOTE_MARKER in html:
                    break
                if time.time() >= deadline:
                    raise CheckFailed(
                        "quotation did not appear on Special:QuotationsOf within 180s (WDQS sync?)"
                    )
                time.sleep(5)
            expect("<i>italic</i>" in html, "QuotationsOf did not render the quotation wikitext")
            expect("<script>alert(1)</script>" not in html, "QuotationsOf leaked the raw injection")

        def statement_row_shows_data_and_hides_empty() -> None:
            # {{#statement-row:Label|property|Qid}} — a complete table row only
            # when the item carries data for the property (the empty infobox
            # row bug). `instance of` is present; `DOI` is not.
            with_data = _parse_wikitext(
                args.api_url,
                '{| class="wikitable"\n{{#statement-row:Instance of|instance of|' + math_id + '}}\n|}',
            )
            expect("Instance of" in with_data, "{{#statement-row}} dropped a row that has data")
            without_data = _parse_wikitext(
                args.api_url,
                '{| class="wikitable"\n{{#statement-row:DOI|DOI|' + math_id + '}}\n|}',
            )
            expect("DOI" not in without_data, "{{#statement-row}} rendered an empty row")

        def citation_reads_source_url_and_last_edition_year() -> None:
            # The quotation's SOURCE (the math item) carries a URL + a 2013
            # date: the citation must include both (the webpage guideline +
            # the source's last-edition year), read from the SOURCE item.
            params = {
                "action": "citation", "entity": quote_id, "style": "apa",
                "output": "text", "format": "json",
            }
            status, body, _ = http_get(f"{args.api_url}?{urllib.parse.urlencode(params)}")
            expect(status == 200, f"citation for the quotation: HTTP {status}")
            payload = json.loads(body.decode("utf-8", "replace"))
            expect("error" not in payload, f"citation error: {payload.get('error')!r}")
            text = payload.get("citation", "")
            expect("https://example.org/e2e-source" in text,
                   f"source URL missing from the citation: {text!r}")
            expect("2013" in text, f"source last-edition year missing from the citation: {text!r}")

        def preview_quotation_translation_and_attribution() -> None:
            # action=embed&preview=1 (the Item-page / Add* popup variant): a
            # quotation renders its ORIGINAL, the reader-language translation,
            # and the attribution line — not the single negotiated language.
            params = {
                "action": "embed", "entity": quote_id, "output": "html",
                "format": "json", "preview": "1", "lang": "fr",
            }
            status, body, _ = http_get(f"{args.api_url}?{urllib.parse.urlencode(params)}")
            expect(status == 200, f"embed preview: HTTP {status}")
            payload = json.loads(body.decode("utf-8", "replace"))
            expect("embed" in payload, f"embed preview error: {payload.get('error')!r}")
            html = payload["embed"]["html"]
            expect(QUOTE_MARKER in html, "preview lost the original quotation")
            expect("fr translation:" in html, "preview missing the reader-language translation block")
            expect("TRADUCTION" in html, "preview missing the translated text")
            expect("E2E math-note item" in html, "preview missing the attribution line")
            # The framed embed surface is unchanged (no translation block).
            expect("fr translation:" not in embed(quote_id),
                   "the framed embed surface leaked the preview translation block")

        def math_note_has_no_embed_chrome() -> None:
            # The math note is plain rich wikitext below the expression — it
            # must not carry the .wb-embed border/background (the stray line).
            html = embed(math_id)
            expect('class="wb-embed-note"' in html, "math note wrapper class changed")
            expect('class="wb-embed wb-embed-note"' not in html,
                   "math note still carries the .wb-embed chrome")

        def quotations_by_row_and_listing() -> None:
            # WDQS is warm (the source listing check polled until the
            # quotation appeared), so the author predicate sees it too.
            row = _parse_wikitext(
                args.api_url,
                '{| class="wikitable"\n{{#quotations-by:' + math_id + '}}\n|}',
            )
            expect("Quotations" in row, "{{#quotations-by}} did not render the row")
            url = f"{args.base_url}/wiki/Special:QuotationsOf/{math_id}/author"
            status, body, _ = http_get(url)
            expect(status == 200, f"QuotationsOf author-mode HTTP {status}")
            html = body.decode("utf-8", "replace")
            expect(QUOTE_MARKER in html, "the author-mode listing did not show the quotation")

        def embed_reflects_latest_revision() -> None:
            # Q2039 regression: action=embed with no `rev` rendered the item
            # at its latest revision but cached the fragment under revision 0
            # for the 30-day TTL — so the Item-page preview (contentpreview.js)
            # never changed after an edit. The scratch item starts without a
            # note; adding one mints a new revision, and the second embed must
            # show it.
            before = embed(cache_math_id)
            expect(NOTE_MARKER not in before, "embed-cache item unexpectedly already carries a note")
            api.add_claims(cache_math_id, {
                note_property: item_claim(note_property, { "value": NOTE_MARKER, "type": "string" }),
            }, "E2E rich suite: embed-cache note update")
            after = embed(cache_math_id)
            expect(
                NOTE_MARKER in after,
                "action=embed served a stale fragment after the item was updated",
            )

        run_check("embed reflects the latest revision after an update (Q2039)", embed_reflects_latest_revision)
        run_check("quotation payload parses as wikitext (embed surface)", quotation_is_rich)
        run_check("quotation payload parses as wikitext ({{#content:}})", quotation_rich_in_parser_function)
        run_check("math note renders below the expression (embed surface)", math_note_renders)
        run_check("math note has no .wb-embed chrome", math_note_has_no_embed_chrome)
        run_check("quotation preview = original + translation + attribution", preview_quotation_translation_and_attribution)
        run_check("citation reads the source URL + last-edition year", citation_reads_source_url_and_last_edition_year)
        run_check("{{#content:Q|noNote}} suppresses the note", no_note_suppresses_it)
        run_check("{{#content:Q|fr|eo}} renders translations + not-found", quotation_translations_render)
        run_check("rich injections do not survive (embed/parse/listing)", injections_do_not_survive)
        run_check("Special:QuotationsOf renders the rich quotation", quotation_listing_renders_rich)
        run_check("{{#statement-row}} shows data rows and hides empty ones", statement_row_shows_data_and_hides_empty)
        run_check("{{#quotations-by}} row + author listing", quotations_by_row_and_listing)

        if failures:
            print(f"\nRICH E2E FAILED: {len(failures)} check(s): {', '.join(failures)}")
            return 1
        print("\nRICH E2E: all checks passed.")
        return 0
    except CheckFailed as exc:
        print(f"\nRICH E2E FAILED: {exc}")
        return 1
    finally:
        for qid in (quote_id, math_id, cache_math_id):
            if qid:
                try:
                    api.delete_item(qid, "E2E rich suite: cleanup")
                except WikibaseApiError as exc:
                    print(f"  [warn] cleanup of {qid} failed: {exc}")


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="v1 E2E acceptance + XSS suite")
    sub = parser.add_subparsers(dest="command", required=True)

    check_p = sub.add_parser("check", help="acceptance checks against a seeded instance")
    check_p.add_argument("--api-url", required=True)
    check_p.add_argument("--base-url", required=True)
    check_p.add_argument("--sparql-url", required=True)
    check_p.add_argument("--concept-uri", default="https://wikibase.ronzz.org",
                         help="WDQS entity concept URI (prefix base)")
    check_p.add_argument("--quote", required=True)
    check_p.add_argument("--code", required=True)
    check_p.add_argument("--math", required=True)
    check_p.add_argument("--instance-of", required=True)
    check_p.add_argument("--quotation-class", required=True)
    check_p.add_argument(
        "--sparql-wait", type=int, default=0,
        help="retry the SPARQL check for N seconds (WDQS updater sync)",
    )
    check_p.add_argument(
        "--allow-sparql-fail", action="store_true",
        help="report a failing SPARQL check as a warning (CI fresh-instance updater quirk)",
    )
    check_p.set_defaults(handler=check)

    xss_p = sub.add_parser("xss", help="XSS suite (creates an injection test item)")
    xss_p.add_argument("--api-url", required=True)
    xss_p.add_argument("--base-url", required=True)
    xss_p.add_argument("--user", required=True)
    xss_p.add_argument("--password", required=True)
    xss_p.set_defaults(handler=xss)

    rich_p = sub.add_parser(
        "rich", help="rich-content E2E (quotation wikitext + math note + noNote)"
    )
    rich_p.add_argument("--api-url", required=True)
    rich_p.add_argument("--base-url", required=True)
    rich_p.add_argument("--user", required=True)
    rich_p.add_argument("--password", required=True)
    rich_p.set_defaults(handler=rich)

    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    return args.handler(args)


if __name__ == "__main__":
    sys.exit(main())
