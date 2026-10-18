"""Unit tests for tools/migrate_hidden_blocks.py — the pure wikitext transform.

Run with: python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
sys.path.insert(0, str(Path(__file__).resolve().parent.parent.parent))

import migrate_hidden_blocks as mhb  # noqa: E402


def migrate(text: str) -> str:
    return mhb.migrate_wikitext(text)[0]


class LabelSpellingsTest(unittest.TestCase):

    def test_colon_outside_the_bold(self):
        self.assertEqual(
            "{{Hidden proof}}\nbody\n{{End hidden}}\n",
            migrate("'''Proof''':\nbody\n"),
        )

    def test_colon_inside_the_bold(self):
        self.assertEqual(
            "{{Hidden solution}}\nbody\n{{End hidden}}\n",
            migrate("'''Solution:'''\nbody\n"),
        )

    def test_colon_on_both_sides(self):
        self.assertEqual(
            "{{Hidden proof}}\nbody\n{{End hidden}}\n",
            migrate("'''Proof:''':\nbody\n"),
        )

    def test_inline_content_moves_inside_the_block(self):
        self.assertEqual(
            "{{Hidden proof}}\nLet x be even.\nand so on\n{{End hidden}}\n",
            migrate("'''Proof:''' Let x be even.\nand so on\n"),
        )

    def test_case_insensitive_keyword(self):
        self.assertEqual(
            "{{Hidden example}}\nbody\n{{End hidden}}\n",
            migrate("'''example''':\nbody\n"),
        )

    def test_bare_bold_keyword_is_not_a_label(self):
        source = "'''Example'''\nbody\n"
        self.assertEqual(source, migrate(source))

    def test_sentence_example_is_not_a_label(self):
        source = "'''Example: exponential growth and decay.''' A quantity obeys\nbody\n"
        self.assertEqual(source, migrate(source))

    def test_plural_examples_is_not_a_label(self):
        source = "'''Examples:'''\n* one\n* two\n"
        self.assertEqual(source, migrate(source))

    def test_descriptive_label_is_not_migrated(self):
        source = "'''Proof by induction''':\nbody\n"
        self.assertEqual(source, migrate(source))


class BlockBoundaryTest(unittest.TestCase):

    def test_ends_before_next_heading(self):
        source = "'''Proof''':\nbody\n\n== Next ==\nrest\n"
        self.assertEqual(
            "{{Hidden proof}}\nbody\n{{End hidden}}\n\n== Next ==\nrest\n",
            migrate(source),
        )

    def test_ends_before_next_legacy_label(self):
        source = "'''Example''':\nfirst\n\n'''Example''':\nsecond\n"
        self.assertEqual(
            "{{Hidden example}}\nfirst\n{{End hidden}}\n\n"
            "{{Hidden example}}\nsecond\n{{End hidden}}\n",
            migrate(source),
        )

    def test_ends_before_descriptive_label(self):
        source = "'''Proof''':\nfirst\n\n'''Proof by induction''':\nsecond\n"
        self.assertEqual(
            "{{Hidden proof}}\nfirst\n{{End hidden}}\n\n"
            "'''Proof by induction''':\nsecond\n",
            migrate(source),
        )

    def test_ends_before_existing_hidden_directive(self):
        source = (
            "'''Example''':\nbefore\n\n"
            "{{Hidden proof}}\nproof\n{{End hidden}}\n"
        )
        self.assertEqual(
            "{{Hidden example}}\nbefore\n{{End hidden}}\n\n"
            "{{Hidden proof}}\nproof\n{{End hidden}}\n",
            migrate(source),
        )

    def test_label_inside_blockquote_ends_at_blockquote_close(self):
        source = (
            "<blockquote>\n"
            "Note.\n"
            "'''Example''':\n"
            "$$\nx\n$$\n"
            "</blockquote>\n\nAfter.\n"
        )
        self.assertEqual(
            "<blockquote>\n"
            "Note.\n"
            "{{Hidden example}}\n"
            "$$\nx\n$$\n"
            "{{End hidden}}\n\n"
            "</blockquote>\n\nAfter.\n",
            migrate(source),
        )


class SkipTest(unittest.TestCase):

    def test_label_inside_existing_hidden_block_is_skipped(self):
        source = (
            "{{Hidden proof}}\n"
            "'''Proof by induction''':\n"
            "'''Proof''':\n"
            "body\n"
            "{{End hidden}}\n"
        )
        self.assertEqual(source, migrate(source))

    def test_label_inside_nowiki_is_skipped(self):
        source = "<nowiki>\n'''Proof''':\n</nowiki>\n"
        self.assertEqual(source, migrate(source))

    def test_label_inside_syntaxhighlight_is_skipped(self):
        source = '<syntaxhighlight lang="text">\n\'\'\'Proof\'\'\':\n</syntaxhighlight>\n'
        self.assertEqual(source, migrate(source))

    def test_label_inside_html_comment_is_skipped(self):
        source = "<!--\n'''Proof''':\n-->\n"
        self.assertEqual(source, migrate(source))

    def test_unclosed_hidden_block_skips_later_labels(self):
        source = "{{Hidden proof}}\n'''Proof''':\nbody\n"
        new, migrations, _skipped, unclosed = mhb.migrate_wikitext(source)
        self.assertTrue(unclosed)
        self.assertEqual([], migrations)
        self.assertEqual(source, new)

    def test_skip_is_reported(self):
        source = "{{Hidden proof}}\n'''Proof''':\nbody\n{{End hidden}}\n"
        _new, migrations, skipped, _unclosed = mhb.migrate_wikitext(source)
        self.assertEqual([], migrations)
        self.assertEqual(1, len(skipped))
        self.assertEqual("already inside a Hidden template", skipped[0].reason)


class MigrationReportTest(unittest.TestCase):

    def test_line_numbers_and_kinds(self):
        source = "lead\n\n'''Proof''':\nbody\n\n'''Example''':\nbody\n"
        _new, migrations, _skipped, _unclosed = mhb.migrate_wikitext(source)
        self.assertEqual([("proof", 3), ("example", 6)],
                         [(entry.kind, entry.line) for entry in migrations])

    def test_idempotent(self):
        source = (
            "'''Proof''':\nbody\n\n== Next ==\n"
            "'''Solution:'''\nanswer\n"
        )
        once = migrate(source)
        twice = migrate(once)
        self.assertEqual(once, twice)
        self.assertNotIn("'''Proof'''", once)


if __name__ == "__main__":
    unittest.main()
