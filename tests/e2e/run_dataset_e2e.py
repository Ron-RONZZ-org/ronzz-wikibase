#!/usr/bin/env python3
"""Curated-dataset E2E: contract dry-run → apply job → query → page.

Runs against the dev/CI wiki. The dataset schema must be installed
(`maintenance/installDatasetSchema.php`) and the seed must include the dataset
vocabulary. The script uploads a tiny PDF, applies the golden manifest with a
fresh dataset id, polls the apply run, queries the store and parses the
Dataset: page. Self-cleans the wiki pages it created.
"""

from __future__ import annotations

import argparse
import hashlib
import http.cookiejar
import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

UA = "ronzz-wikibase-dataset-e2e/1.0"
ROOT = Path(__file__).resolve().parents[2]
EXAMPLE = ROOT / "extensions/EmbeddableContent/contract/dataset-manifest.example.json"


class E2EError(RuntimeError):
    pass


def opener() -> urllib.request.OpenerDirector:
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def api_call(op, api: str, params: dict, post: bool = False) -> dict:
    data = urllib.parse.urlencode(params).encode()
    url = api + ("?" + data.decode() if not post else "")
    req = urllib.request.Request(url, data=data if post else None, headers={"User-Agent": UA})
    with op.open(req, timeout=120) as response:
        return json.load(response)


def login(op, api: str, user: str, password: str) -> None:
    token = api_call(op, api, {"action": "query", "meta": "tokens", "type": "login", "format": "json"})
    result = api_call(
        op,
        api,
        {
            "action": "login",
            "lgname": user,
            "lgpassword": password,
            "lgtoken": token["query"]["tokens"]["logintoken"],
            "format": "json",
        },
        post=True,
    )
    if result.get("login", {}).get("result") != "Success":
        raise E2EError(f"login failed: {result.get('login', {})}")


def csrf(op, api: str) -> str:
    token = api_call(op, api, {"action": "query", "meta": "tokens", "type": "csrf", "format": "json"})
    return token["query"]["tokens"]["csrftoken"]


def upload_pdf(op, api: str, token: str, name: str, content: bytes) -> tuple[str, str]:
    boundary = "----ronzzwbdataset"
    fields = {
        "action": "upload",
        "format": "json",
        "filename": name,
        "token": token,
        "ignorewarnings": "1",
        "comment": "dataset E2E fixture",
    }
    parts = []
    for key, value in fields.items():
        parts.append(
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"\r\n\r\n{value}\r\n"
        )
    parts.append(
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{name}\"\r\n"
        "Content-Type: application/pdf\r\n\r\n"
    )
    body = "".join(parts).encode() + content + f"\r\n--{boundary}--\r\n".encode()
    req = urllib.request.Request(
        api,
        data=body,
        headers={"User-Agent": UA, "Content-Type": f"multipart/form-data; boundary={boundary}"},
    )
    with op.open(req, timeout=180) as response:
        result = json.load(response)
    if "upload" not in result or result["upload"].get("result") != "Success":
        raise E2EError(f"upload failed: {result}")
    filename = str(result["upload"]["filename"]).removeprefix("File:")
    sha1 = (result["upload"].get("imageinfo") or {}).get("sha1") or hashlib.sha1(content).hexdigest()
    return filename, str(sha1)


def minimal_pdf() -> bytes:
    return (
        b"%PDF-1.4\n"
        b"1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        b"2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        b"3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] >>\nendobj\n"
        b"trailer\n<< /Root 1 0 R >>\n%%EOF\n"
    )


def source_item() -> str:
    ids_path = ROOT / "seed/generated/ids.json"
    if ids_path.exists():
        ids = json.loads(ids_path.read_text(encoding="utf-8"))
        value = ids.get("dogfood", {}).get("book")
        if isinstance(value, str):
            return value
    return "Q1"


def dataset_manifest(dataset_id: str, pdf_file: str, pdf_sha1: str) -> dict:
    manifest = json.loads(EXAMPLE.read_text(encoding="utf-8"))
    manifest["dataset"]["dataset_id"] = dataset_id
    manifest["dataset"]["title"] = "E2E curated dataset"
    manifest["dataset"]["source_page"] = "Main Page"
    manifest["dataset"]["source_item"] = source_item()
    table = manifest["tables"][0]
    table["table_id"] = f"{dataset_id}-t1"
    table["caption"] = "Table 1. E2E fixture"
    table["pdf_file"] = pdf_file
    table["pdf_sha1"] = pdf_sha1
    table["pdf_revision"] = None
    table["extraction_runs"][0]["run_id"] = f"{table['table_id']}-run1"
    return manifest


