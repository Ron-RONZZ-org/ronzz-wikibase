"""Unit tests for tools/wiki_content_mirror.py — pure helpers (no network).

Run with: python3 -m unittest discover -s tools/tests

License: GPL-2.0-or-later
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import wiki_content_mirror as wm  # noqa: E402


class MirrorPathTest(unittest.TestCase):

    def test_main_namespace(self):
        self.assertEqual("Main/Set.wiki", wm.mirror_path("", "Set"))

    def test_namespaced_page(self):
        self.assertEqual(
            "Source/The book.wiki",
            wm.mirror_path("Source", "Source:The book"),
        )

    def test_subpage_becomes_directory(self):
        self.assertEqual(
            "Cheatsheet/SPARQL/Examples.wiki",
            wm.mirror_path("Cheatsheet", "Cheatsheet:SPARQL/Examples"),
        )

    def test_illegal_characters_are_replaced(self):
        self.assertEqual(
            'Main/A_B_C_D_E.wiki',
            wm.mirror_path("", 'A:B?C*D"E'),
        )

    def test_traversal_components_are_neutralised(self):
        path = wm.mirror_path("", "../escape")
        self.assertEqual("Main/_/escape.wiki", path)
        self.assertNotIn("..", path.split("/"))

    def test_empty_component_becomes_underscore(self):
        self.assertEqual("Main/A/_/B.wiki", wm.mirror_path("", "A//B"))


class PathMapTest(unittest.TestCase):

    def test_builds_title_to_path(self):
        entries = [("Set", ""), ("Cheatsheet:SPARQL", "Cheatsheet")]
        self.assertEqual(
            {
                "Set": "Main/Set.wiki",
                "Cheatsheet:SPARQL": "Cheatsheet/SPARQL.wiki",
            },
            wm.build_path_map(entries),
        )

    def test_case_insensitive_collision_is_refused(self):
        with self.assertRaises(wm.SyncError):
            wm.build_path_map([("A?B", ""), ("A*B", "")])


class ChangedTitlesTest(unittest.TestCase):

    def _state(self, title: str, revid: int, path: str) -> dict:
        return {"pages": {title: {"revid": revid, "path": path}}}

    def test_new_title_is_changed(self):
        self.assertEqual(
            ["Set"],
            wm.changed_titles({"pages": {}}, {"Set": 1}, {"Set": "Main/Set.wiki"}),
        )

    def test_same_revid_and_path_is_not_changed(self):
        state = self._state("Set", 1, "Main/Set.wiki")
        self.assertEqual(
            [],
            wm.changed_titles(state, {"Set": 1}, {"Set": "Main/Set.wiki"}),
        )

    def test_new_revid_is_changed(self):
        state = self._state("Set", 1, "Main/Set.wiki")
        self.assertEqual(
            ["Set"],
            wm.changed_titles(state, {"Set": 2}, {"Set": "Main/Set.wiki"}),
        )

    def test_changed_path_is_changed(self):
        state = self._state("Set", 1, "Main/Set.wiki")
        self.assertEqual(
            ["Set"],
            wm.changed_titles(state, {"Set": 1}, {"Set": "Main/Set2.wiki"}),
        )

    def test_full_refetches_everything(self):
        state = self._state("Set", 1, "Main/Set.wiki")
        self.assertEqual(
            ["Set"],
            wm.changed_titles(state, {"Set": 1}, {"Set": "Main/Set.wiki"}, full=True),
        )


class OrphanTest(unittest.TestCase):

    def test_extra_state_titles_are_orphans(self):
        state = {"pages": {"Old": {"path": "Main/Old.wiki"}, "Set": {}}}
        self.assertEqual(["Old"], wm.orphan_titles(state, {"Set": "Main/Set.wiki"}))

    def test_no_orphans(self):
        state = {"pages": {"Set": {}}}
        self.assertEqual([], wm.orphan_titles(state, {"Set": "Main/Set.wiki"}))


class PruneGuardTest(unittest.TestCase):

    def test_small_prunes_are_allowed(self):
        wm.prune_guard(["Old"], 802)

    def test_mass_prune_is_refused(self):
        with self.assertRaises(wm.SyncError):
            wm.prune_guard([f"Old{i}" for i in range(100)], 802)

    def test_force_allows_mass_prune(self):
        wm.prune_guard([f"Old{i}" for i in range(100)], 802, force=True)


class NamespaceGuardTest(unittest.TestCase):

    def test_gated_namespaces_are_refused(self):
        with self.assertRaises(wm.SyncError):
            wm.ensure_public_namespaces([2006], {2006: "RonzzIT"})

    def test_unknown_namespace_is_refused(self):
        with self.assertRaises(wm.SyncError):
            wm.ensure_public_namespaces([9999], {0: ""})

    def test_public_namespaces_pass(self):
        wm.ensure_public_namespaces([0, 2000], {0: "", 2000: "Cheatsheet"})


class RemoveFileTest(unittest.TestCase):

    def test_removes_file_and_empty_parents(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            target = root / "Source" / "Sub" / "X.wiki"
            target.parent.mkdir(parents=True)
            target.write_text("x", encoding="utf-8")
            wm.remove_file(root, "Source/Sub/X.wiki")
            self.assertFalse(target.exists())
            self.assertFalse((root / "Source").exists())
            self.assertTrue(root.exists())

    def test_refuses_paths_outside_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaises(wm.SyncError):
                wm.remove_file(Path(tmp), "../escape.wiki")


class StateRoundTripTest(unittest.TestCase):

    def test_load_missing_state_is_empty(self):
        with tempfile.TemporaryDirectory() as tmp:
            self.assertEqual(
                {"version": 1, "pages": {}},
                wm.load_state(Path(tmp) / "state.json"),
            )

    def test_write_then_load(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "state.json"
            state = {"version": 1, "pages": {"Set": {"revid": 1}}}
            wm.write_state(path, state)
            loaded = wm.load_state(path)
            self.assertEqual(1, loaded["pages"]["Set"]["revid"])
            self.assertIn("updated", loaded)


if __name__ == "__main__":
    unittest.main()
