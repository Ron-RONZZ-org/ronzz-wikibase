"""Unit tests for tools/usertools/fix_svg_text.py — pure helpers.

No network and no Inkscape required. Run with:
    python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "usertools"))

import fix_svg_text as fx  # noqa: E402

SVG = "http://www.w3.org/2000/svg"
SOD = "http://sodipodi.sourceforge.net/DTD/sodipodi-0.0.dtd"


def _tree(body: str) -> ET.ElementTree:
    return ET.ElementTree(ET.fromstring(f'<svg xmlns="{SVG}">{body}</svg>'))


class NormalizeTest(unittest.TestCase):
    def test_absolute_xy_lines_marked(self):
        tree = _tree(
            '<text><tspan x="10" y="20">a</tspan>'
            '<tspan x="10" y="34">b</tspan></text>'
        )
        warnings: list[str] = []
        self.assertEqual(fx.normalize_tree(tree, warnings), 2)
        self.assertEqual(warnings, [])
        for tspan in tree.getroot().iter(f"{{{SVG}}}tspan"):
            self.assertEqual(tspan.get(f"{{{SOD}}}role"), "line")
        text = tree.getroot().find(f"{{{SVG}}}text")
        self.assertEqual(text.get("x"), "10")
        self.assertEqual(text.get("y"), "20")
        self.assertEqual(
            text.get("{http://www.w3.org/XML/1998/namespace}space"), "preserve"
        )

    def test_single_numeric_dy_converted_to_absolute(self):
        tree = _tree(
            '<text><tspan x="10" y="20">a</tspan>'
            '<tspan x="10" dy="14">b</tspan></text>'
        )
        self.assertEqual(fx.normalize_tree(tree, []), 2)
        tspans = list(tree.getroot().iter(f"{{{SVG}}}tspan"))
        self.assertEqual(tspans[1].get("y"), "34")
        self.assertIsNone(tspans[1].get("dy"))
        self.assertEqual(tspans[1].get(f"{{{SOD}}}role"), "line")

    def test_per_glyph_dy_list_left_inline(self):
        tree = _tree('<text y="10"><tspan x="1" dy="0 0 -2">a</tspan></text>')
        warnings: list[str] = []
        self.assertEqual(fx.normalize_tree(tree, warnings), 0)
        self.assertEqual(len(warnings), 1)
        tspan = tree.getroot().find(f"{{{SVG}}}text/{{{SVG}}}tspan")
        self.assertIsNone(tspan.get(f"{{{SOD}}}role"))

    def test_inline_tspan_not_marked(self):
        tree = _tree(
            '<text x="1" y="1"><tspan fill="red">a</tspan>'
            "<tspan>b</tspan></text>"
        )
        self.assertEqual(fx.normalize_tree(tree, []), 0)


class FileTest(unittest.TestCase):
    def test_malformed_xml_raises(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "bad.svg"
            path.write_text("<svg><text>", encoding="utf-8")
            with self.assertRaises(fx.SvgFixError):
                fx.normalize_file(str(path), str(path), [])

    def test_comments_preserved(self):
        xml = (
            f'<svg xmlns="{SVG}"><!-- keep me -->'
            '<text><tspan x="1" y="1">a</tspan></text></svg>'
        )
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "a.svg"
            path.write_text(xml, encoding="utf-8")
            fx.normalize_file(str(path), str(path), [])
            self.assertIn("<!-- keep me -->", path.read_text(encoding="utf-8"))

    def test_noop_in_place_leaves_file_untouched(self):
        xml = f'<svg xmlns="{SVG}"><rect width="1" height="1"/></svg>'
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "a.svg"
            path.write_text(xml, encoding="utf-8")
            before = path.read_bytes()
            self.assertEqual(fx.normalize_file(str(path), str(path), []), 0)
            self.assertEqual(path.read_bytes(), before)


class FetchHelperTest(unittest.TestCase):
    def test_name_from_url(self):
        self.assertEqual(
            fx._filename_from_url("https://x.org/a/b/diagram.svg", "/nonexistent"),
            "diagram.svg",
        )

    def test_name_gains_svg_extension(self):
        self.assertEqual(
            fx._filename_from_url("https://x.org/foo", "/nonexistent"), "foo.svg"
        )

    def test_is_url(self):
        self.assertTrue(fx.is_url("HTTPS://X.ORG/a.svg"))
        self.assertFalse(fx.is_url("/tmp/a.svg"))

    def test_looks_like_svg(self):
        self.assertTrue(fx._looks_like_svg(b'<?xml version="1.0"?><svg/>'))
        self.assertTrue(fx._looks_like_svg(b"<svg xmlns='...'>"))
        self.assertFalse(fx._looks_like_svg(b"<!DOCTYPE html><html><body>"))


if __name__ == "__main__":
    unittest.main()
