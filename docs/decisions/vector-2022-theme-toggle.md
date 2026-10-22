# Decision: Vector 2022 + quick day/night toggle

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — default skin, `extensions/ThemeToggle`,
  `LocalSettings.php` / `dev/config/Extensions.php`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The instance used **legacy Vector (2010)** as its default skin. Legacy Vector
has no built-in night mode. The request was an optional dark theme with a
sun/moon toggle.

The installed Vector extension registers two separate skins:

- `vector` — legacy 2010 (`SkinVectorLegacy`, `body.skin-vector-legacy`);
- `vector-2022` — modern Vector 2022 (`SkinVector22`,
  `body.skin-vector-2022`), which has a **native** day/night/OS theme
  (`vector-theme` / `skin-theme-clientpref-*`, Codex palette).

The gated runbook page `RonzzIT:Wikibase/Reference` wrongly claimed the modern
look was already the default and that `vector-2022` was not a skin. That is
stale and is corrected with this change.

A CSS-filter extension (`Extension:DarkMode`) was considered for legacy
Vector. It would avoid a layout change, but inverts images/colors, costs
performance on large pages and conflicts with the skin's native night mode.
The user preferred the modern layout.

## Decision

1. **Switch the default skin to `vector-2022`**:
   `$wgDefaultSkin = 'vector-2022';` (prod `LocalSettings.php` and
   `dev/config/Extensions.php`). Existing users with no explicit `skin`
   preference get the modern layout; `?useskin=vector` remains available as a
   legacy escape hatch.
2. **Do not vendor a filter-based dark mode.** Use Vector 2022's native
   `vector-theme` client preference (`day` / `night` / `os`), which is
   server-rendered and already appears in the Appearance menu.
3. **Add `extensions/ThemeToggle`** — a small house extension that inserts a
   Codex sun/moon icon button next to the Appearance menu. It writes the same
   `vector-theme` preference:
   - logged-in users: `action=options` (`mw.Api().saveOption`);
   - anonymous users: the core client-preference cookie
     (`mw.user.clientPrefs.set`);
   - it removes/adds the same `skin-theme-clientpref-*` classes, fires
     `skin-client-preference.change`, and syncs the Appearance menu radio.
4. No custom dark palette, no second preference key, no per-page CSS.

## Consequences

- The site gets a maintained, accessible night mode with correct images,
  tables, code blocks and OOXML/Codex components.
- The visible layout changes (sticky header, main menu, new table of contents,
  width/appearance controls). The custom UI was audited: the inline toolbars
  append to `#firstHeading`, the block toolbar/preview rows insert just before
  `#bodyContent` (never after `#firstHeading`, which Vector 2022 wraps in the
  flex `.vector-page-titlebar` — the Item:Q2274 title-squeeze fix), and the
  print interception targets `#t-print`, all present in
  `?useskin=vector-2022`; the sitelink tab uses
  - Vector 2022's `.mw-editsection` sets `line-height: 0`; the custom
    per-section controls needed an explicit `line-height: normal` in
    `extensions/EmbeddableContent/resources/gadget.css`, otherwise the
    `<button>` controls collapse to zero height (found by the production
    browser E2E).
  - `SkinVector22::removeAddTopicButton()` strips the `ca-addsection` Views
    item and substitutes a title-bar button pointing at core
    `action=edit&section=new`. The append action therefore uses the distinct
    `appendsection` key / `ca-appendsection` class, which keeps it in the
    visible Views row and out of that substitution.
  - The section copy controls prefer an explicit `{{anchor|…}}` span over the
    generated heading id (math headings), and Copy URL URL-encodes the
    fragment.
  `SkinTemplateNavigation::Universal`. A visual QA pass still covers the
  content-page toolbar, File: toolbar, print popup, Special:Upload,
  Add*/Update* forms, forum, search and the gated `RonzzIT:` pages.
- `ThemeToggle` is registered on the Vector skins; its JS no-ops unless the
  page is `skin-vector-2022`, so legacy users keep the stock skin and no
  button.
- The runbook's skin section and the stack table are corrected (modern default,
  legacy as an explicit `?useskin=vector` alternative).

## Testing

- `tests/e2e/run_wiki_ux_e2e.mjs`: the sun/moon button exists in the Vector
  2022 user links, switches the effective theme, and the anonymous choice
  survives a reload (then restores day mode).
- `tests/e2e/run_pages_e2e.py`: the `ext.themeToggle` module source contains
  the button/class/`vector-theme` wiring, and a Vector 2022 page loads the
  module.
- Manual visual QA in day and night modes (content, tables, code, infoboxes,
  File:, Special:Upload, Add* forms, search).

## References

- `extensions/ThemeToggle/` (`AGENTS.md`, `extension.json`,
  `resources/themeToggle.js`, `resources/themeToggle.css`, `i18n/`)
- `dev/config/Extensions.php`
- `docs/decisions/upload-another-fetch.md`
- Vector's own `skins.vector.clientPreferences` module (the class/hook
  mechanics reused here)
