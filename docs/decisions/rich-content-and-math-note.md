# Decision: rich content — quotation wikitext + the math accompanying note

- **Status**: Accepted (Oct 1 2026)
- **Scope**: `wikibase.ronzz.org` — the content items (`Special:AddQuotation`/
  `AddMath` and their Update pages), the embed renderer, `{{#content:}}`,
  `Special:QuotationsOf`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Content items (quotation / code / math) store their payload **escape-at-rest**
(`PayloadCodec`) and rendered it as **escaped text** on every surface — a
`[[File:xxx]]` in a quotation, or a link/emphasis/math in a note, showed as
literal markup. Two requests:

1. **Quotations** should accept rich media (`[[File:xxx]]`) and render it on
   `Special:QuotationsOf` and via `{{#content:Q…}}`.
2. **Math** items should carry an optional **accompanying note** (wikitext,
   including `[[File:xxx]]` and `$…$`/`$$…$$` math), rendered **below** the
   expression by default; `{{#content:Q…|noNote}}` suppresses it.

The instance already renders `$…$`/`$$…$$` through the vendored
**SimpleMathJax (MathJax 3)** extension and `[[File:…]]` through MediaWiki's
parser.

## Decision

### 1. One rich-text renderer (`Content/RichTextRenderer`)

Both the quotation payload and the math note are parsed as **full wikitext**
by MediaWiki's own parser — the XSS boundary (raw HTML is never enabled):

- **In a parser context** (`{{#content:}}`): `Parser::recursiveTagParse()`,
  so the fragment's files/links/modules land in the page's `ParserOutput` and
  the parser-cache dependency is recorded.
- **Outside a parser context** (the embed renderer, `Special:QuotationsOf`):
  a `Parser` is created via `ParserFactory`; the result carries the
  `ParserOutput` module/style metadata (`RichTextResult`).

Parsing is **exception-safe**: a failure degrades to escaped text, never a
500.

### 2. Embed-surface sanitizer interaction

The embed renderer re-sanitizes its generated markup through
`Sanitizer::removeSomeTags()` (defense in depth). Parser output (the media
markup) is substituted **after** that pass via a per-render token, so the
whitelist never escapes `<figure>`/`<img>`. The renderer now returns the
fragment's extra **modules/styles** in `RenderResult`; `Special:Embed` loads
them (e.g. SimpleMathJax for a note's `$…$`).

`video`/`audio`/`svg` remain in the barred-tag list — images render, other
media types do not (security boundary, unchanged).

### 3. The math note

- New **string** property `note` (manifest `properties.csv`, seed
  `config_builder.py`, `EmbeddableContentConfig::notePropertyId()` —
  nullable, so a pre-seed instance renders no note field).
- `SpecialContentFieldMap` adds `note` to the **math** fields only; the flow
  service escapes it at rest and writes the statement; the Add/Update math
  forms expose an optional textarea.
- `{{#content:}}` reads the second argument (case-insensitive `noNote`) via
  `ContentArgs::noNote()`; otherwise the note renders below the expression in
  a `<div class="wb-embed wb-embed-note">`.
- The embed surfaces render the note below the math too.

## Consequences

- **Full wikitext changes how existing quotations render** — `''italic''`,
  `[[links]]`, `$…$` become active (the chosen behaviour). Plain text is
  unchanged.
- `Special:QuotationsOf` parses each listed quotation; a source with many
  quotations pays N parses per load (bounded by `QuotationFinder::MAX_ROWS =
  500`, page never cached). Acceptable at this instance's scale.
- `$…$` in a note is rendered by **SimpleMathJax (MathJax 3)**, not KaTeX;
  the KaTeX engine still renders the structured math expression itself.
- **Re-seed required** at deploy (new `note` property → D1 importers + D2
  seed); the config is nullable so the extension degrades gracefully before
  the re-seed.
- Regression coverage: `run_e2e.py rich` (self-cleaning: quotation wikitext,
  the note on the embed surface, `{{#content:Q|noNote}}`, note-injection
  safety), `SpecialContentFlowServiceTest` / `FieldMapTest` /
  `ContentArgsTest` (pure-PHP), `run_addmath_ux_e2e.mjs` (the note field).
