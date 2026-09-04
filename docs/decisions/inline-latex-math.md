# Decision: Inline LaTeX math (`$…$`) via SimpleMathJax (vendored)

- **Status**: Accepted (Sep 4 2026)
- **Scope**: `wikibase.ronzz.org` — typeset mathematical formulas **inline in
  wiki page prose** with LaTeX-style `$…$` delimiters, client-side
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Editors asked to "enable MathJAX inline latex math support" on the wiki.
Today math exists on the instance only as **structured content items** —
`Special:AddMath` stores a "math snippet" entity (class + payload statement)
rendered with **KaTeX** via `{{#content:Q…}}`/embed surfaces. There is **no
way to write a formula inline in a normal page** (Help:, FOSS:/Source:/
Person: prose, Cheatsheets:, discussion). The request was clarified to mean
**`$…$`-delimiter authoring** (LaTeX/Markdown style) rather than the stock
`<math>`-tag-only Math extension.

Options evaluated (MW 1.46):

| Option | Verdict |
|--------|---------|
| **Extension:SimpleMathJax** (jmnote, v0.9.0, MIT, MW ≥ 1.43, no DB) | **Chosen.** Actively maintained (0.8.8 Dec 2025; 0.9.0 Aug 2026). Renders MathJax 3 client-side; `$wgSmjExtraInlineMath = [['$','$']]` enables `$…$` in prose and `$wgSmjDisplayMath` `$$…$$` block math; `<math>`/`<chem>` tags work too (server-side inert markers). MathJax's `skipHtmlTags` (`pre`/`code`/…) keeps code blocks literal. Small (8 runtime files, no composer deps) → plain file-copy install like DPLforum/Diagrams. |
| **MediaWiki Math extension** (`<math>` tags only) | The maintained standard, but its syntax is `<math>…</math>` — no `$…$` authoring. Rejected against the clarified requirement (its `mathjax` mode would not deliver `$…$`). |
| **Extension:MathJax** (xeyownt, v1.1, 2019) | Explicitly **unmaintained** on mediawiki.org; v1.1 disabled single-`$` support; pre-extension.json parser hooks. Rejected. |
| **House-written parser hook** for `$…$` | Reinventing SimpleMathJax (delimiter safety, escapes, diff/summary skipping, revision config) — against the repo rule to prefer maintained libraries. |

## Decision

1. **Vendor Extension:SimpleMathJax v0.9.0** as a plain file copy at
   `extensions/SimpleMathJax/` (upstream tag `v0.9.0`, commit
   `6fe10e1836d5`; provenance in `extensions/SimpleMathJax/VENDORED.md`).
   Runtime files only, never modified (upgrade = re-vendor). Upstream
   `LICENSE` is MIT (its `extension.json` declares GPL-2.0+ — discrepancy
   documented in VENDORED.md, both compatible with the repo's GPL-2.0-or-later).
2. **MathJax 3.2.2 is self-hosted, never a CDN** (repo rule). The ~24 MB
   `es5/` asset tree is **not committed** — `tools/install-mathjax.sh`
   installs it (pinned, sha256-checked npm tarball) into
   `extensions/SimpleMathJax/resources/MathJax/es5/` in every environment
   (CI runner host before the stack boots; dev checkout; production after
   rsync), mirroring the PlantUML jar pattern.
3. **Syntax enabled on every page** (config in `dev/config/Extensions.php`,
   mirrored in production LocalSettings):
   - `$…$` inline, `$$…$$` display (centered), `<math>…</math>` as the
     inert tag form (usable in templates), `\$` for a literal dollar
     (`$wgSmjDirectMathJax = 'full'`).
   - `$wgSmjUseChem = false` (LaTeX only, no `<chem>`), `$wgSmjEnableMenu =
     false` (no MathJax context menu / a11y fetches).
4. **Client-side rendering only**: the server never builds HTML from TeX —
   `<math>` content is emitted as an escaped `[math]…[/math]` marker inside
   `span.smj-container` (nowiki), and `$…$` content is ordinary escaped page
   text that MathJax typesets in the browser. XSS surface is therefore inert
   server-side text; the XSS probes are in the math E2E.
5. **The structured-math pipeline is unchanged**: KaTeX keeps rendering math
   *snippet content items* (`Special:AddMath`/`{{#content:}}`/embed). The two
   engines coexist — content-item spans carry no `$`, so MathJax never
   double-typesets them.

## Consequences

- **Editors** write `$…$`/`$$…$$` in page prose; help material added in the
  `Help:Contributing` family. Structured, citable math snippets remain the
  `Special:AddMath` flow (data + provenance); free-form page math is
  editorial text.
- **Client-side weight**: every page view ships the `ext.SimpleMathJax`
  module + MathJax (`tex-chtml.js`, ~1.2 MB, browser-cached, fonts lazy).
  Acceptable on this low-traffic instance; upstream `'env'`/`'none'`
  `$wgSmjDirectMathJax` modes exist if we ever want to restrict MathJax to
  pages that actually use math.
- **`$` false positives** (currency `$5`, `$wg…` config talk, `$var`):
  `<pre>`/`<code>`/SyntaxHighlight blocks are skipped by MathJax; a literal
  dollar elsewhere is escaped `\$`. Verified live before/at deploy on pages
  that legitimately discuss `$`.
- **CI/E2E**: server-side contract (extension loaded, `<math>` markers,
  escaped XSS probes, page jsconfig `wgSmjUseCdn:false`, MathJax asset 200)
  runs in CI (`tests/e2e/run_math_e2e.py`); the client-side render itself is
  verified with `tests/e2e/run_math_ux_e2e.mjs` (Playwright) against the dev
  stack/production after deploy.
- **Maintenance**: single-maintainer upstream — pinned vendored copy +
  VENDORED.md + install-script pin; re-vendor on upstream releases.

## References

- `extensions/SimpleMathJax/VENDORED.md`
- `tools/install-mathjax.sh` · `dev/config/Extensions.php`
- `tests/e2e/run_math_e2e.py` · `tests/e2e/run_math_ux_e2e.mjs`
- mediawiki.org: Extension:SimpleMathJax (maintained) vs Extension:MathJax
  (unmaintained) vs Extension:Math (tag-only)
