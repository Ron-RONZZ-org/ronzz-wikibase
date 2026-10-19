# Decision: automatic section anchors + `Category:Proper name`

- **Status**: Accepted (2026-10-19)
- **Scope**: `wikibase.ronzz.org` — the per-section copy controls
  (`resources/sectionactions.js`), `includes/Hooks.php`,
  `includes/Spec/{SectionAnchor,HeadingAnchors,MentionSnippet}.php`, and the
  on-wiki `Category:Proper name`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Two editor-facing link conventions were manual:

1. **Math headings are unlinkable.** MediaWiki derives a section id from the
   heading text, so `=== Symmetric groups $\mathcal{S}_{n}$ ===` gets
   `Symmetric_groups_$\mathcal{S}_{n}$`; `{`/`}` are illegal in a wikitext
   fragment and `[[Permutation#…]]` never parses. Contributors had to add
   `{{anchor|Symmetric_groups}}` by hand (`Help:Contributing/links`
   §Explicit anchors, `Template:Anchor`). The per-section controls only used
   that explicit anchor; without one they copied the unusable id.
2. **The mention case rule guessed.** `Spec\MentionSnippet` lowercased the
   Main-namespace first letter unless **every word** started uppercase. That
   kept `[[Permutation]]` (a single capitalised word) as a proper name — the
   exact opposite of the sentence-case convention, and there was no way to
   say "this page really is a proper name" other than the heuristic.

## Decision

### 1. Automatic section anchors

- **`Spec\SectionAnchor`** (pure, unit-tested) derives the anchor from the
  heading id:
  - remove every balanced `$…$` segment (an escaped `\$` is a literal
    dollar);
  - if nothing but separators remains — the heading is math ONLY — keep the
    math, because stripping it would leave no anchor at all;
  - replace `# < > [ ] | { }` with `_`, collapse runs of `_`, trim `_`.
- **`Spec\HeadingAnchors`** (pure, unit-tested) runs from
  `Hooks::onParserAfterTidy`. For each `<h1>`–`<h6>` whose generated id needs
  normalisation and that has **no explicit `{{anchor}}`**, it injects
  `<span class="wb-section-auto-anchor" id="…"></span>` **after** MediaWiki's
  legacy escaped span (the explicit-anchor span shape). The heading's own id
  is never changed, so existing links keep working; the auto span is an
  additional alias. New ids are deduplicated case-insensitively (`_2`, `_3`,
  …) against every id already on the page.
- **`resources/sectionactions.js`** preference: explicit `{{anchor}}` →
  automatic anchor → generated heading id. The explicit anchor stays the
  contributor's override; both copy controls (mention and URL) use the same
  value.
- Because the span is in the parsed HTML, a copied fragment resolves on
  initial load (no JS scroll race) and without JavaScript.

### 2. `Category:Proper name`

- `Spec\MentionSnippet::fromPrefixedTitle()` gains an `$isProperName` flag.
  The Main-namespace first letter is lowercased unless the page is in
  **`Category:Proper name`** (a hidden category). Non-Main namespaces keep
  their exact title (`Help:Contributing`).
- `Hooks::wireSectionControls()` reads the page's parsed categories
  (`OutputPage::getCategories()`) and passes the flag; the page-level and
  per-section mentions share the same `wbReferenceSnippet`.
- The old "every word capitalised" heuristic is **removed**: `Permutation`
  → `[[permutation]]`; `Main Page` stays `[[Main Page]]` only because the
  page is categorised.
- The category page is created on the wiki with `__HIDDENCAT__` and a short
  note; the existing proper-name titles (`Main Page`, `LLM`, `SPARQL
  examples`, `UFR-SciFA (Université de Lorraine)`, `French Literature/fr`,
  `Premier empire français`) are added to it.

## Consequences

- No vocabulary, seed or LocalSettings change. Deploy = extension rsync +
  chown + `php8.3-fpm` restart + **parser cache purge** (the
  `ParserAfterTidy` output changed) + objectcache/message purge.
- Explicit `{{anchor}}` remains available for a custom name; the template and
  `Help:Contributing/links` now describe the automatic behaviour.
- A page title containing balanced math is **not** stripped: the link target
  must stay verbatim or it points at another page. The rule applies to the
  section fragment only.
- Pure-math headings without an explicit anchor get the normalised math as
  the anchor (e.g. `$\operatorname_sign_(\sigma)$`) — linkable, if not
  pretty; add `{{anchor}}` for a readable name.
- Regression coverage: `SectionAnchorTest`, `HeadingAnchorsTest`,
  `MentionSnippetTest`, and `run_wiki_ux_e2e.mjs` (mixed math, pure math,
  explicit anchor wins, plain heading unchanged, `Category:Proper name`
  keeps the capital initial, non-category page is sentence-cased).

## References

- `extensions/EmbeddableContent/includes/Spec/SectionAnchor.php`,
  `includes/Spec/HeadingAnchors.php`, `includes/Spec/MentionSnippet.php`,
  `includes/Hooks.php` (`onParserAfterTidy`, `wireSectionControls`),
  `resources/sectionactions.js`
- `docs/decisions/content-page-reference-toolbar.md` (superseded case rule),
  `docs/decisions/section-controls-editpage-editor.md` (explicit anchors)
- On-wiki: `Help:Contributing`, `Help:Contributing/links`,
  `Template:Anchor`, `Category:Proper name`
