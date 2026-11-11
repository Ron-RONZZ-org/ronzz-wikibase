#!/usr/bin/env python3
r"""E2E for action=authority-search (read-only external-authority lookup).

The machine-facing counterpart of the AddSource/AddPerson search step. This
suite proves the module is registered, its parameter validation works, and
the live provider cascade returns the records the MCP authority-search tool
shapes into Add* fields.

Usage::

    python3 tests/e2e/run_authority_search_e2e.py live \
        --api-url https://wikibase.ronzz.org/api.php
    python3 tests/e2e/run_authority_search_e2e.py offline \
        --api-url https://wikibase.ronzz.org/api.php

`offline` needs no internet path to the authorities (only the wiki): it
checks registration and enum validation. `live` also calls Wikidata,
Crossref, Open Library, and ORCID; it is the manual deploy gate.

Exit code 0 = all checks passed.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-authority-search-e2e/1.0"


class CheckFailed(Exception):
    pass


def api(api_url: str, params: dict, timeout: int = 120) -> dict:
    url = api_url + "?" + urllib.parse.urlencode(params)
    request = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return json.load(response)
    except urllib.error.HTTPError as exc:
        raise CheckFailed(f"HTTP {exc.code} for {url}") from exc
    except urllib.error.URLError as exc:
        raise CheckFailed(f"cannot reach {url}: {exc.reason}") from exc


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise CheckFailed(message)


def check_offline(api_url: str) -> None:
    # A missing required parameter is an API-level error: it proves the
    # module is registered (an unknown action answers "unknown_action").
    data = api(api_url, {"action": "authority-search", "format": "json"})
    expect(
        data.get("error", {}).get("code") == "missingparam",
        f"expected missingparam for the required kind, got {data}",
    )
    # An invalid enum is rejected by MediaWiki before the handler runs.
    data = api(
        api_url,
        {"action": "authority-search", "kind": "bogus", "format": "json"},
    )
    expect(
        data.get("error", {}).get("code") == "badvalue",
        f"expected badvalue for an unknown kind, got {data}",
    )


def check_live(api_url: str) -> None:
    doi = api(
        api_url,
        {
            "action": "authority-search",
            "kind": "work",
            "mode": "lookup",
            "doi": "10.1103/PhysRev.47.777",
            "resolveLocal": "1",
            "format": "json",
        },
    )
    records = doi.get("authoritysearch", {}).get("records", [])
    expect(len(records) > 0, f"DOI lookup returned no records: {doi}")
    work = records[0]
    fields = work.get("suggestedAddFields", {})
    expect(fields.get("title"), f"DOI record has no suggested title: {work}")
    expect(
        isinstance(work.get("record", {}).get("authors"), list)
        and len(work["record"]["authors"]) > 0,
        f"DOI record has no authors (Phase D): {work}",
    )
    expect("local" in work, f"resolveLocal=1 returned no local block: {work}")
    expect(
        "authors" in work["local"],
        f"resolveLocal=1 returned no local.authors: {work}",
    )

    # Harvest carries author Wikidata Q-ids, so resolveLocal enters the
    # authority-match path (claimHasValue). A direct PropertyId instantiation
    # passed the unit suite (data-model 9.6.1) and 500'd on the deployed
    # interface (the embeddable-authority-search report); this is the
    # production-runtime regression gate.
    harvest = api(
        api_url,
        {
            "action": "authority-search",
            "kind": "work",
            "mode": "harvest",
            "wikidataId": "Q21563817",
            "resolveLocal": "1",
            "format": "json",
        },
    )
    records = harvest.get("authoritysearch", {}).get("records", [])
    expect(len(records) > 0, f"harvest returned no records: {harvest}")
    local = records[0].get("local", {})
    expect(
        "authors" in local,
        f"harvest resolveLocal returned no local.authors: {records[0]}",
    )
    expect(
        any(author.get("matchedBy") == "wikidata" for author in local["authors"]),
        f"harvest did not match an author by Wikidata id: {local['authors']}",
    )

    person = api(
        api_url,
        {
            "action": "authority-search",
            "kind": "person",
            "orcid": "0000-0002-1825-0097",
            "format": "json",
        },
    )
    expect(
        len(person.get("authoritysearch", {}).get("records", [])) > 0,
        f"ORCID lookup returned no records: {person}",
    )

    book = api(
        api_url,
        {
            "action": "authority-search",
            "kind": "work",
            "class": "book",
            "isbn": "9780262033848",
            "resolveLocal": "1",
            "format": "json",
        },
    )
    expect(
        len(book.get("authoritysearch", {}).get("records", [])) > 0,
        f"ISBN lookup returned no records: {book}",
    )

    entity = api(
        api_url,
        {
            "action": "authority-search",
            "kind": "entity",
            "query": "CERN",
            "resolveLocal": "1",
            "format": "json",
        },
    )
    entity_records = entity.get("authoritysearch", {}).get("records", [])
    expect(len(entity_records) > 0, f"entity search returned no records: {entity}")
    expect(
        "collectiveClassWikidataIds" in entity_records[0].get("local", {}),
        f"entity resolveLocal returned no class hints: {entity_records[0]}",
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["offline", "live"])
    parser.add_argument(
        "--api-url",
        default="https://wikibase.ronzz.org/api.php",
        help="wiki api.php URL",
    )
    args = parser.parse_args()

    try:
        if args.mode == "offline":
            check_offline(args.api_url)
        else:
            check_live(args.api_url)
    except CheckFailed as exc:
        print(f"[FAIL] {exc}")
        return 1

    print(f"[ok] authority-search {args.mode} checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
