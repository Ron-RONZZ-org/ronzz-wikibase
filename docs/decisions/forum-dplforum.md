# Decision: Discussion forum via DPLforum (vendored)

- **Status**: Accepted (Aug 24 2026)
- **Scope**: `wikibase.ronzz.org` — a multi-thread discussion space for future editorial / instance-dev plans
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Editors requested a forum to discuss future editorial and instance-development
plans on RonzzWikiBase. Someone proposed installing the "MiniForum" extension.

**"MiniForum" does not exist as a MediaWiki extension** (verified 2026-08-24:
mediawiki.org page 404s, mediawiki.org full-text search has no such extension,
GitHub has only unrelated projects; the name collides with a DokuWiki plugin
and the MINISFORUM hardware brand). The plausible intended extensions were
evaluated against the stated requirements:

- **Built into RonzzWikiBase MediaWiki, reusing existing helpers — no new
  standalone service.**
- **Lightweight: no auto topic creation per page; only what users need — a
  multi-thread discussion space.**

| Option | Verdict |
|--------|---------|
| **WikiForum** | In-process and maintained (v2.7.0, MW 1.43+), but not lightweight: new DB tables + a one-off `update.php` deploy step (the instance deploy pipeline never runs `update.php`), threads outside normal search/RecentChanges, forum UI in its own Special page silo. |
| **DPLforum** | In-process parser extension: `Forum:` (110) / `Forum_talk:` (111) namespaces, boards and threads are **ordinary wiki pages**, a `<forum>` tag renders board/thread listings. **No DB changes**, no `update.php`. GPL-2.0-or-later, i18n en/fr/eo. Thin upstream maintenance (3.7.3), MW ≥ 1.39.4 — compatible with the instance's 1.46. |
| **DiscussionTools** | WMF-maintained but it *enhances per-page talk*; it is not a board/thread forum space — a different product. |
| **No extension** | A `Forum:` namespace + hub + categories reuses existing machinery but has no thread listing beyond category pages — weakest UX for a multi-thread space. |
| **Custom extension** | Full house-style control but the most work; DPLforum already satisfies every stated requirement at near-zero maintenance. |

## Decision

1. **Vendor DPLforum v3.7.3** as a plain file copy at `extensions/DPLforum/`
   (the instance deploy model — extensions are file copies rsynced from the
   repo, not git checkouts). Upstream commit pinned:
   `18df74f0c05d013c89e8e07a91a3d0545a6be2c3` (provenance in
   `extensions/DPLforum/VENDORED.md`). Runtime files only (no upstream dev
   tooling). GPL-2.0-or-later — compatible with the repo's license posture.
2. **Namespaces**: `Forum:` (110) / `Forum_talk:` (111) registered by the
   extension's `extension.json` (no `$wgExtraNamespaces` entries needed —
   verified free on production). Add only the search/content lines, mirroring
   the FOSS/Person house block: `$wgContentNamespaces[] = NS_FORUM;` and
   `$wgNamespacesToBeSearchedDefault[NS_FORUM] = true;` (custom namespaces are
   not searched by default) — in production `LocalSettings.php` **and** in
   `dev/config/Extensions.php` (CI parity rule).
3. **Threads and boards are wiki pages** (house style): each board is a
   `Forum:` page holding a `<forum>` listing over its own category; each
   thread is a `Forum:<board>/<topic>` subpage carrying that category. Replies
   are indented sections on the thread page; `~~~~` signatures are
   auto-inserted in the Forum namespace (`ExtraSignatureNamespaces`).
   **No auto topic creation** — nothing is generated per content page.
4. **No DB changes, no new services, no `update.php`** — the deploy is the
   standard runbook sequence (backup → rsync + chown → LocalSettings → php-fpm
   restart → cache purge → `rebuildtextindex.php` for the new namespace).
5. **CI/dev parity**: the compose stack bind-mounts `extensions/DPLforum`
   read-only and loads it via `wfLoadExtension('DPLforum')`; a dedicated
   forum E2E (`tests/e2e/run_forum_e2e.py`, self-cleaning) asserts the
   namespace registration and that a created thread appears in its board's
   `<forum>` listing.
6. **Access**: posting uses the wiki's existing page permissions — no
   anonymous posting (instance is anon read-only, registration closed).
7. **On-wiki content** (post-deploy): `Template:Forumheader` /
   `Forumnotice` / `Forumsearch` / `Forumpage` / `Forumheader/preload`,
   `Forum:Index` hub, boards for the stated purpose (editorial plans +
   instance development), `Category:Forums`, a sidebar entry, and a
   `Help:Contributing/forum` subpage.

## Consequences

- Threads are fully native wiki pages: watchlists, history, search, diffs and
  existing permissions apply with zero custom code.
- Listing freshness: boards use `cache=false` in their `<forum>` tag (the
  DPLforum contract for multipage/correct listings); a purge re-renders.
- Upstream maintenance is thin — the vendored commit is pinned and the
  extension surface is small (`src/DPLForum.php` + hooks), so future
  incompatibilities are cheap to patch or fork locally.
- DPLforum ships en/fr/eo messages; `Forum` is the namespace name in all
  three instance languages.
