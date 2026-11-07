#!/usr/bin/env python3
"""Report sources whose author is a "Group of humans" name-list item.

The legacy shortcut stored a bibliography's author list as ONE
``Collective:`` item of class *group of humans* (e.g.
``Moro, Christian ; Phelps, Charlotte ; ...``) and linked it as the source's
``attributed to`` author. That item is not a person and cannot participate in
the citation/author machinery correctly.

This tool is READ-ONLY. It queries the instance's SPARQL endpoint for every
group-of-humans item used as an author, lists the referencing sources, and
optionally resolves the group label through ``action=authorresolve`` (the
canonical name parser/matcher) so the split plan can be reviewed.

Usage::

    python3 tools/report_group_authors.py \
        --sparql-url https://query.ronzz.org/sparql \
        --api-url https://wikibase.ronzz.org/w/api.php \
        --output group-authors.json \
        [--resolve]

``--resolve`` requires the deployed EmbeddableContent authorresolve API; the
report itself only needs SPARQL and the read-only MediaWiki API.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import json
import sys
import urllib.parse
import urllib.request

UA = "ronzz-wikibase-report-group-authors/1.0"

SPARQL = """PREFIX wd: <https://wikibase.ronzz.org/entity/>
PREFIX wdt: <https://wikibase.ronzz.org/prop/direct/>
SELECT ?group ?groupLabel ?source ?sourceLabel ?sitelink WHERE {
  ?group wdt:P1 wd:Q8 .
  OPTIONAL { ?group wikibase:sitelinks ?sitelink }
  OPTIONAL { ?source wdt:P6 ?group . }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
ORDER BY ?group ?source
"""


def sparql(url: str, query: str) -> dict:
    data = urllib.parse.urlencode({"query": query, "format": "json"}).encode()
    req = urllib.request.Request(url, data=data, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.load(resp)


def api_get(url: str, params: dict) -> dict:
    q = urllib.parse.urlencode({"format": "json", **params})
    req = urllib.request.Request(url + "?" + q, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.load(resp)


def entity(url: str, qid: str) -> dict:
    data = api_get(url, {
        "action": "wbgetentities",
        "ids": qid,
        "props": "labels|descriptions|claims|sitelinks",
    })
    return data.get("entities", {}).get(qid, {})


def collect(sparql_url: str, api_url: str, resolve: bool, language: str) -> list[dict]:
    result = sparql(sparql_url, SPARQL)
    groups: dict[str, dict] = {}
    for row in result.get("results", {}).get("bindings", []):
        qid = row["group"]["value"].rsplit("/", 1)[-1]
        entry = groups.setdefault(qid, {
            "qid": qid,
            "label": row.get("groupLabel", {}).get("value", qid),
            "sources": [],
            "sitelink": row.get("sitelink", {}).get("value"),
        })
        source_uri = row.get("source", {}).get("value")
        if source_uri:
            source_qid = source_uri.rsplit("/", 1)[-1]
            if source_qid not in entry["sources"]:
                entry["sources"].append({
                    "qid": source_qid,
                    "label": row.get("sourceLabel", {}).get("value", source_qid),
                })

    out = []
    for qid, entry in sorted(groups.items()):
        item = entity(api_url, qid)
        claims = item.get("claims", {})
        entry["claimProperties"] = sorted(claims)
        entry["claimCount"] = sum(len(v) for v in claims.values())
        entry["sitelinks"] = item.get("sitelinks", {})
        if resolve:
            data = api_get(api_url, {
                "action": "authorresolve",
                "names": entry["label"],
                "nameFormat": "auto",
                "language": language,
            })
            entry["resolution"] = data.get("authorresolve", {})
        entry["sources"].sort(key=lambda s: s["qid"])
        out.append(entry)
    return out


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sparql-url", default="https://query.ronzz.org/sparql")
    parser.add_argument("--api-url", default="https://wikibase.ronzz.org/w/api.php")
    parser.add_argument("--output", default="-", help="output file, or - for stdout")
    parser.add_argument("--resolve", action="store_true",
                        help="resolve each group label through action=authorresolve")
    parser.add_argument("--language", default="en")
    args = parser.parse_args()

    groups = collect(args.sparql_url, args.api_url, args.resolve, args.language)
    payload = {
        "groupCount": len(groups),
        "sourceLinkCount": sum(len(g["sources"]) for g in groups),
        "groups": groups,
    }
    text = json.dumps(payload, ensure_ascii=False, indent=2)
    if args.output == "-":
        print(text)
    else:
        with open(args.output, "w", encoding="utf-8") as handle:
            handle.write(text + "\n")
        print(f"wrote {args.output} ({payload['groupCount']} groups, "
              f"{payload['sourceLinkCount']} source links)", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
