# AGENTS.md — ThemeToggle

## Purpose

`extensions/ThemeToggle` adds a **sun/moon quick toggle** for the Vector 2022
day/night theme. It is loaded on the Vector skins (`vector-2022` and `vector`); the client
module no-ops unless the body carries `skin-vector-2022`. It does not
introduce a second theme system.

## How it works

- `BeforePageDisplay` (`includes/Hooks.php`) adds `ext.themeToggle` on the
  Vector skins; the JS checks `body.skin-vector-2022`.
- `resources/themeToggle.js` inserts a Codex icon button before the Vector
  2022 Appearance menu in the header.
- The button reads the **effective** theme from the
  `skin-theme-clientpref-day|night|os` class on `<html>` and writes the skin's
  own `vector-theme` preference:
  - logged-in users: `action=options` (`mw.Api().saveOption`);
  - anonymous users: the core client-preference cookie
    (`mw.user.clientPrefs.set`).
- It removes/adds the same `skin-theme-clientpref-*` classes as Vector's
  `skins.vector.clientPreferences` module, fires
  `skin-client-preference.change` and syncs the Appearance-menu radio. No
  server preference or extra stylesheet is defined here.

## Constraints

- **Vector 2022 only.** The native night-mode palette lives in the skin; do
  not use this button as a filter-based dark mode on legacy Vector.
- The icon is an inline OOUI `bright`/`moon` SVG so it does not depend on a
  specific icon-module class name.
- Do not add a competing `darkmode` preference: the Appearance menu, this
  button and the server must all read `vector-theme`.

## Tests

- `tests/e2e/run_wiki_ux_e2e.mjs` toggles the button, checks the
  `skin-theme-clientpref-night` class and verifies the choice survives a
  reload.
- `tests/e2e/run_pages_e2e.py` source-checks that the module is registered on
  the Vector 2022 page.

## Deployment

`wfLoadExtension( 'ThemeToggle' );` in `LocalSettings.php` (and
`dev/config/Extensions.php` for CI parity). No seed/config-map change; deploy
= extension rsync + `php-fpm` restart.
