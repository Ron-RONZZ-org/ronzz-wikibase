# Decision: CodeBlockSpaces — literal spaces inside `<pre>`/`<code>` (French-space armoring fix)

- **Status**: Accepted (Sep 29 2026)
- **Scope**: `wikibase.ronzz.org` — the rendered HTML of every `<pre>`/`<code>`
  and SyntaxHighlight block (server-side)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A user reported that the Vim command documented on
`User:Rongzhou/Nvim_Regex#Katex`

```
:%s/\(\\(\(.\{-}\)\\)\)\|\(\\\[\(\_.\{-}\)\\\]\)/\=submatch(1) != '' ? '$'.submatch(2).'$' : '$$'.submatch(4).'$$'/g
```

works when pasted straight into nvim, but **breaks when copied from the wiki
via the SyntaxHighlight copy button**: it "strips the targeted katex content
entirely instead of just replacing delimiters".

### Root cause

1. **MediaWiki core arms the spaces.** `Sanitizer::armorFrenchSpaces()`
   (called unconditionally by `Parser::internalParse()`,
   `includes/Parser/Parser.php:1658`; headings at `:4361`) rewrites the space
   before `! ? : ; % » ›` (and after `« ‹`) into `&#160;` (U+00A0). This is
   MediaWiki's *French space armoring*; it is **not language-gated** (the
   instance runs `$wgLanguageCode = 'en'`), and it applies to code too.
   - Verified on the instance via `action=parse`: `a != b ? c : d` renders as
     `a&#160;!= b&#160;? c&#160;: d` in prose, `<pre>`, `<code>`, and
     `lang="text"` syntaxhighlight.
   - In a tokenizing lexer the punctuation is usually wrapped in a `<span>`,
     so the armoring regex no longer sees ` !` — but `lang="text"` leaves
     everything raw (every space armed), and even `lang="vim"` still leaves
     `?`/`:` raw (those two are still armed). A `lang` change is **not** a fix.
2. **The copy button copies the NBSP.** SyntaxHighlight's
   `modules/pygments.copy.js` does `contentNode.textContent.trim()` and writes
   it to the clipboard; `textContent` carries the NBSP.
3. **Vim then deletes the match.** The NBSP before `!`/`?`/`:` makes the `\=`
   replacement expression unparseable — `E488: Trailing characters` — and Vim
   substitutes the match with an **empty string**, wiping the
   `\(…\)`/`\[…\]` content. Reproduced locally (nvim 0.11.6): the plain
   command yields `$a+b$` / `$$c+d$$`; the NBSP variant leaves both lines
   blank plus `E488`.

## Decision

Add the **`CodeBlockSpaces`** house extension
(`extensions/CodeBlockSpaces/`): a `ParserAfterTidy` hook that replaces
`&#160;`/`&nbsp;` with a plain space **inside `<pre>`/`<code>` only**
(`SpaceRestorer`, pure). `ParserAfterTidy` runs after core's armoring, so the
restored text is what lands in the parser cache and the page HTML. This fixes
the display **and every copy path** (copy button, text selection, no-JS,
`action=parse`), and it is upstreamable.

The fix is deliberately **scoped to code**: prose keeps its French
typography (the armoring is intentional there). The E2E asserts both the
restored code block and the still-armed prose.

## Alternatives considered

| Option | Verdict |
|--------|---------|
| **Server-side `ParserAfterTidy` un-armoring of `<pre>`/`<code>`** (chosen) | Fixes display + all copy paths + API; one-time parse cost; upstreamable. |
| **Client-side JS normalization** of code-block text nodes | Also fixes display + copy for JS users, but leaves the API/no-JS output armored — a band-aid. |
| **Change the code block's `lang`** | Does not work: `lang="text"` arms everything, and even `lang="vim"` still arms `?`/`:` (lexer-dependent, fragile). |
| **Upstream-only** (patch SyntaxHighlight's copy and/or core to skip code) | Correct long-term, not immediately deployable. Tracked separately; the house extension can be retired once core/SyntaxHighlight carry the fix. |

## Consequences

- **Copied code is correct** — the SyntaxHighlight copy button, text
  selection and the API all carry plain spaces inside code blocks. The
  reported Vim command round-trips byte-for-byte.
- **Prose typography is unchanged** (armored as before).
- **Deploy note**: the extension changes cached parser output, so a parser
  cache purge (or a `$wgCacheEpoch` bump) is part of the deploy sequence, like
  any parser-affecting change.
- **Upstream**: the underlying bug is MediaWiki core's blind armoring (and,
  secondarily, the SyntaxHighlight copy feature copying a rendering artifact).
  The house extension is the interim fix; a Phabricator task / Gerrit patch is
  the durable one.
- **CI/E2E**: `tests/e2e/run_codeblock_spaces_e2e.py` (read-only, stdlib,
  `action=parse` with inline `text=` payloads — no page/login) runs in the
  `integration` job; `tests/Unit/SpaceRestorerTest.php` covers the pure
  restorer.
