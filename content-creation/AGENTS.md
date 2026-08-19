# AGENTS.md — content-creation Agent Instructions

wiki content on ronzz-wikibase (wikibase.ronzz.org) are created and edited
through the `mediawiki-mcp-server`MCP tools, not by writing files in this
directory.

When creating content, you MUST follow the [style guide](https://wikibase.ronzz.org/wiki/Help:Contributing/styleGuide)

Translation is out of scope for new pages: ignore it for new pages. Only activate [the translation workflow](./AGENTS-translation.md) when user specifically asks you to.

Deployment context: see [repo root AGENTS.md](../AGENTS.md) for the ronzz-wikibase deployment info.

### `mediawiki-mcp-server`

- The MCP server authenticates as **SeedBot** (user ID 5). Groups: bot,
  bureaucrat, sysop, `*`, user, autoconfirmed.
- Bot credentials live outside this repo at
  `~/.config/mediawiki-mcp/ronzz-wikibase.json` (bot password, username
  `SeedBot@MCP`). Never copy credentials into this repo.
- **`update-page` replaces the FULL page content** — sending a fragment (a
  section or a snippet) silently truncates the page. Always send the
  complete new source, or use `mode=append`/`prepend`/`section` explicitly.
- **Fetch `latestId` + the current source immediately before editing** —
  someone (or you) may have edited since you last read; a stale `latestId`
  causes an edit conflict, and a stale source means you clobber changes.
  If the page moved while you were drafting, **rebase onto the current
  source** (fetch it, merge your changes in, then update) — never push from
  your own stale draft.
- **Verify after every edit**: page size via `prop=revisions` (a size
  collapse means truncation) + rendered HTML via `action=parse`.

## Domain-Specific Rules for Agents

- **File descriptions describe the file's content, never its usage.** A
  file description (the upload summary and the text on the file page) must
  say what the file contains or shows and where it comes from — not where it
  is used or how it was processed. The upload summary is what visitors see
  in `Special:ListFiles`; it cannot be edited in place, so get it right at
  upload time (correcting it means re-uploading a new version). Positive
  example: "Photograph of Earth, the Blue Marble, taken by the Apollo 17
  crew. Public domain (NASA)." Negative example: "Sample image for
  Help:Contributing/richMediaContent" (says where the file is used, not what
  it shows). See `Help:Contributing/richMediaContent#Uploading a file`.
- **Inline code — `<code>` for plain tokens, `<syntaxhighlight inline>`
  when content needs escaping or highlighting.** `<code>` is raw HTML: it
  does not escape its content, so `<`, `>` and `&` inside it are read as
  markup. For short plain tokens with none of those characters (`#`,
  `quarto render`, `latestId`) `<code>` is fine and lighter to render; use
  `<syntaxhighlight lang="…" inline>` for anything containing them, for
  highlighting, and always for blocks. `lang` is required on
  `<syntaxhighlight inline>` (missing it lands the page in
  Category:Pages with syntax highlighting errors); entities are NOT
  decoded inside `<syntaxhighlight>`, so write raw characters
  (`<syntaxhighlight lang="text" inline><pre></syntaxhighlight>`, not
  `&lt;pre&gt;`). When reviewing a page you did not write, grep for
  `<code>` and confirm the content is escape-safe.
