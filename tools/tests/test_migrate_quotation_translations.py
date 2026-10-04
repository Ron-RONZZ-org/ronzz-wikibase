"""Unit tests for tools/migrate_quotation_translations.py — pure helpers.

Run with: python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import migrate_quotation_translations as mqt  # noqa: E402


def claim(language: str, text: str) -> dict:
    return {
        "id": f"{language}-guid",
        "mainsnak": {
            "snaktype": "value",
            "property": "P2",
            "datatype": "monolingualtext",
            "datavalue": {"value": {"text": text, "language": language}, "type": "monolingualtext"},
        },
        "type": "statement",
        "rank": "normal",
    }


class MonolingualClaimsTest(unittest.TestCase):
    def test_keeps_only_monolingualtext_claims(self):
        string_claim = {
            "mainsnak": {"datatype": "string", "datavalue": {"value": "x"}},
        }
        self.assertEqual(
            [c["id"] for c in mqt.monolingual_claims([claim("en", "a"), string_claim])],
            ["en-guid"],
        )


class TranslationMovesTest(unittest.TestCase):
    def test_single_claim_is_the_original_with_no_moves(self):
        en = claim("en", "Hello")
        base, moves = mqt.translation_moves([en], None)
        self.assertIs(base, en)
        self.assertEqual(moves, [])

    def test_first_claim_is_the_original_by_default(self):
        en, fr, eo = claim("en", "Hello"), claim("fr", "Bonjour"), claim("eo", "Saluton")
        base, moves = mqt.translation_moves([en, fr, eo], None)
        self.assertIs(base, en)
        self.assertEqual(moves, [fr, eo])

    def test_explicit_original_language_wins(self):
        en, fr, eo = claim("en", "Hello"), claim("fr", "Bonjour"), claim("eo", "Saluton")
        base, moves = mqt.translation_moves([en, fr, eo], "fr")
        self.assertIs(base, fr)
        self.assertEqual(moves, [en, eo])

    def test_missing_original_language_yields_no_moves(self):
        en, fr = claim("en", "Hello"), claim("fr", "Bonjour")
        self.assertEqual(mqt.translation_moves([en, fr], "de"), (None, []))

    def test_empty_input(self):
        self.assertEqual(mqt.translation_moves([], None), (None, []))


class MonolingualClaimBuildingTest(unittest.TestCase):
    def test_builds_a_monolingualtext_statement(self):
        built = mqt.monolingual_claim("P32", "Bonjour", "fr")
        self.assertEqual(built["mainsnak"]["property"], "P32")
        self.assertEqual(built["mainsnak"]["datavalue"]["type"], "monolingualtext")
        self.assertEqual(built["mainsnak"]["datavalue"]["value"], {"text": "Bonjour", "language": "fr"})


if __name__ == "__main__":
    unittest.main()
