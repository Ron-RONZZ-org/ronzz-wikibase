#!/usr/bin/env python3
"""Generate the ISO 639 language catalog for the AddSource language fields.

The AddSource `language` combobox (and the `additionalLanguages`
multi-combobox) list languages by their **ISO 639-1** (2-letter) and
**ISO 639-2** (3-letter, both the bibliographic and terminology variants)
codes together with the English name — "en — English", "fra — French" — so a
contributor can partial-match on the code AND on the English name.

The catalog is generated from the ISO 639-2 registration authority (the
Library of Congress) machine-readable list:
    https://www.loc.gov/standards/iso639-2/ISO-639-2_utf-8.txt
which carries the 639-2/B code, the 639-2/T code, the 639-1 code, the
English name and the French name per language (``|``-separated, UTF-8 BOM,
CRLF). The generated CSV is committed
(``extensions/EmbeddableContent/data/iso639.csv``) — the extension reads it
at runtime; this tool only (re)generates it for review.

Usage::

    python3 tools/generate_iso_language_fields.py --dry-run   # print the CSV
    python3 tools/generate_iso_language_fields.py             # write the CSV
    python3 tools/generate_iso_language_fields.py --input ISO-639-2_utf-8.txt

Standard library only (urllib, csv, argparse).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import csv
import io
import sys
import urllib.request
from pathlib import Path

SOURCE_URL = "https://www.loc.gov/standards/iso639-2/ISO-639-2_utf-8.txt"
DEFAULT_OUT = (
    Path(__file__).resolve().parent.parent
    / "extensions" / "EmbeddableContent" / "data" / "iso639.csv"
)


def parse_catalog(raw: str) -> list[tuple[str, str, str]]:
    """Parse the LoC ISO 639-2 list into (code, english_name, part) rows.

    ``part`` is the set the code belongs to: ``639-1``, ``639-2`` (both the
    B and T 3-letter variants). Each distinct code appears once (the first
    occurrence wins); the result is sorted by code.
    """
    rows: list[tuple[str, str, str]] = []
    for line in raw.splitlines():
        line = line.lstrip("\ufeff").rstrip("\r\n")
        if not line.strip():
            continue
        fields = line.split("|")
        if len(fields) < 4:
            continue
        b_code = fields[0].strip()
        t_code = fields[1].strip()
        one_code = fields[2].strip()
        english = fields[3].strip()
        if not english:
            continue
        for code, part in (
            (one_code, "639-1"),
            (b_code, "639-2"),
            (t_code, "639-2"),
        ):
            if code:
                rows.append((code, english, part))

    seen: dict[str, tuple[str, str, str]] = {}
    for code, english, part in rows:
        seen.setdefault(code, (code, english, part))
    return sorted(seen.values(), key=lambda row: row[0])


def render_csv(rows: list[tuple[str, str, str]]) -> str:
    buffer = io.StringIO()
    writer = csv.writer(buffer, lineterminator="\n")
    writer.writerow(["code", "english_name", "part"])
    writer.writerows(rows)
    return buffer.getvalue()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument(
        "--input",
        help="raw ISO-639-2 file to read; default: fetch the LoC source URL",
    )
    parser.add_argument(
        "--out",
        default=str(DEFAULT_OUT),
        help=f"output CSV path (default: {DEFAULT_OUT})",
    )
    parser.add_argument(
        "--dry-run", action="store_true", help="print the CSV without writing"
    )
    args = parser.parse_args(argv)

    if args.input:
        raw = Path(args.input).read_text(encoding="utf-8-sig")
    else:
        with urllib.request.urlopen(SOURCE_URL, timeout=60) as response:
            raw = response.read().decode("utf-8-sig")

    rows = parse_catalog(raw)
    output = render_csv(rows)
    if args.dry_run:
        sys.stdout.write(output)
        return 0

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(output, encoding="utf-8")
    print(f"wrote {len(rows)} rows to {out_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
