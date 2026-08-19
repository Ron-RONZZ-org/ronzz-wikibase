# AGENTS.md — content-creation Agent Instructions

## Summary

Creating and editing wiki content on ronzz-wikibase (wikibase.ronzz.org)
through the `mediawiki-mcp-server`. All pages referenced below are **live wiki
pages** — edit them via the MCP tools, never by writing files in this
directory.

## Purpose and Expected Behavior

This module governs **on-wiki content**: entity terms (labels, descriptions,
aliases) and classic wiki pages (`Help:` namespace). It is the enforcement
point of the instance language policy (en/fr/eo) as taught by the
`Help:Contributing` family.

Translation is deliberately out of scope here: it is a rare,
approval-gated workflow. If the team leader has approved marking a page,
load **`AGENTS-translation.md`** in this directory — it holds the marking
workflow, translation policy and markup rules.

Deployment context: see [repo root AGENTS.md](../AGENTS.md) for the
ronzz-wikibase deployment info, then the `mediawiki-mcp-server` section below
for wiki-side identity.

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
- **Verify after every edit**: page size via `prop=revisions` (a size
  collapse means truncation) + rendered HTML via `action=parse`.

## Constraints and Invariants

- **Language policy**: official languages are **en, fr, eo**, best-effort.
  Guides must not mention other languages (adding languages is deliberately
  undecided). Content should be multilingual: entity terms (labels,
  descriptions, aliases) and wiki pages — wiki pages get translation markup
  only once approved (see `AGENTS-translation.md`).
- Examples must use real entities: P1 = "instance of" (no aliases yet),
  Q1 = "Spike test item". Do not copy Wikidata P-numbers (ontology alignment
  as data, equivalence statements instead).
- Credentials never enter this repo (see Summary).
- Content is authored **on the wiki** (live pages) — never as files in this
  directory.

## Input/Output Expectations

- **Input**: page titles + wikitext — plain for unapproved pages; entity
  terms via `wikibase-edit-entity`. Translation-marking input and output are
  covered in `AGENTS-translation.md`.

## Documentation Reference

- `docs/contribution-guide.md` — pointer: the editor guide lives on-wiki at
  the `Help:Contributing` family (migrated 2026-08-19)
- Live wiki pages (authored via MCP, never local files):
  - `[[Help:Contributing]]` — hub for contributors
  - `[[Help:Contributing/access]]` — who can do what, logging in, accounts
  - `[[Help:Contributing/entities]]` — data model, properties, items, statements, merge/delete
  - `[[Help:Contributing/query]]` — SPARQL querying
  - `[[Help:Contributing/house-rules]]` — instance rules
  - `[[Help:Contributing/import]]` — importing from external authorities
  - `[[Help:Contributing/api]]` — API and bulk editing
  - `[[Help:Contributing/styleGuide]]` — writing rules (short sentences, no padding, tables for comparison, show don't tell)
  - `[[Help:Contributing/code]]` — code blocks and syntax highlighting
  - `[[Help:Contributing/richMediaContent]]` — media workflow: uploading and embedding files
  - `[[Cheatsheets:Main]]` — dev cheatsheets hub (issue #20; `Cheatsheets:` namespace = 2000)
  - `[[Cheatsheets:SPARQL]]` — pilot cheatsheet (same namespace)
- Translation-specific pages (`Help:Contributing/languages*`) are referenced
  from `AGENTS-translation.md`.

## Domain-Specific Rules for Agents

- **Never use live `<code>` tags on wiki pages — prefer
  `<syntaxhighlight lang="text" inline>`.** `<code>` is raw HTML: it does not
  escape its content (`<` and `&` inside it are read as markup). The code
  guide (`[[Help:Contributing/code]]`) teaches this, so pages must practice
  what the guides preach. Notes: `lang` is required —
  `<syntaxhighlight inline>` without it lands the page in
  Category:Pages with syntax highlighting errors; entities are NOT decoded
  inside `<syntaxhighlight>`, so write raw characters
  (`<syntaxhighlight lang="text" inline><pre></syntaxhighlight>`, not
  `&lt;pre&gt;`).
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
- **Reference content (cheatsheets, syntax guides)** — assume the reader
  already knows the topic; these pages are **not** tutorials:
  - **Point to a tutorial, don't write one** — link the canonical tutorial
    at the top and keep the page as syntax reference.
  - **Generic content, not instance-coupled** — write against a well-known
    public reference (Wikidata); instance-specific facts (endpoints,
    prefixes, property IDs) belong on the instance's own page (e.g.
    `Help:Contributing/query`), linked from the reference.
  - **Show, don't tell** — every construct gets an example query + its
    **actual, verified result** underneath. Abstract fragments
    (`[] ex:age 42`) confuse; real queries with real output teach.
  - **Verify every example live before writing it** — run the query against
    the endpoint; IDs, counts and outputs change and must be current
    (2026-08-19: P31 vs P1 confusion, HAVING + label-service quirk).
  - **Gotchas need a negative + positive pair** — show the failing query
    (and its empty/wrong result) next to the fixed one, both **inside** the
    `<blockquote>`.
  - **Compare with without/with side-by-side** — for modifiers (DISTINCT,
    LIMIT, …), a two-column table "without / with" + `line highlight` on the
    key line beats a separate diff block.
  - **One running example, established at the top** — reuse it throughout
    the page so readers build familiarity.
  - **Teach intuition, not grammar** — frame patterns as "question → known
    parts → pattern" rather than abstract syntax explanations.
  - **Use real entity IDs, verified at authoring time** (P1 = instance of,
    Q1 = Spike test item — see Constraints and Invariants).
- Translation markup rules (opt-in marking, `<translate>` units, tvar
  linking, re-mark/purge cycles) apply only to pages approved by the team
  leader — see `AGENTS-translation.md`.
