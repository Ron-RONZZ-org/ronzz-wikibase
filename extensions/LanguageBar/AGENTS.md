# AGENTS.md — LanguageBar extension

## Summary

Standalone MediaWiki extension that renders the **languages bar** — the
static-translation switcher linking a content page to its English original
and its `/fr` and `/eo` copies.

The bar is **included by default** on every content page: the extension
prepends it server-side via the `OutputPageBeforeHTML` hook, so editors do
not add `{{Languages}}` by hand. The on-wiki `Template:Languages` is a thin
wrapper around the same builder (the `{{#languagebar:}}` parser function the
extension registers), so the automatic bar and an explicit template call
render identically. This is the static-copy translation model — see
`../../docs/decisions/static-llm-translation.md` and
`../../content-creation/AGENTS-translation.md`.

Not to be confused with the Translate extension's `<languages/>` tag (that
workflow is archived/inert) or with UniversalLanguageSelector.

## How it works

- `LanguageBar::compose()` (pure) assembles the markup:
  `<div class="languages-bar"><p><b>{label}:</b> {links}</p></div>`,
  matching the historical `Template:Languages` rendering (the `<p>` is the
  parser's paragraph wrapping). The bar is styled by the
  `ext.LanguageBar.styles` module (`resources/languages-bar.css`) with Codex
  theme tokens (`--background-color-neutral-subtle`, `--border-color-subtle`,
  `--color-base`) and light-mode fallbacks, so it follows Light/Dark/OS-dark.
  The module is added by BOTH the hook (`$out->addModuleStyles`) and the
  parser function (`$parser->getOutput()->addModuleStyles`).
- `LanguageBar::buildHtml()` (MW-bound) renders the three links — the page
  itself (`English`, as a self-link), `Page/fr` (`français`) and `Page/eo`
  (`Esperanto`). The language names are autonyms and are not translated; the
  `Languages` label follows the reader's interface language (en/fr/eo).
- `Hooks::onOutputPageBeforeHTML()` prepends the bar when the page is an
  article in an enabled namespace. It **skips**:
  - pages that already render a bar (an explicit `{{Languages}}`, directly
    or via a transcluded template) — no duplicate;
  - `/fr` and `/eo` translation copies (they carry the `{{Translation}}`
    banner and link back to the original);
  - diffs (the article flag is set there too).
- `Hooks::onParserFirstCallInit()` registers `{{#languagebar:}}` (optional
  first argument = another page; defaults to the current page).

## Namespaces

`$wgLanguageBarNamespaces` (extension config `LanguageBarNamespaces`) is the
allow-list of namespace IDs. When `null`, it falls back to
`$wgContentNamespaces` + `NS_HELP`. The instance sets an explicit list
(see `dev/config/Extensions.php` and production `LocalSettings.php`) — entity
(`Item:`/`Property:`), `Template:`, `Category:`, `MediaWiki:`, `Special:`,
talk, and the gated `RonzzIT:`/`RonzzInt:` namespaces are excluded.

## Constraints and Invariants

- The bar is **unconditional about translations**: `/fr` and `/eo` links are
  always shown, so untranslated pages carry red links (a deliberate
  translation invitation — ParserFunctions is not installed).
- Keep `LanguageBar::compose()` in sync with the `Template:Languages`
  rendering contract; the parser function and the hook share it.
- The bar must not carry an inline `style=` — that bypasses the theme (the
  old `background:#f8f9fa` stayed light in Dark mode). Style changes go in
  `resources/languages-bar.css` using theme tokens.
- Do not put this logic in EmbeddableContent — it is unrelated (translation
  navigation, not content embedding).

## Input/Output Expectations

- **Input**: the current `Title` (hook) or the parser context (function).
- **Output**: an HTML `<div class="languages-bar">` fragment.
- **Unit-test surface**: `tests/Unit/LanguageBarTest.php` covers the pure
  `compose()`, `isEnabledNamespace()` and `isTranslationSubpageName()`.
  The MW-bound hook/parser paths are covered by
  `tests/e2e/run_languagebar_e2e.py` (dev-stack CI + live).

## Documentation Reference

- `../../docs/decisions/static-llm-translation.md` — the static-copy model
- `../../content-creation/AGENTS-translation.md` — translation convention
- On-wiki: `Help:Contributing/languages` (+ `/translator`)
- `RonzzIT:Deployment/Wikibase` + `RonzzIT:Runbook/Wikibase` — instance ops

## Domain-Specific Rules for Agents

- i18n changes must ship all three languages (en/fr/eo).
- The extension has no database, seed, manifest or config-map surface — a
  deploy is a file rsync + `wfLoadExtension` + a php-fpm restart/cache purge
  (the ResourceLoader module registration + stylesheet are APCu-cached).
