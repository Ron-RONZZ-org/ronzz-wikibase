# AGENTS.md — CodeBlockSpaces extension

## Summary

Standalone MediaWiki extension that **restores literal spaces inside
`<pre>` / `<code>` blocks**. MediaWiki core applies *French space armoring*
unconditionally: `Sanitizer::armorFrenchSpaces()` (called by
`Parser::internalParse()`) rewrites the space before `! ? : ; % » ›` into
`&#160;` (U+00A0) across the whole parse output — including code. The NBSP
then leaks into copied code and corrupts it.

Concretely, on this instance a `<syntaxhighlight lang="text" copy>` block
carrying a Vim command rendered `submatch(1)&#160;!= …`; the SyntaxHighlight
copy button copies `textContent` (NBSP included), and pasting the command
into nvim made Vim's `\=` replacement expression abort with
`E488: Trailing characters` — Vim then replaced the match with an empty
string, deleting the targeted katex content.

The fix is **server-side and scoped to code**: only `<pre>`/`<code>` content
is un-armored, so French typography in prose is untouched.

## How it works

- `SpaceRestorer::restore()` (pure) replaces `&#160;` / `&nbsp;` with a
  plain space, but only inside `<pre>` and `<code>` elements. The closing tag
  is tied to the opening one (`\2`) so a `<code>` nested in a `<pre>` is
  consumed — and fixed — by the outer match. A fast string guard
  (`str_contains`) short-circuits pages that carry no armored space.
- `Hooks::onParserAfterTidy()` runs **after** core's armoring
  (`Parser::internalParse()` applies the tidy callback before firing
  `ParserAfterTidy`), so it sees the armored text and rewrites it. The
  restored text is what lands in the parser cache and the page HTML.

## Constraints and Invariants

- **Do not touch prose.** The armoring is intentional French typography;
  only `<pre>`/`<code>` are literal code and must keep their real spaces.
- The restorer is **idempotent** and a no-op on HTML without `&#160;`.
- The `&#160;` entity is the only artifact reverted; a user who literally
  typed `&nbsp;` in a code block sees `&amp;nbsp;` (escaped, untouched), so
  only core's armoring is undone.
- No database, seed, manifest or config-map surface — a deploy is a file
  rsync + `wfLoadExtension` + a parser-cache purge (or `$wgCacheEpoch`
  bump), because the fix changes cached parser output.

## Input/Output Expectations

- **Input**: the `ParserAfterTidy` HTML string.
- **Output**: the same HTML with `&#160;`/`&nbsp;` → ` ` inside
  `<pre>`/`<code>`.
- **Unit-test surface**: `tests/Unit/SpaceRestorerTest.php` covers the pure
  restorer (pre, code, nested, attributes, idempotence, prose untouched).
  The MW-bound hook path is covered by
  `tests/e2e/run_codeblock_spaces_e2e.py` (dev-stack CI + live; read-only,
  no credentials — it parses inline `text=` payloads via `action=parse`).

## Documentation Reference

- `../../docs/decisions/code-block-spaces.md` — the ADR (root cause chain,
  the nvim reproduction, the fix scope).
- Upstream context: MediaWiki's `Sanitizer::armorFrenchSpaces()` and the
  SyntaxHighlight `copy` feature (`modules/pygments.copy.js`).

## Domain-Specific Rules for Agents

- i18n changes must ship all three languages (en/fr/eo).
- The extension has no config key; keep it that way unless a real need
  appears (the scope — `<pre>`/`<code>` — is the whole contract).
