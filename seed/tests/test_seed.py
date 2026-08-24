"""Unit tests for the seed's manifest loader and config builder (D2).

Run with: python3 -m unittest discover -s seed/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import json
import os
import sys
import types
import unittest
from pathlib import Path
from unittest import mock

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

    def test_load_preseed_bundled(self):
        # issue follow-up: preseed items for Special:AddSoftware comboboxes
        # (operating systems, FOSS licenses, user interfaces) + their parent
        # classes — each item must name a class that exists in classes.csv.
        rows = manifest_loader.load_preseed(MANIFESTS / "preseed.csv")
        self.assertTrue(rows)
        class_labels = {r["labels"]["en"] for r in manifest_loader.load_classes(MANIFESTS / "classes.csv")}
        for row in rows:
            self.assert_trilingual(row, row["labels"]["en"])
            self.assertIn(row["class_label"], class_labels,
                          f'preseed class "{row["class_label"]}" missing from classes.csv')
        labels = [r["labels"]["en"] for r in rows]
        # the exact preseed contract Special:AddSoftware depends on
        for expected in ["Linux", "Android", "Windows", "GNU AGPL-3.0", "MIT License",
                         "GUI (graphical user interface)", "CLI (command-line interface)"]:
            self.assertIn(expected, labels, f"preseed item {expected!r} missing")

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

    def test_config_fragment_foss_sections(self):
        # issue #26: FOSS vocabulary (Special:AddSoftware).
        snippet = config_builder.build_config(
            property_ids={
                "instance of": "P31",
                "developer": "P33", "license": "P34", "operating system": "P35",
                "official website": "P36", "source code repository": "P37",
                "software version": "P38", "has use": "P39", "replaces": "P40",
                "user interface": "P41", "documentation URL": "P42", "image": "P32",
            },
            class_ids={"free and open-source software": "Q16"},
            lexer_ids={},
            fallback_languages=["fr", "en", "eo"],
        )
        self.assertIn("'fossClasses'", snippet)
        self.assertIn("'foss' => 'Q16'", snippet)
        self.assertIn("'fossProperties'", snippet)
        self.assertIn("'developer' => 'P33'", snippet)
        self.assertIn("'license' => 'P34'", snippet)
        self.assertIn("'operatingSystem' => 'P35'", snippet)
        self.assertIn("'officialWebsite' => 'P36'", snippet)
        self.assertIn("'sourceRepository' => 'P37'", snippet)
        self.assertIn("'softwareVersion' => 'P38'", snippet)
        self.assertIn("'hasUse' => 'P39'", snippet)
        self.assertIn("'replaces' => 'P40'", snippet)
        self.assertIn("'userInterface' => 'P41'", snippet)
        self.assertIn("'documentationUrl' => 'P42'", snippet)
        self.assertIn("'image' => 'P32'", snippet)

    def test_config_fragment_image_upload_sections(self):
        """Upload-enhancements vocabulary: the image class + the image-fact
        properties (image author P2093-aligned, additional license
        information unaligned) shared by person/software/collective image
        facts."""
        snippet = config_builder.build_config(
            property_ids={
                "instance of": "P31",
                "image": "P32",
                "license": "P34",
                "image author": "P70",
                "additional license information": "P71",
                "parent organization": "P72",
            },
            class_ids={"image": "Q120", "free and open-source software": "Q16"},
            lexer_ids={},
            fallback_languages=["fr", "en", "eo"],
        )
        self.assertIn("'imageClasses'", snippet)
        self.assertIn("'image' => 'Q120'", snippet)
        self.assertIn("'imageProperties'", snippet)
        self.assertIn("'imageAuthor' => 'P70'", snippet)
        self.assertIn("'imageLicenseInfo' => 'P71'", snippet)
        # The shared image-fact keys also land on person, FOSS and collective
        # properties.
        self.assertIn("'imageAuthor' => 'P70'", snippet)
        self.assertIn("'imageLicenseInfo' => 'P71'", snippet)
        self.assertIn("'parentOrganization' => 'P72'", snippet)

    def test_config_fragment_followup_sections(self):
        """Issue follow-up vocabulary: fictional characters, the journal
        entity, the person OpenAlex author id, the preseed license options —
        and the CC BY-SA data-rights change."""
        snippet = config_builder.build_config(
            property_ids={
                "instance of": "P31",
                "journal (entity)": "P50",
                "OpenAlex author ID": "P51",
                "present in work": "P52",
                "image": "P32",
                "license": "P34",
                "date of birth": "P60",
            },
            class_ids={"fictional character": "Q90"},
            lexer_ids={},
            fallback_languages=["fr", "en", "eo"],
            preseed_ids={"MIT License": "Q200", "GNU GPL-3.0": "Q201"},
        )
        self.assertIn("'fictionalCharacterClasses'", snippet)
        self.assertIn("'fictionalCharacter' => 'Q90'", snippet)
        self.assertIn("'fictionalCharacterProperties'", snippet)
        self.assertIn("'appearsIn' => 'P52'", snippet)
        self.assertIn("'journal' => 'P50'", snippet)
        self.assertIn("'openalexAuthor' => 'P51'", snippet)
        self.assertIn("'licenses'", snippet)
        self.assertIn("'MIT License' => 'Q200'", snippet)
        self.assertIn("'GNU GPL-3.0' => 'Q201'", snippet)
        self.assertIn("'image' => 'P32'", snippet)
        self.assertIn("'license' => 'P34'", snippet)  # personProperties keys
        # CC BY-SA 4.0 data rights (was CC0).
        self.assertIn("['dataRightsUrl'] = 'https://creativecommons.org/licenses/by-sa/4.0/'", snippet)
        self.assertNotIn("publicdomain/zero/1.0", snippet)

    @mock.patch.dict(os.environ, {}, clear=True)
    def test_youtube_key_carry_forward(self):
        """The deploy-injected YouTube key must survive re-emissions: without
        YOUTUBE_API_KEY exported, the previous config's key is preserved
        (hit 2026-08-23 — a deploy silently emptied it); an exported env
        value (incl. an explicit empty one) wins."""
        # No env -> the previous key is carried forward.
        snippet = config_builder.build_config({}, {}, {}, [], {}, previous_youtube_api_key="AIzaPREVIOUS")
        self.assertIn("'youtubeApiKey' => 'AIzaPREVIOUS'", snippet)
        # No env, no previous key -> empty (fresh instance).
        snippet = config_builder.build_config({}, {}, {}, [], {}, previous_youtube_api_key="")
        self.assertIn("'youtubeApiKey' => ''", snippet)

    @mock.patch.dict(os.environ, {"YOUTUBE_API_KEY": "AIzaNEW"}, clear=True)
    def test_youtube_key_env_wins(self):
        """An explicitly exported key rotates the previous one."""
        snippet = config_builder.build_config({}, {}, {}, [], {}, previous_youtube_api_key="AIzaPREVIOUS")
        self.assertIn("'youtubeApiKey' => 'AIzaNEW'", snippet)
        self.assertNotIn("AIzaPREVIOUS", snippet)

    @mock.patch.dict(os.environ, {"YOUTUBE_API_KEY": ""}, clear=True)
    def test_youtube_key_explicit_empty_disables(self):
        """An explicitly exported EMPTY key is an explicit disable — it must
        override the previous key, not be treated as 'unset'."""
        snippet = config_builder.build_config({}, {}, {}, [], {}, previous_youtube_api_key="AIzaPREVIOUS")
        self.assertIn("'youtubeApiKey' => ''", snippet)
        self.assertNotIn("AIzaPREVIOUS", snippet)

    def test_existing_youtube_key_extraction(self):
        """seed_instance.existing_youtube_key reads the previous config's key
        (absent file -> '', no key line -> '', key -> the value)."""
        out = Path(__file__).parent / "out" / "prev-config.php"
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text("<?php\n$wgEmbeddableContentConfig = [ 'youtubeApiKey' => 'AIzaOLD' ];\n", encoding="utf-8")
        args = types.SimpleNamespace(config_out=str(out), dry_run=True, api_url="http://example.invalid/api.php",
                                     user=None, password=None, timeout=5, lang=None, manifests_dir=str(MANIFESTS))
        orch = SeedOrchestrator(args)
        self.assertEqual(orch.existing_youtube_key(Path(out)), "AIzaOLD")
        out.write_text("<?php\n$wgEmbeddableContentConfig = [];\n", encoding="utf-8")
        self.assertEqual(orch.existing_youtube_key(Path(out)), "")
        self.assertEqual(orch.existing_youtube_key(Path(__file__).parent / "out" / "does-not-exist.php"), "")

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
        self.orchestrator.preseed_ids = {"Linux": "Q300"}
        self.orchestrator.languages_available = ["fr", "en", "eo"]

    def test_emits_ids_json(self):
        self.orchestrator.phase_config()
        ids = json.loads((Path(__file__).parent / "out" / "ids.json").read_text())
        self.assertEqual(ids["dogfood"]["quotation"], "Q87")
        self.assertEqual(ids["properties"]["instance of"], "P1")
        self.assertEqual(ids["preseed"]["Linux"], "Q300")


if __name__ == "__main__":
    unittest.main()