- **Rendering pitfalls** (all silent failures — verify the rendered HTML via
  `action=parse` after every edit):
  - `<syntaxhighlight inline>` **at line start renders as a block `<pre>`**
    even with the `inline` attribute — it splits paragraphs/list items. Keep
    inline tags mid-sentence, or put the code inside a table cell.
  - **`||` inside a table cell is a cell separator** — `&& || !` in a cell
    splits it into two columns. Wrap the expression in
    `<syntaxhighlight lang="…" inline>` to protect it.
  - **Tables need `{| … |}` delimiters** — a row fragment like `| Result ||`
    without the opening `{| class="wikitable"` renders as literal text, not
    a table.
  - **External links can't span a newline** — `[https://… URL\nlabel]`
    renders the `[` literally. Keep URL and label on one line.
  - **Unknown custom tags get HTML-escaped** — a placeholder like `<graph>…`
    renders as literal `<graph>` text. Use `<syntaxhighlight inline>`.
  - **A line starting with a space inside a table cell triggers `<pre>`**;
    use `<br/>` to keep single-line cells.
  - **`line highlight="N"` gives a numbered code block with highlighted
    lines** — prefer it over a separate diff block to show what a modifier
    adds.
  - **Raw `<h1>`–`<h6>` become TOC entries** — a raw heading tag adds
    itself to the table of contents. To show heading-sized text in a
    rendered example, use a styled paragraph instead:
    `<p style="font-size:2em;font-weight:bold;">` (inline `style` survives
    the sanitizer; heading tags don't). See
    https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#Basic_example:_Pancake_recipe
    — the browser column uses styled paragraphs.
  - **`<input type="checkbox">` is escaped** — task-list visuals render as
    literal text. Use ☑/☐ glyphs plus a one-line note. See
    https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#GFM_extensions.
  - **Don't write `<tbody>`** — it leaks as literal text; MediaWiki adds
    its own `<tbody>` to a raw `<table>`.
  - **`<code>` inside `<pre>` is escaped** — use a bare `<pre>` for a
    plain code-block visual.
  - **Wikitables and raw HTML blocks render inside `<blockquote>`** —
    nest example tables and rendered results freely inside notes.
- **Reference content (cheatsheets, syntax guides)** — assume the reader
  already knows the topic; these pages are **not** tutorials:
  - **Point to a tutorial, don't write one** — link the canonical tutorial
    at the top and keep the page as syntax reference.
  - **Don't duplicate a sibling cheatsheet** — if another page covers the
    syntax you need, link it and document only what's unique (see
    https://wikibase.ronzz.org/wiki/Cheatsheets:Quarto, whose intro defers
    all Markdown syntax to Cheatsheets:markdown).
  - **Generic content, not instance-coupled** — write against a well-known
    public reference (Wikidata); instance-specific facts (endpoints,
    prefixes, property IDs) belong on the instance's own page (e.g.
    `Help:Contributing/query`), linked from the reference.
  - **Show, don't tell** — every construct gets an example + its **actual,
    verified result** underneath. Abstract fragments (`[] ex:age 42`)
    confuse; real queries with real output teach.
  - **Show results rendered, not raw** — display the result the way the
    reader sees it (a rendered table, list or styled block), not the raw
    intermediate form. When the conversion chain matters (source →
    compiled → rendered), show the stages side by side in one table (see
    https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#Basic_example:_Pancake_recipe).
  - **Keep source and result distinguishable** — show source blocks plain
    so their syntax markers stay visible, and the result in its rendered
    form; if both look alike, the reader cannot tell them apart (see
    https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#Code — the
    fenced-block example).
  - **Verify every example live before writing it** — run the query against
    the endpoint; IDs, counts and outputs change and must be current
    (2026-08-19: P31 vs P1 confusion, HAVING + label-service quirk).
  - **Gotchas need a negative + positive pair** — show the failing example
    (and its wrong result) next to the fixed one, both **inside** the
    `<blockquote>`, as a Source/Result table (negative row + positive row)
    rather than prose (see
    https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#Emphasis and
    https://wikibase.ronzz.org/wiki/Cheatsheets:SPARQL#FILTER).
  - **Notes live under the construct they concern** — place each gotcha in
    the section that covers the construct, never grouped at the page end
    (see https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#Emphasis and
    https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#Escaping). The
    `<blockquote>` already signals "note" — don't write the word "Gotcha"
    on the page.
  - **Compare with without/with side-by-side** — for modifiers (DISTINCT,
    LIMIT, …), a two-column table "without / with" + `line highlight` on the
    key line beats a separate diff block.
  - **Equivalence: N variants, one shared result** — to prove different
    spellings of a construct produce the same result, put the variants side
    by side in N columns and show a single rendered result underneath;
    elide irrelevant rows with `| ...||...|` (see
    https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#Lists — the
    marker and numbering tables).
  - **Subsections for construct families** — split a family of related
    constructs into `===` subsections so the TOC nests and each construct
    gets room to breathe (see
    https://wikibase.ronzz.org/wiki/Cheatsheets:Markdown#Lists —
    subsections Unordered / Ordered).
  - **One running example, established at the top** — reuse it throughout
    the page so readers build familiarity.
  - **Teach intuition, not grammar** — frame patterns as "question → known
    parts → pattern" rather than abstract syntax explanations.
  - **Use real entity IDs, verified at authoring time** (P1 = instance of,
    Q1 = Spike test item — see Constraints and Invariants).
