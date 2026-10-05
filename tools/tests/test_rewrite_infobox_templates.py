"""Unit tests for tools/rewrite_infobox_templates.py — pure rewrites.

Run with: python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
sys.path.insert(0, str(Path(__file__).resolve().parent.parent.parent))

import rewrite_infobox_templates as rit  # noqa: E402


class RewriteTemplateTest( unittest.TestCase ):

    def test_statements_row_becomes_statement_row(self):
        source = "|-\n| Date of birth || {{#statements:date of birth}}"
        self.assertEqual(
            "{{#statement-row:Date of birth|date of birth}}",
            rit.rewrite_template(source),
        )

    def test_osm_row_becomes_statement_row(self):
        source = "|-\n| Place of birth || {{#osm-place:birth}}"
        self.assertEqual(
            "{{#statement-row:Place of birth|osm-birth}}",
            rit.rewrite_template(source),
        )

    def test_source_access_and_image_rows_are_untouched(self):
        for row in (
            "|-\n| Access || {{#source-access:}}",
            '|-\n| colspan="2" style="text-align:center;" | {{{portrait|{{#item-image:}}}}}',
            "{{#quotations-of:}}",
            "{{#child-items-of:}}",
        ):
            self.assertEqual(row, rit.rewrite_template(row))

    def test_multiple_rows_in_a_table(self):
        source = (
            "{| class=\"wikitable\"\n"
            "|-\n| Instance of || {{#statements:instance of}}\n"
            "|-\n| Date of birth || {{#statements:date of birth}}\n"
            "|-\n| Official website || {{#statements:official website}}\n"
            "|}"
        )
        expected = (
            "{| class=\"wikitable\"\n"
            "{{#statement-row:Instance of|instance of}}\n"
            "{{#statement-row:Date of birth|date of birth}}\n"
            "{{#statement-row:Official website|official website}}\n"
            "|}"
        )
        self.assertEqual(expected, rit.rewrite_template(source))


class AddQuotationsByRowTest( unittest.TestCase ):

    def test_adds_the_row_once_before_the_table_close(self):
        source = "{| class=\"wikitable\"\n{{#statement-row:ISNI|ISNI}}\n|}"
        once = rit.add_quotations_by_row(source)
        self.assertIn("{{#quotations-by:}}", once)
        self.assertLess(once.index("{{#quotations-by:}}"), once.index("\n|}"))
        self.assertEqual(once, rit.add_quotations_by_row(once))

    def test_leaves_a_template_without_a_table_alone(self):
        source = "no table here"
        self.assertEqual(source, rit.add_quotations_by_row(source))


if __name__ == "__main__":
    unittest.main()
