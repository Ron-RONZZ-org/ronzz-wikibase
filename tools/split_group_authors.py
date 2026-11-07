#!/usr/bin/env python3
"""Split legacy "Group of humans" author items into individual Persons.

The migration counterpart of ``report_group_authors.py``. For every
group-of-humans item used as a source's ``attributed to`` author:

1. resolve the group label through ``action=authorresolve`` (the canonical
   name parser/matcher);
2. reuse an exactly matching Person, or create a new Person through
   ``action=addsemanticentity``;
3. replace the group claim on every referencing source with the Person
   claims;
4. once no source references the group, optionally delete the group item
   and its ``Collective:`` page when the item has no other claims.

Safety:
* dry-run by default — nothing is written without ``--apply``;
* a name with an ambiguous / probable-only / mononym / CJK / parenthetical
  match, or a group with extra claims, is SKIPPED and reported for manual
  review (never silently guessed);
* exact-label duplicates are never reused when there is more than one.

Usage::

    python3 tools/split_group_authors.py \
        --report group-authors.json \
        --api-url https://wikibase.ronzz.org/w/api.php \
        --user SeedBot --password-file seed/.seedbot.pass \
        [--apply] [--delete-groups] [--verify]

Requires the deployed EmbeddableContent ``action=authorresolve``. The
delete step requires the ``delete`` right.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import sys
import time
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-split-group-authors/1.0"


class FlowError(Exception):
    pass


def make_opener() -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
    )


def api_get(op, api: str, params: dict) -> dict:
    url = api + "?" + urllib.parse.urlencode({"format": "json", **params})
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with op.open(req, timeout=120) as resp:
        data = json.load(resp)
    if "error" in data:
        raise FlowError(f"API error: {data['error']}")
    return data


def api_post(op, api: str, params: dict) -> dict:
    data = urllib.parse.urlencode({"format": "json", **params}).encode()
    req = urllib.request.Request(api, data=data, headers={"User-Agent": UA})
    with op.open(req, timeout=120) as resp:
        result = json.load(resp)
    if "error" in result:
        raise FlowError(f"API error: {result['error']}")
    return result


def login(op, api: str, user: str, password: str) -> None:
    token = api_get(op, api, {
        "action": "query", "meta": "tokens", "type": "login",
    })["query"]["tokens"]["logintoken"]
    result = api_post(op, api, {
        "action": "login", "lgname": user, "lgpassword": password, "lgtoken": token,
    })
    if result.get("login", {}).get("result") != "Success":
        raise FlowError(f"login failed: {result}")


def csrf(op, api: str) -> str:
    return api_get(op, api, {
        "action": "query", "meta": "tokens",
    })["query"]["tokens"]["csrftoken"]


def entities(op, api: str, ids: list[str]) -> dict:
    data = api_get(op, api, {
        "action": "wbgetentities",
        "ids": "|".join(ids),
        "props": "labels|descriptions|claims|sitelinks",
    })
    return data.get("entities", {})


def author_values(claims: dict, property_id: str) -> list[dict]:
    out = []
    for claim in claims.get(property_id, []):
        main = claim.get("mainsnak", {})
        if main.get("snaktype") != "value":
            continue
        value = main.get("datavalue", {}).get("value", {})
        if isinstance(value, dict) and value.get("entity-type") == "item":
            out.append({"claim": claim.get("id"), "qid": value.get("id")})
    return out


def resolve_names(op, api: str, label: str, language: str) -> dict:
    data = api_get(op, api, {
        "action": "authorresolve",
        "names": label,
        "nameFormat": "auto",
        "language": language,
    })
    result = data.get("authorresolve")
    if not isinstance(result, dict):
        raise FlowError("authorresolve returned no result (is the new API deployed?)")
    return result


def needs_review(name: dict) -> str | None:
    flags = set(name.get("flags", []))
    for flag in ("mononym", "cjk", "parenthetical"):
        if flag in flags:
            return f"name flag: {flag}"
    candidates = [c for c in name.get("candidates", []) if c.get("score", 0) >= 0.75]
    exact = [c for c in candidates if c.get("score") == 1.0 and c.get("reason") == "exact"]
    if len(exact) > 1:
        return "multiple exact Person matches"
    if not exact and candidates:
        return "probable but unconfirmed Person match"
    return None


def person_for(op, api: str, name: dict, resolution: dict, token: str, summary: str) -> str:
    exact = [c for c in resolution.get("candidates", []) if c.get("score") == 1.0
             and c.get("reason") == "exact"]
    if len(exact) == 1:
        return exact[0]["qid"]
    result = api_post(op, api, {
        "action": "addsemanticentity",
        "kind": "person",
        "givenName": name.get("givenName", ""),
        "familyName": name.get("familyName", ""),
        "labelLanguage": "en",
        "summary": summary,
        "token": token,
    })
    entity_id = result.get("semantic", {}).get("entityId")
    if not entity_id:
        raise FlowError(f"could not create Person {name.get('original')}: {result}")
    return entity_id


def replace_group_claims(op, api: str, source: str, property_id: str,
                          group: str, persons: list[str], token: str,
                          summary: str) -> bool:
    entity = entities(op, api, [source]).get(source, {})
    claims = entity.get("claims", {})
    existing = author_values(claims, property_id)
    group_claims = [c for c in existing if c["qid"] == group]
    if not group_claims:
        return False
    existing_persons = {c["qid"] for c in existing if c["qid"] != group}
    for claim in group_claims:
        api_post(op, api, {
            "action": "wbremoveclaims",
            "claim": claim["claim"],
            "summary": summary,
            "token": token,
        })
    for person in persons:
        if person in existing_persons:
            continue
        api_post(op, api, {
            "action": "wbcreateclaim",
            "entity": source,
            "property": property_id,
            "snaktype": "value",
            "value": json.dumps({"entity-type": "item", "numeric-id": int(person[1:])}),
            "summary": summary,
            "token": token,
        })
    return True


def remaining_sources(sparql_url: str, group: str, property_id: str) -> int:
    query = (
        "PREFIX wd: <https://wikibase.ronzz.org/entity/> "
        "PREFIX wdt: <https://wikibase.ronzz.org/prop/direct/> "
        "SELECT (COUNT(DISTINCT ?s) AS ?n) WHERE { ?s wdt:%s wd:%s . }"
        % (property_id, group)
    )
    data = urllib.parse.urlencode({"query": query, "format": "json"}).encode()
    req = urllib.request.Request(sparql_url, data=data, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=120) as resp:
        payload = json.load(resp)
    rows = payload.get("results", {}).get("bindings", [])
    return int(rows[0]["n"]["value"]) if rows else 0


def delete_group(op, api: str, group: str, item: dict, token: str, summary: str) -> None:
    claims = item.get("claims", {})
    non_instance = [p for p in claims if p != "P1"]
    if non_instance:
        print(f"  SKIP delete {group}: still has claims {non_instance}", file=sys.stderr)
        return
    api_post(op, api, {
        "action": "delete",
        "title": f"Item:{group}",
        "reason": summary,
        "token": token,
    })
    sitelink = (item.get("sitelinks", {}).get("wikibase") or {}).get("title")
    if sitelink:
        api_post(op, api, {
            "action": "delete",
            "title": sitelink,
            "reason": summary,
            "token": token,
        })


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report", help="JSON from report_group_authors.py")
    parser.add_argument("--sparql-url", default="https://query.ronzz.org/sparql")
    parser.add_argument("--api-url", default="https://wikibase.ronzz.org/w/api.php")
    parser.add_argument("--author-property", default="P6")
    parser.add_argument("--language", default="en")
    parser.add_argument("--user")
    parser.add_argument("--password-file")
    parser.add_argument("--summary", default="Split a groups-of-humans author into individual Persons")
    parser.add_argument("--apply", action="store_true", help="write the changes (default: dry-run)")
    parser.add_argument("--delete-groups", action="store_true",
                        help="delete an unreferenced group item + Collective: page")
    parser.add_argument("--verify", action="store_true")
    args = parser.parse_args()

    if not args.report:
        print("--report is required (run report_group_authors.py first)", file=sys.stderr)
        return 2
    with open(args.report, encoding="utf-8") as handle:
        report = json.load(handle)

    op = make_opener()
    if args.apply:
        if not args.user or not args.password_file:
            print("--apply needs --user and --password-file", file=sys.stderr)
            return 2
        with open(args.password_file, encoding="utf-8") as handle:
            password = handle.read().strip()
        login(op, args.api_url, args.user, password)
    token = csrf(op, args.api_url) if args.apply else ""

    for group in report.get("groups", []):
        qid = group["qid"]
        label = group["label"]
        print(f"\n== {qid} — {label}")
        resolution = resolve_names(op, args.api_url, label, args.language)
        names = resolution.get("names", [])
        if not names:
            print("  SKIP: no person names parsed", file=sys.stderr)
            continue
        skip = None
        person_plan = []
        for name in names:
            reason = needs_review(name)
            if reason:
                skip = f"{name.get('original')}: {reason}"
                break
            exact = [c for c in name.get("candidates", []) if c.get("score") == 1.0
                     and c.get("reason") == "exact"]
            if len(exact) == 1:
                person_plan.append({"reuse": exact[0]["qid"], "name": name})
            else:
                person_plan.append({"create": name})
        if skip:
            print(f"  SKIP: {skip}", file=sys.stderr)
            continue
        for entry in person_plan:
            target = entry.get("reuse") or f"CREATE {entry['name'].get('givenName')} {entry['name'].get('familyName')}"
            print(f"  person: {target}")
        for source in group.get("sources", []):
            print(f"  source {source['qid']}: replace {qid} with the persons")

        if not args.apply:
            continue
        persons = []
        for entry in person_plan:
            if "reuse" in entry:
                persons.append(entry["reuse"])
            else:
                persons.append(person_for(
                    op, args.api_url, entry["name"], entry["name"], token, args.summary
                ))
                time.sleep(0.2)
        print(f"  persons: {', '.join(persons)}")
        for source in group.get("sources", []):
            changed = replace_group_claims(
                op, args.api_url, source["qid"], args.author_property, qid, persons, token, args.summary
            )
            print(f"  source {source['qid']}: {'updated' if changed else 'no group claim'}")
            time.sleep(0.2)
        remaining = remaining_sources(args.sparql_url, qid, args.author_property)
        print(f"  remaining source references: {remaining}")
        if remaining == 0 and args.delete_groups:
            item = entities(op, args.api_url, [qid]).get(qid, {})
            delete_group(op, args.api_url, qid, item, token, args.summary)
            print(f"  deleted {qid}")
        if args.verify:
            for source in group.get("sources", []):
                entity = entities(op, args.api_url, [source["qid"]]).get(source["qid"], {})
                values = {v["qid"] for v in author_values(entity.get("claims", {}), args.author_property)}
                ok = qid not in values and set(persons) <= values
                print(f"  verify {source['qid']}: {'OK' if ok else 'MISMATCH'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
