"""Unit tests for tools/wiki_pages.py — the sync state machine (no network).

Run with: python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import wiki_pages as wp  # noqa: E402


def prod(text: str, revid: int = 10) -> dict:
    return {"revid": revid, "timestamp": "2026-01-01T00:00:00Z", "content": text}


def state(text: str, revid: int = 10) -> dict:
    return {"revid": revid, "sha256": wp.sha256(text), "synced_at": "2026-01-01T00:00:00Z"}


class ComputeStatusTest(unittest.TestCase):
    def test_in_sync(self):
        self.assertEqual(
            wp.compute_status(state("same", 10), prod("same", 10), "same"),
            wp.STATUS_IN_SYNC,
        )

    def test_prod_ahead(self):
        self.assertEqual(
            wp.compute_status(state("old", 10), prod("new", 11), "old"),
            wp.STATUS_PROD_AHEAD,
        )

    def test_repo_ahead(self):
        self.assertEqual(
            wp.compute_status(state("old", 10), prod("old", 10), "new"),
            wp.STATUS_REPO_AHEAD,
        )

    def test_conflict_when_both_changed_differently(self):
        self.assertEqual(
            wp.compute_status(state("old", 10), prod("wiki", 11), "repo"),
            wp.STATUS_CONFLICT,
        )

    def test_both_changed_to_same_content_is_not_a_conflict(self):
        self.assertEqual(
            wp.compute_status(state("old", 10), prod("same new", 11), "same new"),
            wp.STATUS_IN_SYNC,
        )

    def test_null_edit_only_moves_the_revid(self):
        # Same text, new wiki revision: a pull must refresh the revid, but it
        # is not a content conflict.
        self.assertEqual(
            wp.compute_status(state("same", 10), prod("same", 11), "same"),
            wp.STATUS_PROD_AHEAD,
        )

    def test_no_state_and_identical_content(self):
        self.assertEqual(
            wp.compute_status(None, prod("same"), "same"),
            wp.STATUS_IN_SYNC,
        )

    def test_no_state_and_different_content_is_conflict(self):
        self.assertEqual(
            wp.compute_status(None, prod("wiki"), "repo"),
            wp.STATUS_CONFLICT,
        )

    def test_new_wiki_page(self):
        self.assertEqual(
            wp.compute_status(None, prod("wiki"), None),
            wp.STATUS_PROD_ONLY,
        )

    def test_new_repo_page(self):
        self.assertEqual(
            wp.compute_status(None, None, "repo"),
            wp.STATUS_REPO_ONLY,
        )

    def test_deleted_wiki_page_is_reported_not_auto_removed(self):
        self.assertEqual(
            wp.compute_status(state("old"), None, "old"),
            wp.STATUS_PROD_MISSING,
        )

    def test_deleted_repo_page_is_reported_not_auto_removed(self):
        self.assertEqual(
            wp.compute_status(state("old"), prod("old"), None),
            wp.STATUS_REPO_MISSING,
        )


class PathForTitleTest(unittest.TestCase):
    def test_css_keeps_its_extension(self):
        self.assertEqual(wp.path_for_title("MediaWiki:Common.css"), "MediaWiki/Common.css")

    def test_wikitext_gets_extension(self):
        self.assertEqual(wp.path_for_title("MediaWiki:Licenses"), "MediaWiki/Licenses.wiki")

    def test_subpage_becomes_a_directory(self):
        self.assertEqual(
            wp.path_for_title("Template:FOSS/Infobox"),
            "Template/FOSS/Infobox.wiki",
        )

    def test_filename_illegal_characters_are_replaced(self):
        self.assertEqual(
            wp.path_for_title('Template:A:B?C*D"E'),
            "Template/A_B_C_D_E.wiki",
        )

    def test_json_keeps_its_extension(self):
        self.assertEqual(
            wp.path_for_title("MediaWiki:Citation-type-map.json"),
            "MediaWiki/Citation-type-map.json",
        )


class ManifestTest(unittest.TestCase):
    def _write(self, data: dict) -> Path:
        handle = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
        json.dump(data, handle)
        handle.close()
        return Path(handle.name)

    def test_accepts_a_valid_manifest(self):
        path = self._write({"pages": [
            {"title": "MediaWiki:Common.css", "path": "MediaWiki/Common.css"},
        ]})
        self.assertEqual(wp.load_manifest(path)["pages"][0]["title"], "MediaWiki:Common.css")

    def test_rejects_path_traversal(self):
        path = self._write({"pages": [
            {"title": "Template:Book", "path": "../Book.wiki"},
        ]})
        with self.assertRaises(wp.SyncError):
            wp.load_manifest(path)

    def test_rejects_duplicate_titles(self):
        path = self._write({"pages": [
            {"title": "Template:Book", "path": "Template/Book.wiki"},
            {"title": "Template:Book", "path": "Template/Book2.wiki"},
        ]})
        with self.assertRaises(wp.SyncError):
            wp.load_manifest(path)

    def test_conflict_report_lists_both_hashes(self):
        rows = [{
            "title": "Template:Book",
            "status": wp.STATUS_CONFLICT,
            "prod": prod("wiki"),
            "repo_text": "repo",
            "state": state("old"),
        }]
        report = wp.conflict_report(rows)
        self.assertIn("Template:Book", report)
        self.assertIn("Wiki revid", report)
        self.assertIn(wp.sha256("wiki")[:12], report)
        self.assertIn(wp.sha256("repo")[:12], report)


if __name__ == "__main__":
    unittest.main()
