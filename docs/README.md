# Wikibase — wikibase.ronzz.org

Self-hosted **Wikibase** (structured-data wiki, the software behind Wikidata) on
`ronzz-linux-server-2` (158.178.193.231). Re-enabled Aug 15 2026.

> **Instance deployment details (stack, endpoints, services, access control,
> uploads, media, CLI operations) are documented on the gated wiki** at
> **`RonzzIT:Wikibase`** · **`RonzzIT:Wikibase/Reference`** ·
> **`RonzzIT:Wikibase/CLI`** (wikibase.ronzz.org, `it` user group).
> They were moved out of this public repo on 2026-08-20 — update the wiki pages,
> not this file.

## What stays public (this repo)

- Extension code: EmbeddableContent, WikibaseCitation, LanguageBar (+ the
  shared entity model);
  **DPLforum** (third-party forum, vendored at `extensions/DPLforum/`) +
  **InputBox** (third-party thread-creation field, `extensions/InputBox/`) —
  see their `VENDORED.md` + `docs/decisions/forum-dplforum.md`); **Diagrams**
  (third-party diagram extension — PlantUML/GraphViz/Mscgen server-side +
  Mermaid client-side — vendored at `extensions/Diagrams/` — see
  `VENDORED.md` + `docs/decisions/diagrams.md`); **SimpleMathJax** (third-party
  inline-LaTeX-math extension, `$…$`/`$$…$$` via client-side MathJax 3 —
  vendored at `extensions/SimpleMathJax/` — see `VENDORED.md` +
  `docs/decisions/inline-latex-math.md`); **CSVTable** (house media handler,
  `[[File:data.csv]]` rendered as a wikitable — `extensions/CSVTable/` —
  see `docs/decisions/csv-table-embed.md`); **Pyodide4MediaWiki** (house
  extension, client-side Python execution of `class="pyodide"` code blocks via
  Pyodide/WASM — `extensions/Pyodide4MediaWiki/` — see
  `docs/decisions/pyodide4mediawiki.md`); **ThemeToggle** (house extension,
  Vector 2022 sun/moon quick toggle for the skin's native day/night theme —
  `extensions/ThemeToggle/` — see `docs/decisions/vector-2022-theme-toggle.md`)
- seed/ (bootstrap orchestrator), tools/, tests/, dev/ (CI stack)
- `wiki_pages/` — the tracked production `MediaWiki:` / `Template:` page
  mirror (manifest + state + `tools/wiki_pages.py`; see
  `docs/decisions/wiki-pages-parity.md`)
- `docs/decisions/` — ADR-style design rationale (opaque IDs, ontology alignment,
  raw RDF in Blazegraph, cite-by-QID, static LLM translation, automatic
  languages bar, forum via DPLforum, diagrams via Extension:Diagrams, inline
  LaTeX math via SimpleMathJax, rich content + math note, content-page
  reference toolbar, copy internal mention + quotation attribution,
  fictional-character aliases, source language field, client-side Python via
  Pyodide)
- `docs/contribution-guide.md` — pointer to the on-wiki `Help:Contributing` family
- Editor-facing rules live on-wiki at `Help:Contributing` (public)

## Official documentation & tutorials

- **MediaWiki** — https://www.mediawiki.org/wiki/MediaWiki · Manual: https://www.mediawiki.org/wiki/Manual:Contents
- **Wikibase** — https://www.mediawiki.org/wiki/Wikibase · API docs: https://doc.wikimedia.org/Wikibase/master/php/
- **Wikidata intro** — https://www.wikidata.org/wiki/Wikidata:Introduction
- **SPARQL 1.1 spec** — https://www.w3.org/TR/sparql11-query/
- **Wikidata SPARQL tutorial** — https://www.wikidata.org/wiki/Wikidata:SPARQL_tutorial
- **WDQS** — https://www.mediawiki.org/wiki/Wikidata_Query_Service
- **MediaWiki API** — https://www.mediawiki.org/wiki/API:Main_page
- **Wikibase API** (entity CRUD) — https://www.wikidata.org/w/api.php
- **wikibase-docker** (reference deployment) — https://github.com/wmde/wikibase-docker

## Decisions

