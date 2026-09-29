"""Unit tests for tools/backfill_page_items.py — the exclusion rules.

Run with: python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import re
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import backfill_page_items as bpi  # noqa: E402


class DefaultExcludeTest(unittest.TestCase):
    """Housekeeping/pseudo pages that must never become items."""

    def setUp(self):
        self.exclude = re.compile(bpi.DEFAULT_EXCLUDE)

    def test_excludes_housekeeping_pseudo_and_test_pages(self):
        excluded = [
            "Main Page",
            "Sandbox",
            "Sandbox:Temp",
            "SPARQL examples",
            "French Literature/fr",
            "Some Article/eo",
            "GeoGebra UX E2E 1790184491317",
            "Page-flow E2E pageitem 1790184491317",
            "E2E upload 1790184491317",
        ]
        for title in excluded:
            with self.subTest(title=title):
                self.assertTrue(self.exclude.search(title),
                                f"{title!r} should be excluded")

    def test_keeps_content_pages(self):
        kept = [
            "Physics", "Vector space", "Euler's method", "Dark matter",
            "Mental disorder", "Two body problem",
        ]
        for title in kept:
            with self.subTest(title=title):
                self.assertFalse(self.exclude.search(title),
                                 f"{title!r} should be kept")


class CollisionTitlesTest(unittest.TestCase):
    """Namespace-collision ghosts (ns-0 titles that now name a namespace)."""

    NAMESPACES = {"person", "source", "collective", "template", "help"}

    def test_flags_namespace_prefixed_titles(self):
        titles = ["Person:Main", "Source:Main", "Collective:Main",
                  "Template:Infobox", "Help:Foo"]
        self.assertEqual(bpi.collision_titles(titles, self.NAMESPACES),
                         set(titles))

    def test_keeps_plain_and_unknown_prefix_titles(self):
        titles = ["Physics", "Euler's method", "Foo:Bar", "French Literature/fr"]
        self.assertEqual(bpi.collision_titles(titles, self.NAMESPACES), set())

    def test_case_insensitive_prefix(self):
        self.assertEqual(bpi.collision_titles(["person:main"], {"person"}),
                         {"person:main"})


if __name__ == "__main__":
    unittest.main()
