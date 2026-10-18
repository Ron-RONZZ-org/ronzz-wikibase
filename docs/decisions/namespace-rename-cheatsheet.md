# Decision: rename the `Cheatsheets` namespace to `Cheatsheet`

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — namespace 2000, sitelinks, on-wiki links,
  runbook/reference pages
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The development-cheatsheet namespace was named `Cheatsheets` (plural). The
request is to rename it to the singular `Cheatsheet` and leave a redirect
behind.

MediaWiki namespaces have **no redirect page**. A namespace is a configuration
entry (`$wgExtraNamespaces[2000] = 'Cheatsheet'`); changing the name re-prefixes
every page in that namespace automatically (the namespace **ID** does not
change). The native equivalent of a redirect is a **namespace alias**:
`$wgNamespaceAliases['Cheatsheets'] = 2000;` makes every old
`Cheatsheets:…` link and URL resolve to the renamed namespace.

Two sitelinks use the old prefix: Q55 ↔ `Cheatsheets:Markdown` and
Q77 ↔ `Cheatsheets:SPARQL`. Wikibase stores sitelink page titles as strings,
so they must be rewritten; otherwise the Sitelink tab turns red.

## Decision

1. Change the canonical namespace name:
   ```php
   $wgExtraNamespaces[NS_CHEATSHEETS] = 'Cheatsheet';
   $wgNamespaceAliases['Cheatsheets'] = NS_CHEATSHEETS;
   ```
   Keep the historical `NS_CHEATSHEETS` constant (other config, e.g.
   `$wgLanguageBarNamespaces`, uses it).
2. Rewrite the two sitelinks through the Wikibase API/MCP
   (`wbsetsitelink`): Q55 → `Cheatsheet:Markdown`, Q77 → `Cheatsheet:SPARQL`.
3. Rewrite in-content old-prefix links (`Cheatsheets:` → `Cheatsheet:`) on the
   public content, Help and gated `RonzzIT:` pages, and in the tracked
   `Template:` pages. **Do not** rename `Category:Cheatsheets` — it is a
   category, not the namespace.
4. Leave historical ADRs/logs as written; this ADR records the rename. Update
   current-state docs (AGENTS/README/reference/runbook) to the new name.
5. Run `rebuildtextindex.php` for the custom namespace and verify
   `Special:Search` / the Sitelink tab.

## Consequences

- Canonical URLs become `https://wikibase.ronzz.org/wiki/Cheatsheet:<Topic>`.
- Old `Cheatsheets:…` links and bookmarks resolve via the namespace alias.
- The category `Category:Cheatsheets`, `Template:Cheatsheet` and the
  `Cheatsheet:Main` hub keep their names (the hub page's title prefix changes
  automatically).
- No page moves and no database page-ID changes.

## Deployment / verification

- Back up `LocalSettings.php`; edit, `php -l`, restart `php8.3-fpm`.
- Update the sitelinks; verify Q55/Q77.
- Verify `meta=siteinfo` namespace 2000 name + `namespacealiases`,
  `Cheatsheet:SPARQL` and `Cheatsheets:SPARQL` both 200, `Special:AllPages`
  (ns 2000), search with `srnamespace=2000`, and the Sitelink tabs.
- Content link rewrite is a normal wiki edit batch; the public content mirror
  picks up the new namespace directory on its next sync.

## References

- `LocalSettings.php` namespace blocks (prod); `dev/config/Extensions.php`
  comments (mirror)
- `wiki_pages/Template/Cheatsheet.wiki`, `Template/DesktopLinux.wiki`
- `tools/wiki_content_mirror.py` (auto-discovers the namespace name from
  siteinfo; docstring/tests updated)
- Historical ADRs that still say `Cheatsheets:`: `automatic-languages-bar.md`,
  `wiki-pages-parity.md`, `subpages.md`, and the older upload/UX ADRs.
