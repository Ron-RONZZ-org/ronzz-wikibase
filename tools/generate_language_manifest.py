#!/usr/bin/env python3
"""Generate the language-item manifest from the installed Pygments lexers.

The renderer's canonical contract is the Pygments lexer name: this tool dumps
the installed lexers (``pygmentize -L lexers``), keeps a curated default subset
of well-known languages, and writes the human-review manifest

    extensions/EmbeddableContent/manifests/languages.csv

which the seed (D2) later imports as language *items* (each ``instance of`` the
``programming language`` class). See the repo issue #6, §2.

Workflow
--------
1. Generate a draft::

       python3 tools/generate_language_manifest.py --dry-run   # print
       python3 tools/generate_language_manifest.py             # write the CSV

2. Human review — edit the CSV:
   - translate the ``label.fr`` / ``label.eo`` columns (labels default to the
     English human name in every language),
   - adjust descriptions where "programming language" is not accurate,
   - fill ``wikidata_qid`` from Wikidata (e.g. Python = Q9296),
   - drop languages you do not want in the instance vocabulary.

3. Commit the reviewed CSV. It is the source of truth for the seed; unknown
   languages fall back to the ``text`` lexer at render time.

``--all`` dumps every installed lexer (583 on Pygments 2.17) instead of the
curated default; ``--include`` / ``--exclude`` fine-tune the default set.
"""

from __future__ import annotations

import argparse
import csv
import re
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_OUT = REPO_ROOT / "extensions" / "EmbeddableContent" / "manifests" / "languages.csv"

# Canonical Pygments 2.x lexer names (first alias of each line in `pygmentize -L
# lexers`). This is the reviewer's starting subset — trim it to taste.
CURATED_LEXERS = [
    # languages
    "ada", "awk", "bash", "batch", "c", "clojure", "coffeescript", "common-lisp",
    "cpp", "csharp", "cuda", "cython", "dart", "delphi", "elixir", "elm", "erlang",
    "fish", "fortran", "fsharp", "go", "groovy", "haskell", "java", "javascript",
    "julia", "kotlin", "lua", "matlab", "nimrod", "objective-c", "ocaml", "octave",
    "perl", "php", "powershell", "prolog", "python", "racket", "ruby", "rust",
    "scala", "scheme", "sed", "solidity", "sparql", "splus", "sql", "swift", "tcl",
    "turtle", "typescript", "vb.net", "verilog", "vhdl", "zig",
    # markup / data / config
    "apacheconf", "bibtex", "cmake", "css", "diff", "docker", "graphql", "html",
    "ini", "json", "less", "make", "markdown", "nginx", "protobuf", "restructuredtext",
    "scss", "systemd", "tex", "text", "toml", "vim", "xml", "yaml",
]

LEXER_LINE = re.compile(r"^\* ([^:]+):")
NAME_LINE = re.compile(r"^    (.+)$")
FALLBACK_DESCRIPTION = {
    "en": "programming language",
    "fr": "langage de programmation",
    "eo": "programlingvo",
}
CSV_HEADER = [
    "lexer", "label.en", "label.fr", "label.eo",
    "description.en", "description.fr", "description.eo", "wikidata_qid",
]


def run_pygmentize(binary: str) -> str:
    try:
        proc = subprocess.run(
            [binary, "-L", "lexers"],
            capture_output=True,
            text=True,
            check=True,
        )
    except (FileNotFoundError, subprocess.CalledProcessError) as exc:
        raise SystemExit(
            f"cannot run {binary} -L lexers ({exc}). Install Pygments or pass --pygmentize."
        ) from exc
    return proc.stdout


def parse_lexers(output: str) -> dict[str, str]:
    """Return {canonical lexer name: human name} from `pygmentize -L lexers`."""
    lexers: dict[str, str] = {}
    current: str | None = None
    for line in output.splitlines():
        m = LEXER_LINE.match(line)
        if m:
            current = m.group(1).split(",")[0].strip()
            continue
        if current is not None and line.startswith("    "):
            m = NAME_LINE.match(line)
            if m:
                name = m.group(1).strip()
                if " (filenames" in name:
                    name = name.split(" (filenames", 1)[0]
                if current:  # skip the two nameless pseudo-lexers
                    lexers[current] = name
            current = None
    return lexers


def build_rows(lexers: dict[str, str]) -> list[dict[str, str]]:
    rows = []
    for lexer in sorted(lexers):
        human_name = lexers[lexer]
        rows.append(
            {
                "lexer": lexer,
                "label.en": human_name,
                "label.fr": human_name,
                "label.eo": human_name,
                "description.en": FALLBACK_DESCRIPTION["en"],
                "description.fr": FALLBACK_DESCRIPTION["fr"],
                "description.eo": FALLBACK_DESCRIPTION["eo"],
                "wikidata_qid": "",
            }
        )
    return rows


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pygmentize", default="pygmentize", help="pygmentize binary (default: pygmentize)")
    parser.add_argument("--all", action="store_true", help="include every installed lexer")
    parser.add_argument("--include", default="", help="extra lexers to add (comma-separated)")
    parser.add_argument("--exclude", default="", help="lexers to drop (comma-separated)")
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT, help=f"output CSV (default: {DEFAULT_OUT})")
    parser.add_argument("--dry-run", action="store_true", help="print the CSV instead of writing it")
    args = parser.parse_args()

    lexers = parse_lexers(run_pygmentize(args.pygmentize))

    wanted = set(lexers) if args.all else set(CURATED_LEXERS)
    wanted.update(name.strip() for name in args.include.split(",") if name.strip())
    wanted.difference_update(name.strip() for name in args.exclude.split(",") if name.strip())

    selected = {name: label for name, label in lexers.items() if name in wanted}
    missing = sorted(wanted - set(lexers))
    if missing:
        print(
            f"warning: {len(missing)} requested lexers not installed, skipped: "
            + ", ".join(missing),
            file=sys.stderr,
        )

    rows = build_rows(selected)

    if args.dry_run:
        writer = csv.DictWriter(sys.stdout, fieldnames=CSV_HEADER, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
        print(f"\n# {len(rows)} languages written to stdout (dry run).", file=sys.stderr)
        return 0

    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=CSV_HEADER, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
    print(f"wrote {len(rows)} languages to {args.out}")
    print("review it before committing: translate labels, fill wikidata_qid, drop unwanted entries.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
