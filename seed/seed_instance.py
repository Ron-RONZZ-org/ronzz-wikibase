"""One-time bootstrap orchestrator for the fresh ronzz-wikibase instance.

Implements issue #6, D2: runs the D1 vocabulary manifests (properties,
classes, Pygments-derived language items) against a fresh Wikibase instance
via direct API calls, creates the dogfood entities, emits the
LocalSettings config fragment, and self-verifies.

Idempotent (skip-existing-label), --dry-run, resume-safe. Stdlib only.

Usage::

    python3 -m seed.seed_instance --user 'Rongzhou@seed' --password '***' --dry-run
    python3 -m seed.seed_instance --user 'Rongzhou@seed' --password '***'

License: GPL-2.0-or-later
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import Any, Optional

# Make sibling modules importable from both `python3 -m seed.seed_instance`
# and `python3 seed/seed_instance.py`.
sys.path.insert(0, str(Path(__file__).resolve().parent))

import config_builder
import dogfood
import manifest_loader
import verify
import wikibase_api
from wikibase_api import WikibaseApi, WikibaseApiError

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_MANIFESTS = REPO_ROOT / "extensions" / "EmbeddableContent" / "manifests"
DEFAULT_CONFIG_OUT = REPO_ROOT / "seed" / "generated" / "ronzz-wikibase.config.php"
DEFAULT_REPORT_OUT = REPO_ROOT / "seed" / "generated" / "seed-report.wiki"

ANCHOR_LANGUAGE = "en"  # alignment anchor labels are written in English (matches D1)
CLASS_PAYLOAD_LABELS = {
    "quotation content": "content text",
    "code snippet": "code source",
    "mathematical expression": "LaTeX source",
}

SUMMARY_PREFIX = "Seed (issue #6, D2): "


class SeedOrchestrator:
    def __init__(self, args: argparse.Namespace) -> None:
        self.args = args
        self.api = WikibaseApi(args.api_url, args.user, args.password, args.timeout)
        self.properties: list[dict[str, Any]] = []
        self.classes: list[dict[str, Any]] = []
        self.languages: list[dict[str, Any]] = []
        self.lang = args.lang or ""
        self.languages_available: list[str] = []
        # resolved id maps, keyed by en label / lexer / dogfood kind
        self.property_ids: dict[str, str] = {}
        self.class_ids: dict[str, str] = {}
        self.lexer_ids: dict[str, str] = {}
        self.dogfood_ids: dict[str, str] = {}

    # ------------------------------------------------------------ manifests

    def load_manifests(self) -> None:
        manifests = Path(self.args.manifests_dir)
        props_path = manifests / "properties.csv"
        classes_path = manifests / "classes.csv"
        langs_path = manifests / "languages.csv"
        if not props_path.exists():
            raise SystemExit(f"manifests dir has no properties.csv at {manifests}")

        self.properties = manifest_loader.load_properties(props_path)
        self.classes = manifest_loader.load_classes(classes_path)
        self.languages = manifest_loader.load_languages(langs_path) if langs_path.exists() else []
        self.languages_available = manifest_loader.manifest_languages(props_path)
        self.lang = self.args.lang or self.languages_available[0]
        print(f"manifests: {len(self.properties)} properties, {len(self.classes)} classes, "
              f"{len(self.languages)} languages (primary language: {self.lang})")

    # ------------------------------------------------------------- helpers

    def primary(self, row: dict[str, Any]) -> str:
        return row["labels"][self.lang]

    def find(self, label: str, entity_type: str, language: str) -> Optional[str]:
        if self.args.dry_run:
            return None  # dry-run is fully offline: plan only
        for hit in self.api.search_entities(label, entity_type, language):
            return hit.get("id")
        return None

    def ensure_login(self) -> None:
        if self.args.dry_run:
            return
        try:
            self.api.login()
        except WikibaseApiError as exc:
            raise SystemExit(f"authentication required: {exc}") from exc

    # ------------------------------------------------------------- phases

    def run_phase(self, phase: str) -> None:
        if phase in self.args.only:
            getattr(self, f"phase_{phase}")()
        elif not self.args.only:
            getattr(self, f"phase_{phase}")()

    def phase_properties(self) -> None:
        print("— properties")
        equiv_id = None  # 'equivalent property', resolved lazily
        for row in self.properties:
            label = self.primary(row)
            existing = self.find(label, "property", self.lang)
            if existing:
                self.property_ids[row["labels"][ANCHOR_LANGUAGE]] = existing
                print(f"  skip {label} ({existing})")
                continue
            if self.args.dry_run:
                print(f"  [dry-run] create property {label} ({row['datatype']})")
                continue
            entity_id = self.api.create_property(
                row["labels"], row["descriptions"], row["datatype"], SUMMARY_PREFIX + "create property"
            )
            self.property_ids[row["labels"][ANCHOR_LANGUAGE]] = entity_id
            print(f"  created {label} ({entity_id})")

            if row["align_uri"] or row["align_wikidata"]:
                if equiv_id is None:
                    equiv_id = self.find("equivalent property", "property", ANCHOR_LANGUAGE)
                if equiv_id:
                    urls = [u for u in (row["align_uri"], row["align_wikidata"]) if u]
                    self.api.add_claims(
                        entity_id,
                        {equiv_id: [dogfood.url_claim(equiv_id, url) for url in urls]},
                        SUMMARY_PREFIX + "align property",
                    )

    def phase_classes(self) -> None:
        print("— classes")
        equiv_class_id = None
        for row in self.classes:
            label = self.primary(row)
            existing = self.find(label, "item", self.lang)
            if existing:
                self.class_ids[row["labels"][ANCHOR_LANGUAGE]] = existing
                print(f"  skip {label} ({existing})")
                continue
            if self.args.dry_run:
                print(f"  [dry-run] create class {label}")
                continue
            entity_id = self.api.create_item(
                row["labels"], row["descriptions"], SUMMARY_PREFIX + "create class"
            )
            self.class_ids[row["labels"][ANCHOR_LANGUAGE]] = entity_id
            print(f"  created {label} ({entity_id})")

            if row["align_uri"] or row["align_wikidata"]:
                if equiv_class_id is None:
                    equiv_class_id = self.find("equivalent class", "property", ANCHOR_LANGUAGE)
                if equiv_class_id:
                    urls = [u for u in (row["align_uri"], row["align_wikidata"]) if u]
                    self.api.add_claims(
                        entity_id,
                        {equiv_class_id: [dogfood.url_claim(equiv_class_id, url) for url in urls]},
                        SUMMARY_PREFIX + "align class",
                    )

    def phase_languages(self) -> None:
        print(f"— languages ({len(self.languages)})")
        instance_of_id = self.find("instance of", "property", ANCHOR_LANGUAGE)
        prog_lang_class = self.class_ids.get("programming language")
        equiv_class_id = self.find("equivalent class", "property", ANCHOR_LANGUAGE)
        if self.args.dry_run:
            for row in self.languages:
                print(f"  [dry-run] create language {row['labels'][ANCHOR_LANGUAGE]} ({row['lexer']})")
            return

        for row in self.languages:
            label = row["labels"][ANCHOR_LANGUAGE]
            existing = self.find(label, "item", ANCHOR_LANGUAGE)
            if existing:
                self.lexer_ids[row["lexer"]] = existing
                print(f"  skip {label} ({existing})")
                continue
            entity_id = self.api.create_item(
                row["labels"], row["descriptions"], SUMMARY_PREFIX + "create language item"
            )
            self.lexer_ids[row["lexer"]] = entity_id
            print(f"  created {label} ({entity_id})")

            claims: dict[str, list[dict[str, Any]]] = {}
            if instance_of_id and prog_lang_class:
                claims[instance_of_id] = [dogfood.entity_claim(instance_of_id, prog_lang_class)]
            if equiv_class_id and row["wikidata_qid"]:
                claims.setdefault(equiv_class_id, []).append(
                    dogfood.url_claim(equiv_class_id, f"https://www.wikidata.org/wiki/{row['wikidata_qid']}")
                )
            if claims:
                self.api.add_claims(entity_id, claims, SUMMARY_PREFIX + "classify language item")

    def phase_dogfood(self) -> None:
        print("— dogfood entities")
        if self.args.dry_run:
            for kind in ("person", "book", "quotation", "code", "math"):
                print(f"  [dry-run] create {kind}")
            return

        person_id = self.api.create_item(
            dogfood.PERSON_LABELS, dogfood.PERSON_DESCRIPTIONS, SUMMARY_PREFIX + "create person"
        )
        self.dogfood_ids["person"] = person_id
        print(f"  created person ({person_id})")

        book_id = self.api.create_item(
            dogfood.BOOK_LABELS, dogfood.BOOK_DESCRIPTIONS, SUMMARY_PREFIX + "create book"
        )
        self.dogfood_ids["book"] = book_id
        print(f"  created book ({book_id})")

        instance_of_id = self.find("instance of", "property", ANCHOR_LANGUAGE)
        attributed_to_id = self.find("attributed to", "property", ANCHOR_LANGUAGE)
        source_id = self.find("source", "property", ANCHOR_LANGUAGE)
        date_id = self.find("date", "property", ANCHOR_LANGUAGE)
        content_text_id = self.find("content text", "property", ANCHOR_LANGUAGE)
        code_source_id = self.find("code source", "property", ANCHOR_LANGUAGE)
        latex_id = self.find("LaTeX source", "property", ANCHOR_LANGUAGE)
        prog_lang_id = self.find("programming language", "property", ANCHOR_LANGUAGE)
        python_id = self.lexer_ids.get("python")
        quote_class = self.class_ids.get("quotation content")
        code_class = self.class_ids.get("code snippet")
        math_class = self.class_ids.get("mathematical expression")

        claims = {}
        if instance_of_id and quote_class:
            claims[instance_of_id] = [dogfood.entity_claim(instance_of_id, quote_class)]
        if content_text_id:
            claims.setdefault(content_text_id, []).append(
                dogfood.monolingual_claim(content_text_id, dogfood.QUOTATION_TEXT, ANCHOR_LANGUAGE)
            )
        if attributed_to_id:
            claims.setdefault(attributed_to_id, []).append(
                dogfood.entity_claim(attributed_to_id, person_id)
            )
        if source_id:
            claims.setdefault(source_id, []).append(dogfood.entity_claim(source_id, book_id))
        if date_id:
            claims.setdefault(date_id, []).append(dogfood.time_claim(date_id, "+1843-01-01T00:00:00Z", 9))
        quote_id = self.api.create_item(
            dogfood.QUOTATION_LABELS, dogfood.QUOTATION_DESCRIPTIONS, SUMMARY_PREFIX + "create quotation"
        )
        if claims:
            self.api.add_claims(quote_id, claims, SUMMARY_PREFIX + "populate quotation")
        self.dogfood_ids["quotation"] = quote_id
        print(f"  created quotation ({quote_id})")

        code_claims = {}
        if instance_of_id and code_class:
            code_claims[instance_of_id] = [dogfood.entity_claim(instance_of_id, code_class)]
        if code_source_id:
            code_claims.setdefault(code_source_id, []).append(
                dogfood.url_claim(code_source_id, dogfood.CODE_TEXT)
            )
        if prog_lang_id and python_id:
            code_claims.setdefault(prog_lang_id, []).append(dogfood.entity_claim(prog_lang_id, python_id))
        if attributed_to_id:
            code_claims.setdefault(attributed_to_id, []).append(dogfood.entity_claim(attributed_to_id, person_id))
        code_id = self.api.create_item(
            dogfood.CODE_LABELS, dogfood.CODE_DESCRIPTIONS, SUMMARY_PREFIX + "create code snippet"
        )
        if code_claims:
            self.api.add_claims(code_id, code_claims, SUMMARY_PREFIX + "populate code snippet")
        self.dogfood_ids["code"] = code_id
        print(f"  created code snippet ({code_id})")

        math_claims = {}
        if instance_of_id and math_class:
            math_claims[instance_of_id] = [dogfood.entity_claim(instance_of_id, math_class)]
        if latex_id:
            math_claims.setdefault(latex_id, []).append(
                dogfood.url_claim(latex_id, dogfood.MATH_LATEX)
            )
        if attributed_to_id:
            math_claims.setdefault(attributed_to_id, []).append(dogfood.entity_claim(attributed_to_id, person_id))
        math_id = self.api.create_item(
            dogfood.MATH_LABELS, dogfood.MATH_DESCRIPTIONS, SUMMARY_PREFIX + "create math item"
        )
        if math_claims:
            self.api.add_claims(math_id, math_claims, SUMMARY_PREFIX + "populate math item")
        self.dogfood_ids["math"] = math_id
        print(f"  created math item ({math_id})")

    def phase_config(self) -> None:
        print("— config emission")
        if self.args.dry_run:
            print("  [dry-run] would write config fragment + report")
            return
        snippet = config_builder.build_config(
            self.property_ids, self.class_ids, self.lexer_ids, self.languages_available
        )
        report = config_builder.build_report(
            self.property_ids, self.class_ids, self.lexer_ids, self.dogfood_ids, self.languages_available
        )

        config_out = Path(self.args.config_out)
        config_out.parent.mkdir(parents=True, exist_ok=True)
        config_out.write_text(snippet, encoding="utf-8")
        print(f"  wrote {config_out}")

        report_out = Path(self.args.report_out)
        report_out.parent.mkdir(parents=True, exist_ok=True)
        report_out.write_text(report, encoding="utf-8")
        print(f"  wrote {report_out}")

        if self.args.publish_report:
            page = self.args.publish_report
            self.api.edit_page(page, report, SUMMARY_PREFIX + "publish seed report")
            print(f"  published {page}")

    def phase_verify(self) -> None:
        if self.args.dry_run:
            return
        quote_id = self.dogfood_ids.get("quotation")
        quotation_class = self.class_ids.get("quotation content")
        instance_of = self.property_ids.get("instance of")
        if not (quote_id and quotation_class and instance_of):
            print("self-verification skipped: dogfood/vocabulary missing (run earlier phases)")
            return
        ok = verify.self_verify(
            self.args.api_url,
            self.args.base_url,
            self.args.sparql_url,
            quote_id,
            quotation_class,
            instance_of,
            self.args.timeout,
        )
        if not ok:
            raise SystemExit("self-verification failed")

    # ----------------------------------------------------------------- main

    def run(self) -> int:
        self.load_manifests()
        self.ensure_login()
        self.run_phase("properties")
        self.run_phase("classes")
        self.run_phase("languages")
        self.run_phase("dogfood")
        self.run_phase("config")
        self.run_phase("verify")
        return 0


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="One-time bootstrap of the fresh ronzz-wikibase instance (issue #6, D2)."
    )
    parser.add_argument("--api-url", default="https://wikibase.ronzz.org/api.php")
    parser.add_argument("--base-url", default="https://wikibase.ronzz.org")
    parser.add_argument("--sparql-url", default="https://wikibase.ronzz.org/sparql")
    parser.add_argument("--user", default=None, help="wiki user or 'User@botname'")
    parser.add_argument("--password", default=None)
    parser.add_argument("--manifests-dir", type=Path, default=DEFAULT_MANIFESTS)
    parser.add_argument("--lang", default=None, help="primary language (default: first manifest language)")
    parser.add_argument(
        "--only",
        default="",
        help="comma-separated phases: properties,classes,languages,dogfood,config,verify",
    )
    parser.add_argument("--dry-run", action="store_true", help="plan only, no writes")
    parser.add_argument("--config-out", type=Path, default=DEFAULT_CONFIG_OUT)
    parser.add_argument("--report-out", type=Path, default=DEFAULT_REPORT_OUT)
    parser.add_argument("--publish-report", default="", help="MediaWiki page to publish the report to")
    parser.add_argument("--timeout", type=int, default=60)
    args = parser.parse_args(argv)
    args.only = [p.strip() for p in args.only.split(",") if p.strip()]
    return args


def main(argv: Optional[list[str]] = None) -> int:
    return SeedOrchestrator(parse_args(argv)).run()


if __name__ == "__main__":
    sys.exit(main())
