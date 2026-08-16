"""Unit tests for the seed's manifest loader and config builder (D2).

Run with: python3 -m unittest discover -s seed/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import config_builder  # noqa: E402
import manifest_loader  # noqa: E402
from seed_instance import SeedOrchestrator  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
MANIFESTS = REPO_ROOT / "extensions" / "EmbeddableContent" / "manifests"


class ManifestLoaderTest(unittest.TestCase):
    def test_load_properties_bundled(self):
        rows = manifest_loader.load_properties(MANIFESTS / "properties.csv")
        self.assertEqual(len(rows), 11)
        instance_of = next(r for r in rows if r["labels"]["en"] == "instance of")
        self.assertEqual(instance_of["datatype"], "wikibase-item")
        self.assertEqual(instance_of["align_uri"], "http://www.w3.org/1999/02/22-rdf-syntax-ns#type")
        content_text = next(r for r in rows if r["labels"]["en"] == "content text")
        self.assertIsNone(content_text["align_wikidata"])

    def test_load_classes_bundled(self):
        rows = manifest_loader.load_classes(MANIFESTS / "classes.csv")
        self.assertEqual(len(rows), 4)
        quotation = next(r for r in rows if r["labels"]["en"] == "quotation content")
        self.assertEqual(quotation["align_uri"], "https://schema.org/Quotation")

    def test_load_languages_bundled(self):
        rows = manifest_loader.load_languages(MANIFESTS / "languages.csv")
        self.assertEqual(len(rows), 80)
        python = next(r for r in rows if r["lexer"] == "python")
        self.assertEqual(python["labels"]["en"], "Python")

    def test_manifest_languages(self):
        langs = manifest_loader.manifest_languages(MANIFESTS / "properties.csv")
        self.assertEqual(langs, ["fr", "en", "eo"])

    def test_invalid_datatype_rejected(self, tmp_path=None):
        path = Path(__file__).parent / "bad_props.csv"
        path.write_text(
            "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype\n"
            "x,x,x,d,d,d,not-a-datatype\n",
            encoding="utf-8",
        )
        try:
            with self.assertRaises(manifest_loader.ManifestError):
                manifest_loader.load_properties(path)
        finally:
            path.unlink()


class ConfigBuilderTest(unittest.TestCase):
    def test_config_fragment_shape(self):
        snippet = config_builder.build_config(
            property_ids={
                "instance of": "P31", "content text": "P2", "code source": "P3",
                "LaTeX source": "P4", "programming language": "P5", "attributed to": "P6",
                "source URL": "P7", "source": "P8", "date": "P9",
            },
            class_ids={"quotation content": "Q1", "code snippet": "Q2",
                       "mathematical expression": "Q3", "programming language": "Q4"},
            lexer_ids={"python": "Q10", "cpp": "Q11"},
            fallback_languages=["fr", "en", "eo"],
        )
        self.assertIn("$wgEmbeddableContentConfig = [", snippet)
        self.assertIn("'instanceOf' => 'P31'", snippet)
        self.assertIn("'quotation' => 'Q1'", snippet)
        self.assertIn("'python' => 'Q10'", snippet)
        self.assertIn("$wgWikibaseCitationInstanceOf = 'P31';", snippet)
        self.assertIn("'author' => 'P6'", snippet)  # wellKnownReferencePropertyIds
        self.assertIn("['length'] = 50000", snippet)

    def test_report_lists_vocabulary(self):
        report = config_builder.build_report(
            property_ids={"instance of": "P31"},
            class_ids={"quotation content": "Q1"},
            lexer_ids={"python": "Q10"},
            dogfood_ids={"quotation": "Q5"},
            languages=["fr", "en", "eo"],
        )
        self.assertIn("== Properties ==", report)
        self.assertIn("| instance of || P31", report)
        self.assertIn("| quotation || Q5", report)


class SeedPhaseConfigTest(unittest.TestCase):
    """Regression guard: phase_config emits ids.json (issue #6, CI wiring)."""

    def setUp(self):
        import types

        args = types.SimpleNamespace(
            dry_run=False,
            config_out=Path(__file__).parent / "out" / "ronzz-wikibase-config.php",
            report_out=Path(__file__).parent / "out" / "seed-report.wiki",
            ids_out=Path(__file__).parent / "out" / "ids.json",
            publish_report="",
            api_url="http://example.invalid/api.php",
            user=None,
            password=None,
            timeout=5,
            lang=None,
            manifests_dir=str(MANIFESTS),
        )
        self.orchestrator = SeedOrchestrator(args)
        self.orchestrator.property_ids = {"instance of": "P1"}
        self.orchestrator.class_ids = {"quotation content": "Q1"}
        self.orchestrator.lexer_ids = {"python": "Q57"}
        self.orchestrator.dogfood_ids = {"quotation": "Q87"}
        self.orchestrator.languages_available = ["fr", "en", "eo"]

    def test_emits_ids_json(self):
        self.orchestrator.phase_config()
        ids = json.loads((Path(__file__).parent / "out" / "ids.json").read_text())
        self.assertEqual(ids["dogfood"]["quotation"], "Q87")
        self.assertEqual(ids["properties"]["instance of"], "P1")


if __name__ == "__main__":
    unittest.main()