def poll_status(op, api: str, run_id: str, timeout: int = 120) -> dict:
    deadline = time.time() + timeout
    while time.time() < deadline:
        result = api_call(op, api, {"action": "datasetstatus", "run_id": run_id, "format": "json"})
        status = result.get("datasetstatus", {})
        if status.get("status") in {"ok", "partial", "failed"}:
            return status
        time.sleep(2)
    raise E2EError(f"dataset apply run {run_id} did not finish within {timeout}s")


def delete_page(op, api: str, title: str, token: str) -> None:
    result = api_call(
        op,
        api,
        {"action": "delete", "title": title, "token": token, "format": "json"},
        post=True,
    )
    if "error" in result:
        print(f"  cleanup warning for {title}: {result['error']}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-url", default="http://127.0.0.1:8082/api.php")
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    args = parser.parse_args()

    op = opener()
    login(op, args.api_url, args.user, args.password)
    token = csrf(op, args.api_url)
    dataset_id = f"e2e-{int(time.time())}-{os.getpid()}"
    pdf = minimal_pdf()
    pdf_name = f"{dataset_id}.pdf"
    print(f"== dataset {dataset_id}")

    pdf_file, pdf_sha1 = upload_pdf(op, args.api_url, token, pdf_name, pdf)
    print(f"  uploaded File:{pdf_file} (sha1 {pdf_sha1})")
    info = api_call(op, args.api_url, {
        "action": "query", "titles": f"File:{pdf_file}", "prop": "imageinfo",
        "iiprop": "sha1", "format": "json",
    })
    print(f"  imageinfo: {json.dumps(info)[:1000]}")

    manifest = dataset_manifest(dataset_id, pdf_file, pdf_sha1)
    encoded = json.dumps(manifest)

    dry = api_call(
        op,
        args.api_url,
        {"action": "datasetupsert", "manifest": encoded, "dryrun": "1", "token": token, "format": "json"},
        post=True,
    )
    if "datasetupsert" not in dry:
        raise E2EError(f"dry-run returned no datasetupsert payload: {json.dumps(dry)[:4000]}")
    report = dry["datasetupsert"]
    if report.get("status") not in {"ok", "warnings"}:
        raise E2EError(f"dry-run failed: {json.dumps(dry)[:4000]}")
    print(f"  dry-run: {report.get('status')}")

    applied = api_call(
        op,
        args.api_url,
        {"action": "datasetupsert", "manifest": encoded, "apply": "1", "token": token, "format": "json"},
        post=True,
    )
    run_id = applied.get("datasetupsert", {}).get("runId")
    if not run_id:
        raise E2EError(f"apply did not return a run id: {json.dumps(applied)[:4000]}")
    status = poll_status(op, args.api_url, run_id)
    if status.get("status") not in {"ok", "partial"}:
        raise E2EError(f"apply failed: {json.dumps(status)[:2000]}")
    print(f"  apply: {status.get('status')}")

    query = api_call(
        op,
        args.api_url,
        {"action": "datasetquery", "dataset_id": dataset_id, "limit": "50", "format": "json"},
    )
    rows = query.get("datasetquery", {}).get("rows", [])
    if len(rows) != 4:
        raise E2EError(f"expected 4 observation rows, got {len(rows)}: {query}")
    print(f"  query: {len(rows)} rows")

    page = api_call(
        op,
        args.api_url,
        {"action": "parse", "page": f"Dataset:{dataset_id}", "prop": "text", "format": "json"},
    )
    html = page.get("parse", {}).get("text", {}).get("*", "")
    if "wb-dataset-table" not in html or "wb-dataset-footnotes" not in html:
        raise E2EError("Dataset: page did not render the table/footnotes parser functions")
    if f"fn-{dataset_id}-t1-fn1" not in html:
        raise E2EError("the footnote anchor is missing from the rendered page")
    print("  page: table + footnotes rendered")

    delete_page(op, args.api_url, f"Dataset:{dataset_id}/manifest", token)
    delete_page(op, args.api_url, f"Dataset:{dataset_id}", token)
    print("PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
