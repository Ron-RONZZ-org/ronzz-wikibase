"""Loads the vocabulary manifests shipped with the EmbeddableContent extension.

Mirrors the column contract of the PHP ``ManifestReader`` (D1): per-language
``label.<lang>`` / ``description.<lang>`` columns, plus ``datatype``,
``align.uri``, ``align.wikidata`` (properties/classes) and ``lexer``,
``wikidata_qid`` (languages). The orchestrator consumes the exact same CSV
files as the D1 maintenance importers.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import csv
from pathlib import Path
from typing import Any

ALLOWED_DATATYPES = {"wikibase-item", "monolingualtext", "string", "url", "time", "external-id"}


class ManifestError(Exception):
    """Raised when a manifest is malformed or unreadable."""


def manifest_languages(path: str | Path) -> list[str]:
    """Language codes present in the manifest, in column order."""
    columns = _read_header(path)
    langs = []
    for column in columns:
        if column.startswith("label."):
            langs.append(column[len("label."):])
    if not langs:
        raise ManifestError(f"{path}: no label.<lang> column found")
    return langs


def load_properties(path: str | Path) -> list[dict[str, Any]]:
    rows = _read_rows(path)
    result = []
    for index, row in enumerate(rows, start=2):
        labels = _terms(row, "label")
        descriptions = _terms(row, "description")
        datatype = row.get("datatype", "")
        if datatype not in ALLOWED_DATATYPES:
            raise ManifestError(f"{path} line {index}: invalid datatype {datatype!r}")
        formatter_url = _optional_url(row.get("formatter.url", ""), path, index)
        if formatter_url and datatype != "external-id":
            raise ManifestError(
                f"{path} line {index}: formatter URL requires datatype "
                f'"external-id" (got {datatype!r})'
            )
        result.append(
            {
                "labels": labels,
                "descriptions": descriptions,
                "datatype": datatype,
                "align_uri": _optional_url(row.get("align.uri", ""), path, index),
                "align_wikidata": _optional_url(row.get("align.wikidata", ""), path, index),
                "formatter_url": formatter_url,
            }
        )
    return result


def load_classes(path: str | Path) -> list[dict[str, Any]]:
    rows = _read_rows(path)
    result = []
    for index, row in enumerate(rows, start=2):
        result.append(
            {
                "labels": _terms(row, "label"),
                "descriptions": _terms(row, "description"),
                "align_uri": _optional_url(row.get("align.uri", ""), path, index),
                "align_wikidata": _optional_url(row.get("align.wikidata", ""), path, index),
            }
        )
    return result


def load_languages(path: str | Path) -> list[dict[str, Any]]:
    rows = _read_rows(path)
    result = []
    seen = set()
    for index, row in enumerate(rows, start=2):
        lexer = row.get("lexer", "").strip()
        if not lexer:
            raise ManifestError(f"{path} line {index}: empty lexer name")
        if lexer in seen:
            raise ManifestError(f"{path} line {index}: duplicate lexer {lexer!r}")
        seen.add(lexer)
        qid = row.get("wikidata_qid", "").strip()
        result.append(
            {
                "lexer": lexer,
                "labels": _terms(row, "label"),
                "descriptions": _terms(row, "description"),
                "wikidata_qid": qid or None,
            }
        )
    return result


def load_preseed(path: str | Path) -> list[dict[str, Any]]:
    """Loads the preseed items manifest (issue follow-up: common operating
    systems, FOSS licenses and user interfaces for Special:AddSoftware).
    Each row names the class (by English label) the item is an instance of."""
    rows = _read_rows(path)
    result = []
    seen = set()
    for index, row in enumerate(rows, start=2):
        class_label = row.get("class.en", "").strip()
        if not class_label:
            raise ManifestError(f"{path} line {index}: empty class.en")
        label = row.get("label.en", "").strip()
        if not label:
            raise ManifestError(f"{path} line {index}: empty label.en")
        if label in seen:
            raise ManifestError(f"{path} line {index}: duplicate preseed item {label!r}")
        seen.add(label)
        result.append(
            {
                "class_label": class_label,
                "labels": _terms(row, "label"),
                "descriptions": _terms(row, "description"),
            }
        )
    return result


# ------------------------------------------------------------- internals


def _read_header(path: str | Path) -> list[str]:
    with open(path, newline="", encoding="utf-8") as handle:
        return list(csv.reader(handle))[0]


def _read_rows(path: str | Path) -> list[dict[str, str]]:
    with open(path, newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        return [row for row in reader if any((v or "").strip() for v in row.values())]


def _terms(row: dict[str, str], kind: str) -> dict[str, str]:
    terms = {}
    for key, value in row.items():
        if key.startswith(f"{kind}."):
            lang = key[len(f"{kind}."):]
            terms[lang] = value.strip()
    if not terms:
        raise ManifestError(f"row has no {kind} terms")
    for lang, value in terms.items():
        if not value:
            raise ManifestError(f"missing {kind} for language {lang!r}")
    return terms


def _optional_url(value: str, path: str | Path, line: int) -> str | None:
    value = value.strip()
    if not value:
        return None
    if not value.startswith(("http://", "https://")):
        raise ManifestError(f"{path} line {line}: invalid URL {value!r}")
    return value
