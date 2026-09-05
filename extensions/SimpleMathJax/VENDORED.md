# SimpleMathJax (vendored)

Third-party MediaWiki extension, vendored as a plain file copy (the instance
deploy model — extensions are file copies rsynced from the repo, not git
checkouts).

| | |
|---|---|
| Upstream | https://github.com/jmnote/SimpleMathJax (mediawiki.org: https://www.mediawiki.org/wiki/Extension:SimpleMathJax) |
| Version | 0.9.0 |
| Vendored commit | `6fe10e1836d5377577e679b99ca6cc02f17598c3` (tag `v0.9.0`, 2026-08-01) |
| License | MIT (`LICENSE`, Copyright (c) 2020 Jmnote) — ⚠️ `extension.json` declares `GPL-2.0+`; the file headers carry no notice. Both are permissive/compatible for this repo (house code is GPL-2.0-or-later) |
| MW requirement | `>= 1.43.0` (instance runs 1.46) — uses the 1.43 `MediaWiki\Html\Html` / `MediaWiki\Parser\Sanitizer` namespaces |
| PHP requirement | 8.1+ (instance runs 8.3) |
| DB changes | none |
| Composer runtime deps | none — plain file-copy install works |

Scope of the vendor: runtime files only (`SimpleMathJax.php`,
`SimpleMathJaxHooks.php`, `resources/ext.SimpleMathJax.js`, `extension.json`,
`LICENSE`, `README.md`). Upstream dev tooling (`.github/`, `.editorconfig`)
and the MathJax **submodule pointer** (`.gitmodules` → `resources/MathJax`)
are intentionally not vendored.

## MathJax assets — installed, never committed

The extension renders math client-side with **MathJax 3**, whose `es5/`
build (~24 MB unpacked) lives at `resources/MathJax/es5/` (the upstream git
submodule). This repo does **not** commit those assets — like the pinned
PlantUML jar (`tools/install-plantuml.sh`), they are installed into each
environment (dev/CI/production) by:

```bash
tools/install-mathjax.sh
```

which fetches the **pinned** `mathjax@3.2.2` npm tarball (sha256-checked)
and extracts `package/es5/` into
`extensions/SimpleMathJax/resources/MathJax/es5/`. Run it on the host of any
checkout before booting the dev stack, and on the server after rsyncing the
extension (see `RonzzIT:Runbook/Wikibase`). `resources/MathJax/` is
gitignored (root `.gitignore`).

## Syntax (enabled on every page)

Math is typeset **client-side** by MathJax; the server never builds HTML
from the TeX (XSS-inert — only the `<math>`/`<chem>` *tags* produce
server-side markup, escaped via `Html::Element` + `markerType=nowiki`):

- `$…$` — inline math (configured via `$wgSmjExtraInlineMath`)
- `$$…$$` — display math (configured via `$wgSmjDisplayMath`)
- `[math]…[/math]` — always-on inline delimiters (upstream default)
- `<math>…</math>` / `<chem>…</chem>` — tag form (server-side marker; chem
  disabled on this instance). `<math display="block">` gives block math —
  requires `$wgSmjEnableHtmlAttributes = true` (upstream drops all tag
  attributes otherwise)
- `\$` — literal dollar (escape, `SmjDirectMathJax=full`)

`<pre>`/`<code>`/SyntaxHighlight blocks are skipped by MathJax's
`skipHtmlTags`, so code samples with `$` are untouched. Instance config
lives in `dev/config/Extensions.php` (mirrored in production
LocalSettings); see `docs/decisions/inline-latex-math.md` for the choice
rationale and the `$…$`-vs-currency false-positive risk.

Upgrade = re-vendor the upstream tag + bump `tools/install-mathjax.sh`'s
MathJax pin only if the new upstream requires it. Do not modify the vendored
`src/` without a documented reason.

## Local patch — quote guard for `''`/`'''` inside `$…$` math

**Modified files** (vs upstream tag `v0.9.0`, `6fe10e18`): `extension.json`
(+`InternalParseBeforeLinks` hook, +`SimpleMathJaxQuotes` autoload),
`SimpleMathJaxHooks.php` (+`onInternalParseBeforeLinks`), plus the new file
`SimpleMathJaxQuotes.php`. Regenerate the diff against the upstream tag with
`git diff 6fe10e1836d5377577e679b99ca6cc02f17598c3 -- extensions/SimpleMathJax/`.

**Why**: MediaWiki parses `''`/`'''` in wikitext as italics/bold markup. Inside
MathJax-delimited math they are TeX **primes** (`y''`, `f'''(x)`): the parser
inserted `<i>`/`<b>` inside the delimited text, splitting it so MathJax could
no longer find the closing `$` — the dangling delimiter then swallowed the
surrounding prose as math (hit live on the Power_series page, 2026-09-04).
Single primes (`y'`) are not wikitext markup and were never affected.

**Fix**: an `InternalParseBeforeLinks` handler (runs after nowiki/tag
stripping, before `handleAllQuotes`) that mirrors MathJax v3 `FindTeX`'s
delimiter scanning (longest-first starts, brace/control-sequence skipping,
`processEscapes` `\$`/`\\` skip, `\begin…\end` environments) and replaces
every run of 2+ apostrophes inside math spans with a `Parser::insertStripItem()`
marker — the emphasis pass skips them and the literal `''` is re-inserted
into the final HTML for MathJax to parse as primes. Prose `''italic''` is
untouched. Gated to `$wgSmjDirectMathJax !== 'none'` and to the configured
`$wgSmjExtraInlineMath`/`$wgSmjDisplayMath` delimiter pairs.

**Testing**: pure-PHP unit tests `tests/Unit/SimpleMathJaxQuotesTest.php`
(scanner against a fake protector) + server-side E2E assertions in
`tests/e2e/run_math_e2e.py` (`''` survives inside `$…$`/`$$…$$`, prose
italics still renders).

**Status**: proposed upstream as PR to `jmnote/SimpleMathJax` (this is the
same change). On re-vendor, re-apply the patch or drop it once upstream
merges a version containing it.