Architecture/design choices (ADR-style) live in `docs/decisions/`:
`opaque-id.md` (entity IDs stay opaque Q/P), `ontology-alignment.md` (mirror
properties + equivalence mappings), `raw-rdf-in-blazegraph.md` (two-worlds:
curated entities + native RDF), `cite-by-qid.md` (citations as a derived view),
`static-llm-translation.md` (static LLM-maintained copies, no translation markup),
`automatic-languages-bar.md` (the languages bar is injected by default on
content pages),
`owui-wiki-writer.md` (Open WebUI as the LLM writer studio — MCP endpoint +
least-privilege writer bot), `reuse-file-subdomain-sourcecite.md` (reuse-file
CONTAINS search, subdomain parent inference, Source-page internal citation),
`zotero-source-classes.md` (Zotero/CSL-aligned source classes + class-scoped
entity comboboxes), `addsource-improvements.md` (title-only required fields,
multi-value territorial jurisdiction + international marker, the `text`
catch-all class, and the `bool|string` return-type fix),
`add-forms-fixes-2026-09.md` (case-insensitive entity search, infusion-safe
combobox class scope, `Special:UpdateSoftware` prefill, page-title
normalization, `Special:NewItem` Main-namespace pages),
`classic-page-toolbar.md` (the classic per-kind pages render the same action
toolbar as the Item: page), `csv-table-embed.md` (uploaded CSV rendered as a
wikitable via `[[File:data.csv]]` + the existing-extension survey),
`wiki-bug-fixes-2026-09c.md` (OpenAlex author name split, the clipboard-blocked
upload-copy fallback, and the multiline-display-math `gathered` wrap),
`label-title-and-page-item-linking.md` (the label→title first-letter
capitalization fix + the Main-page→item reverse hook), `upload-enhancements.md`
(`Special:Upload` + Add\* portrait/logo uploads — Wikimedia metadata fetch +
429 blob fallback, semantic license combobox, item-per-upload; round 5: the
Wikimedia SVG-thumbnail source fix + the converted-upload source-radio
preservation), `subpages.md`
(subpages in every namespace, Main included; the breadcrumb needs an existing
parent page), `userspace-title.md` (`User:<name>/<page>` renders as
`<page> by @<name>` with a linked author), `upload-image-tools-and-plain-content.md` (local-file upload
preview + client-side resize + extension auto-correction; `{{#content:}}`
expands to plain wikitext — math via SimpleMathJax, no `.wb-embed` chrome;
the AddMath note sits below Content and shows in the preview),
`semantic-entity-label-language.md` (the AddSource `labelLanguage` field
extended to every semantic-entity Add\*/Update\* form + `addsemanticentity`),
`upload-source-memory-dragpaste.md` (`Special:Upload` remembers the File/Url
source across "upload another" and accepts a dropped/pasted image),
`printable-version.md` (namespace/subpage-stripped print title, centered
authors, the "Print this page" toolbar button, the cover-page popup, and the
browser Save-as-PDF filename),
`law-embed-code.md` (legal provisions are embeddable — the `law` class in
`ContentRenderer`; the Source-page "Copy embed code" button),
`addsource-law-addmore.md` (the `law` form's "Add more" submit + success
popup, parent legislation preserved), `classic-page-label-sync.md` (a direct
label edit renames the sitelinked classic page, a redirect left behind;
backtracking moves it back), `wiki-bug-fixes-2026-10.md` (the self-cited-book
citation duplication + class-suffix strip, the AddMath Note `$$…$$` preview
fix, the defensive AddPerson date normalization, bookExcerpt language
inheritance from the parent book, the content-page "Add section" action, and
the label/description fallback that makes `fr`/`eo`-only items visible in the
search results and on their Item: pages),
`upload-another-fetch.md` (the "upload another" destination tab + same-origin
fetch POST; inline error recovery, file preservation, CSRF refresh), `vector-2022-theme-toggle.md` (Vector 2022 as the default skin +
the sun/moon quick toggle using the native `vector-theme` preference), and
`namespace-rename-cheatsheet.md` (`Cheatsheets` → `Cheatsheet`, namespace
alias as the redirect-equivalent, sitelink and content-link updates).

Later content-page UX: `section-controls-and-editor.md` (each per-section
control in its own `[ ]` bracket pair + "Add section below"/"Delete section",
and the topmost-heading auto-summary), `section-controls-editpage-editor.md`
(the controls extended to every classic article page, and the append editor
rebuilt on core's `EditPage` — built-in Show preview / Show changes, minus the
minor-edit checkbox) and `geogebra-inline-commands.md` (the
`<geogebra>` parser tag: raw GeoGebra commands rendered by the sandboxed
player without an uploaded `.ggb`).

Sync/search follow-ups: `search-items-section.md` (Special:Search surfaces
Wikibase item labels through the shared CONTAINS term search),
`addsource-class-scoped-search.md` (AddSource Wikidata candidates are scoped
to the selected class — book also accepts literary works), and
`wiki-pages-parity.md` (two-way repo ↔ production sync for
`MediaWiki:`/`Template:` pages, with conflict detection and a dedicated bot).
