#!/usr/bin/env python3
"""Backfill membership for legacy "Group of humans" author groups.

The migration counterpart of ``report_group_authors.py``. For every
group-of-humans item used as a source's ``attributed to`` author:

1. resolve the group label through ``action=authorresolve`` (the canonical
   name parser/matcher);
2. reuse an exactly matching Person, or create a new Person through
   ``action=addsemanticentity``;
3. add a ``has member`` claim for each Person on the GROUP item (the group is
   kept as a reusable collaborator group);
4. replace the group claim on every referencing source with the Person
   claims, so the source stores its actual individual authors;
5. rename the group label to the auto-label style (member names, with
   ``et al.`` on overflow) — the label-sync hook renames the Collective:
   page.

Safety:
* dry-run by default — nothing is written without ``--apply``;
* a name with an ambiguous / probable-only / mononym / CJK / parenthetical
  match is SKIPPED and reported for manual review (never silently guessed);
* exact-label duplicates are never reused when there is more than one.

Usage::

    python3 tools/backfill_group_members.py \
        --report group-authors.json \
        --api-url https://wikibase.ronzz.org/w/api.php \
        --user SeedBot --password-file seed/.seedbot.pass \
        [--apply] [--verify]

Requires the deployed EmbeddableContent ``action=authorresolve`` and the new
``has member`` property (discovered through
``action=addsemanticentity-fields`` unless --has-member-property is given).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import re
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


def has_member_property(op, api: str) -> str:
    data = api_get(op, api, {"action": "addsemanticentity-fields"})
    value = (
        data.get("semanticfields", {})
        .get("propertyIds", {})
        .get("collectiveProperties", {})
        .get("hasMember")
    )
    if not value:
        raise FlowError(
            "hasMember is missing from addsemanticentity-fields; re-seed the vocabulary first."
        )
    return value


def add_group_member(op, api: str, group: str, person: str, property_id: str,
                     token: str, summary: str) -> bool:
    entity = entities(op, api, [group]).get(group, {})
    existing = {v["qid"] for v in author_values(entity.get("claims", {}), property_id)}
    if person in existing:
        return False
    api_post(op, api, {
        "action": "wbcreateclaim",
        "entity": group,
        "property": property_id,
        "snaktype": "value",
        "value": json.dumps({"entity-type": "item", "numeric-id": int(person[1:])}),
        "summary": summary,
        "token": token,
    })
    return True


def group_label(names: list[str], max_length: int = 250) -> str:
    names = [n.strip() for n in names if n.strip()]
    if not names:
        return ""
    label = ""
    for index, name in enumerate(names):
        candidate = name if not label else f"{label}, {name}"
        remaining = len(names) - index - 1
        reserve = remaining > 0 and len(candidate) + len(" et al.") > max_length
        if len(candidate) <= max_length and not reserve:
            label = candidate
            continue
        if label and len(label + " et al.") <= max_length:
            return label + " et al."
        if not label:
            return name[:max_length]
        return label[:max_length]
    return label[:max_length]


def title_label(label: str) -> str:
    title = re.sub(r"[#<>\[\]{}|]", "-", label).strip()
    return title[:1].upper() + title[1:] if title else title


def rename_group(op, api: str, group: str, label: str, token: str, summary: str) -> None:
    entity = entities(op, api, [group]).get(group, {})
    old_title = ((entity.get("sitelinks") or {}).get("wikibase") or {}).get("title")
    new_title = "Collective:" + title_label(label)
    moved = False
    if old_title and old_title != new_title:
        try:
            api_post(op, api, {
                "action": "move",
                "from": old_title,
                "to": new_title,
                "reason": summary,
                "movetalk": "1",
                "token": token,
            })
            moved = True
            print(f"  moved page: {old_title} -> {new_title}")
        except FlowError as exc:
            print(f"  WARN page move failed: {exc}", file=sys.stderr)

    # The page move updates the item's sitelink through a hook; re-read so the
    # label update does not race that edit.  Set the sitelink ourselves only
    # when the move succeeded and the hook left the old title in place.
    entity = entities(op, api, [group]).get(group, {})
    current_title = ((entity.get("sitelinks") or {}).get("wikibase") or {}).get("title")
    current_label = ((entity.get("labels") or {}).get("en") or {}).get("value")
    if current_label == label and (not current_title or current_title == new_title):
        print("  label already set")
        return
    data = {"labels": {"en": {"language": "en", "value": label}}}
    if moved and current_title != new_title:
        data["sitelinks"] = {"wikibase": {"site": "wikibase", "title": new_title}}
    try:
        api_post(op, api, {
            "action": "wbeditentity",
            "id": group,
            "data": json.dumps(data),
            "summary": summary,
            "token": token,
        })
    except FlowError as exc:
        if "edit-conflict" not in str(exc):
            raise
        # A move hook or a concurrent edit wrote the item after our read;
        # retry the label alone, which cannot conflict with a sitelink write.
        time.sleep(0.5)
        api_post(op, api, {
            "action": "wbeditentity",
            "id": group,
            "data": json.dumps({"labels": {"en": {"language": "en", "value": label}}}),
            "summary": summary,
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
    parser.add_argument("--has-member-property",
                        help="has-member property id (default: discover via addsemanticentity-fields)")
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
    has_member = args.has_member_property or has_member_property(op, args.api_url)

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
                person_plan.append({"create": name, "name": name})
        if skip:
            print(f"  SKIP: {skip}", file=sys.stderr)
            continue
        for entry in person_plan:
            target = entry.get("reuse") or f"CREATE {entry['name'].get('givenName')} {entry['name'].get('familyName')}"
            print(f"  person: {target}")
        planned_names = []
        for entry in person_plan:
            planned_names.append(
                entry["name"].get("givenName", "") + " " + entry["name"].get("familyName", "")
            )
        planned_label = group_label([n.strip() for n in planned_names])
        print(f"  add has-member claims ({has_member})")
        print(f"  rename group label -> {planned_label!r}")
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
        member_labels = []
        for entry, person in zip(person_plan, persons):
            if "reuse" in entry:
                # The exact candidate label is the best display name.
                exact = [c for c in entry["name"].get("candidates", [])
                         if c.get("score") == 1.0 and c.get("reason") == "exact"]
                member_labels.append(exact[0]["label"] if exact else person)
            else:
                member_labels.append(
                    (entry["name"].get("givenName", "") + " "
                     + entry["name"].get("familyName", "")).strip()
                )
            if add_group_member(op, args.api_url, qid, person, has_member, token, args.summary):
                print(f"  has member: {person}")
            time.sleep(0.2)
        for source in group.get("sources", []):
            changed = replace_group_claims(
                op, args.api_url, source["qid"], args.author_property, qid, persons, token, args.summary
            )
            print(f"  source {source['qid']}: {'updated' if changed else 'no group claim'}")
            time.sleep(0.2)
        label = group_label(member_labels)
        rename_group(op, args.api_url, qid, label, token, args.summary)
        print(f"  label -> {label!r}")
        remaining = remaining_sources(args.sparql_url, qid, args.author_property)
        print(f"  remaining source references: {remaining}")
        if args.verify:
            for source in group.get("sources", []):
                entity = entities(op, args.api_url, [source["qid"]]).get(source["qid"], {})
                values = {v["qid"] for v in author_values(entity.get("claims", {}), args.author_property)}
                ok = qid not in values and set(persons) <= values
                print(f"  verify {source['qid']}: {'OK' if ok else 'MISMATCH'}")
            group_entity = entities(op, args.api_url, [qid]).get(qid, {})
            group_members = {
                v["qid"] for v in author_values(group_entity.get("claims", {}), has_member)
            }
            missing = [p for p in persons if p not in group_members]
            print(f"  verify members: {'OK' if not missing else 'MISMATCH ' + ','.join(missing)}")
            label = group_label(member_labels)
            stored = (
                group_entity.get("labels", {}).get("en", {}).get("value", "")
                if isinstance(group_entity, dict) else ""
            )
            print(f"  verify label: {'OK' if stored == label else 'MISMATCH'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
