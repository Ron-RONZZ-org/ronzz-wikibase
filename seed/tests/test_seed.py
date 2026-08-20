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

# Labels the seed (D2) resolves by exact en-label match (see seed_instance.py's
# find() calls and the CONTENT_TYPE map, and config_builder.py for the content
# classes). Dropping any of these breaks the bootstrap, so their presence is
# the contract — not a magic row count.
REQUIRED_PROPERTY_LABELS = [
    "instance of", "equivalent property", "equivalent class", "formatter URL",
    "attributed to", "content text", "code source", "LaTeX source",
]
REQUIRED_CLASS_LABELS = [
    "quotation content", "code snippet", "mathematical expression", "programming language",
]
INSTANCE_LANGUAGES = {"en", "fr", "eo"}  # instance language policy


class ManifestLoaderTest(unittest.TestCase):
    def assert_trilingual(self, row, context):
        missing = INSTANCE_LANGUAGES - set(row["labels"])
        self.assertEqual(missing, set(), f"{context}: missing manifest languages: {sorted(missing)}")

    def test_load_properties_bundled(self):
        rows = manifest_loader.load_properties(MANIFESTS / "properties.csv")
        self.assertTrue(rows)
        for label in REQUIRED_PROPERTY_LABELS:
            self.assertTrue(
                any(r["labels"]["en"] == label for r in rows),
                f'required property label "{label}" missing from the bundled manifest',
            )
        for row in rows:
            self.assert_trilingual(row, row["labels"]["en"])
        instance_of = next(r for r in rows if r["labels"]["en"] == "instance of")
        self.assertEqual(instance_of["datatype"], "wikibase-item")
        self.assertEqual(instance_of["align_uri"], "http://www.w3.org/1999/02/22-rdf-syntax-ns#type")
        content_text = next(r for r in rows if r["labels"]["en"] == "content text")
        self.assertIsNone(content_text["align_wikidata"])
        orcid = next(r for r in rows if r["labels"]["en"] == "ORCID")
        self.assertEqual(orcid["datatype"], "external-id")
        self.assertEqual(orcid["formatter_url"], "https://orcid.org/$1")
        doi = next(r for r in rows if r["labels"]["en"] == "DOI")
        self.assertEqual(doi["formatter_url"], "https://doi.org/$1")
        formatter = next(r for r in rows if r["labels"]["en"] == "formatter URL")
        self.assertEqual(formatter["datatype"], "url")

    def test_load_classes_bundled(self):
        rows = manifest_loader.load_classes(MANIFESTS / "classes.csv")
        self.assertTrue(rows)
        for label in REQUIRED_CLASS_LABELS:
            self.assertTrue(
                any(r["labels"]["en"] == label for r in rows),
                f'required class label "{label}" missing from the bundled manifest',
            )
        for row in rows:
            self.assert_trilingual(row, row["labels"]["en"])
        quotation = next(r for r in rows if r["labels"]["en"] == "quotation content")
        self.assertEqual(quotation["align_uri"], "https://schema.org/Quotation")
        person = next(r for r in rows if r["labels"]["en"] == "person")
        self.assertEqual(person["align_wikidata"], "https://www.wikidata.org/wiki/Q5")

    def test_load_languages_bundled(self):
        rows = manifest_loader.load_languages(MANIFESTS / "languages.csv")
        self.assertTrue(rows)
        for row in rows:
            self.assert_trilingual(row, row["lexer"])
            # Pygments lexer names are lowercase — a case change silently breaks
            # the code-snippet language dropdown contract.
            self.assertEqual(row["lexer"], row["lexer"].lower(), f'lexer "{row["lexer"]}" must be lowercase')
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

    def test_formatter_url_on_non_external_id_rejected(self, tmp_path=None):
        path = Path(__file__).parent / "bad_fmt_props.csv"
        path.write_text(
            "label.en,label.fr,label.eo,description.en,description.fr,description.eo,"
            "datatype,align.uri,align.wikidata,formatter.url\n"
            "x,x,x,d,d,d,string,,,https://example.org/$1\n",
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

    def test_config_fragment_issue7_sections(self):
        snippet = config_builder.build_config(
            property_ids={
                "instance of": "P31",
                "Wikidata ID": "P10", "ORCID": "P11", "DOI": "P12",
                "given name": "P13", "published in": "P14", "formatter URL": "P15",
            },
            class_ids={"person": "Q20", "book": "Q21", "scholarly article": "Q22"},
            lexer_ids={},
            fallback_languages=["fr", "en", "eo"],
            wikidata_class_qids={"person": "Q5", "book": "Q571", "scholarly article": "Q13442814"},
        )
        self.assertIn("'externalIds'", snippet)
        self.assertIn("'orcid' => 'P11'", snippet)
        self.assertIn("'citationMetadata'", snippet)
        self.assertIn("'givenName' => 'P13'", snippet)
        self.assertIn("'formatterUrl' => 'P15'", snippet)
        self.assertIn("'sourceClasses'", snippet)
        self.assertIn("'book' => 'Q21'", snippet)
        self.assertIn("'agentClasses'", snippet)
        self.assertIn("'person' => 'Q20'", snippet)
        # harvest inference maps: Wikidata QID -> local class key
        self.assertIn("'Q571' => 'book'", snippet)
        self.assertIn("'Q5' => 'person'", snippet)
        # issue #24: WikibaseCitation source-class list (self-cite config)
        self.assertIn("$wgWikibaseCitationSourceClasses", snippet)
        self.assertIn("'Q21',", snippet)
        self.assertIn("'Q22',", snippet)

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
