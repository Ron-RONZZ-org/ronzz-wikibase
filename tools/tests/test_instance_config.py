"""Static guards for the instance config that the WBS CI stack cannot apply.

The dev/CI image's ExtensionLoaders run before /config/Extensions.php and
may build MediaWiki's NamespaceInfo first, so a subpage flag set inside
Extensions.php is too late for the image's runtime. Production's own
LocalSettings sets the flag at the top; this test guards the dev mirror
(and the production deployment is checked live by run_wiki_ux_e2e.mjs).

Run with: python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
EXTENSIONS = ROOT / "dev" / "config" / "Extensions.php"


class MainSubpagesConfigTest(unittest.TestCase):
    """Main-namespace subpages must be enabled before any extension loads."""

    def setUp(self) -> None:
        self.text = EXTENSIONS.read_text(encoding="utf-8")

    def test_range_enables_every_namespace(self) -> None:
        self.assertRegex(
            self.text,
            r"\$wgNamespacesWithSubpages\s*\+=\s*array_fill_keys\(\s*range\(\s*0,\s*2017\s*\),\s*true\s*\)",
        )

    def test_main_namespace_is_explicitly_true(self) -> None:
        self.assertRegex(
            self.text,
            r"\$wgNamespacesWithSubpages\[NS_MAIN\]\s*=\s*true;",
        )

    def test_main_flag_precedes_extension_loading(self) -> None:
        flag = re.search(r"\$wgNamespacesWithSubpages\[NS_MAIN\]\s*=\s*true;", self.text)
        self.assertIsNotNone(flag)
        first_load = self.text.find("wfLoadExtension")
        self.assertGreater(first_load, 0)
        self.assertLess(flag.start(), first_load,
                        "set NS_MAIN subpages before the first wfLoadExtension")


if __name__ == "__main__":
    unittest.main()
