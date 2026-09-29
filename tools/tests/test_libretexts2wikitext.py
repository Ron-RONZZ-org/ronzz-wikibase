"""Unit tests for tools/libretexts2wikitext.py — pure helpers.

No network and no pandoc required (the pandoc test is skipped when absent).
Run with: python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import shutil
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import libretexts2wikitext as lt  # noqa: E402


class ExtractMacrosTest(unittest.TestCase):
    def test_parses_arity_and_body(self):
        html = r"\( \newcommand{\inner}[2]{\langle #1, #2 \rangle} \)"
        self.assertEqual(
            lt.extract_macros(html), {"inner": (2, r"\langle #1, #2 \rangle")}
        )

    def test_zero_arity(self):
        html = r"\newcommand{\Span}{\mathrm{span}}"
        self.assertEqual(lt.extract_macros(html), {"Span": (0, r"\mathrm{span}")})

    def test_renewcommand_is_accepted(self):
        html = r"\renewcommand{\inner}[2]{#1,#2}"
        self.assertEqual(lt.extract_macros(html), {"inner": (2, "#1,#2")})

    def test_unescapes_entities_in_body(self):
        html = r"\newcommand{\bra}[1]{\left&lt; #1 \right|}"
        self.assertEqual(lt.extract_macros(html)["bra"], (1, r"\left< #1 \right|"))

    def test_skips_malformed_definition(self):
        self.assertEqual(lt.extract_macros(r"\newcommand{\x"), {})


class ExpandMacrosTest(unittest.TestCase):
    def test_expands_arguments(self):
        macros = {"inner": (2, r"\langle #1, #2 \rangle")}
        self.assertEqual(
            lt.expand_macros(r"\inner{u}{v}", macros), r"\langle u, v \rangle"
        )

    def test_expands_nested_macros(self):
        self.assertEqual(lt.expand_macros(r"\a", {"a": (0, r"\b"), "b": (0, "X")}), "X")

    def test_leaves_unknown_commands(self):
        self.assertEqual(lt.expand_macros(r"\mathbb{R}", {}), r"\mathbb{R}")


class StripNoncontentTest(unittest.TestCase):
    def test_removes_page_index(self):
        self.assertEqual(lt.strip_noncontent(r"\PageIndex{9}"), "")

    def test_removes_label_and_ref(self):
        self.assertEqual(lt.strip_noncontent(r"\label{eqn:det}"), "")
        self.assertEqual(
            lt.strip_noncontent(r"summand of Equation \ref{eqn:det} permutes"),
            "summand of Equation permutes",
        )

    def test_removes_ref_before_punctuation_cleanly(self):
        self.assertEqual(
            lt.strip_noncontent(r"Equation \ref{x}, then"), "Equation, then"
        )

    def test_keeps_visible_tag(self):
        self.assertEqual(lt.strip_noncontent(r"x \tag{9.2.1}"), r"x \tag{9.2.1}")


class NormalizeMathTest(unittest.TestCase):
    def test_inline_and_display_delimiters(self):
        self.assertEqual(lt.normalize_math(r"\(V \)", {}), "$V$")
        self.assertEqual(lt.normalize_math(r"\[ x \]", {}), "$$x$$")

    def test_equation_star_becomes_aligned(self):
        src = r"\begin{equation*}\begin{split}a\\b\end{split}\end{equation*}"
        self.assertEqual(
            lt.normalize_math(src, {}), r"$$\begin{aligned}a\\b\end{aligned}$$"
        )

    def test_display_env_nested_in_brackets_not_double_wrapped(self):
        src = r"\[ \begin{eqnarray*} a & = & b \end{eqnarray*} \]"
        self.assertEqual(
            lt.normalize_math(src, {}), r"$$\begin{aligned}a & = & b\end{aligned}$$"
        )

    def test_expands_page_macros(self):
        macros = {"inner": (2, r"\langle #1, #2 \rangle")}
        self.assertEqual(
            lt.normalize_math(r"\(\inner{u}{v}\)", macros), r"$\langle u, v \rangle$"
        )

    def test_math_that_is_only_a_stripped_macro_is_removed(self):
        self.assertEqual(lt.normalize_math(r"Example \(\PageIndex{9}\)", {}), "Example ")

    def test_label_inside_display_math_is_stripped(self):
        self.assertEqual(
            lt.normalize_math(r"\[ \label{eqn:x} a = b \]", {}), "$$a = b$$"
        )


class CleanWikitextTest(unittest.TestCase):
    def test_tilde_becomes_nbsp_in_prose_only(self):
        self.assertEqual(
            lt.clean_wikitext("Condition~1 and $a~b$"), "Condition&nbsp;1 and $a~b$\n"
        )

    def test_drops_trailing_attribution_rule(self):
        self.assertEqual(lt.clean_wikitext("text\n\n-----\n"), "text\n")


class SlugTest(unittest.TestCase):
    def test_slug_from_url_tail(self):
        url = (
            "https://math.libretexts.org/Bookshelves/Linear_Algebra/"
            "09%3A_Inner_product_spaces/9.01%3A_Inner_Products"
        )
        self.assertEqual(lt.slug_from_url(url), "9.01_Inner_Products")


class ExtractSectionTest(unittest.TestCase):
    def test_extracts_inner_html_of_content_section(self):
        html = (
            "<div>chrome</div>"
            '<section class="mt-content-container"><p>hi <b>there</b></p></section>'
            "<section>other</section>"
        )
        self.assertEqual(lt.extract_section(html), "<p>hi <b>there</b></p>")

    def test_raises_when_no_body_found(self):
        with self.assertRaises(lt.ConversionError):
            lt.extract_section("<div>nothing here</div>")


class RestoreImagesTest(unittest.TestCase):
    def test_replaces_placeholder_and_builds_manifest(self):
        text, manifest = lt.restore_images(
            "@@LTIMG0@@", [("https://x/y/pic.png?v=2", "alt text")]
        )
        self.assertEqual(text, "[[File:pic.png|alt text]]")
        self.assertIn("pic.png", manifest[0])
        self.assertIn("https://x/y/pic.png?v=2", manifest[0])


class PandocConversionTest(unittest.TestCase):
    @unittest.skipUnless(shutil.which("pandoc"), "pandoc not installed")
    def test_html_fragment_to_mediawiki(self):
        self.assertIn("hi", lt.pandoc_html_to_mediawiki("<p>hi</p>"))


if __name__ == "__main__":
    unittest.main()
