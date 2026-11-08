# Decision: namespace index, archive print cover, multi-author citations

- **Status**: Accepted (Nov 2026)
- **Scope**: `wikibase.ronzz.org` — content-page H1s, `Source:*/archivedText`
  pages, `Special:AddSource`, and the WikibaseCitation engine
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Four unrelated friction points surfaced together:

1. `Special:AddSource` carried an unhelpful help line about the archive
   subpage ("The page text is fetched now and stored as a wikitext subpage …").
2. A content page in a namespace (`Source:Beloved`) rendered "Beloved in
   Source", but the namespace name was dead text: there was no way to browse
   all pages of that kind, and "in" was not visually secondary.
3. The automatic archive page (`Source:*/archivedText`) had an unreadable
   disclaimer in Vector 2022 night mode (light amber background, inherited
   near-white text), a `<button>` for dismissal rather than the requested
   `<br><a>Dismiss</a>`, and a print flow that credited the wiki contributors
   and used the literal subpage title "archivedText".
4. WikibaseCitation rendered **only the first author** of a multi-author
   item (Q2937 had 22 `attributed to` statements), in every style, and mapped
   "scholarly article" to the generic CSL `article` type, so APA rendered the
   journal as a monographic container ("In Pediatrics (Vols. …)").

## Decisions

### 1. Namespace index (`Special:EntityIndex`)

- A new special page, `Special:EntityIndex/<namespace id|name>`, lists the
  **top-level, non-redirect** pages of a namespace alphabetically. Subpages
  (translations `/fr`, `/eo`, `/archivedText`, `/quotation`) are excluded.
- Paginated with the core `Special:AllPages` cursor shape (`from`), so inserts
  cannot shift a row across chunks. Page size is `limit`, **clamped to
  10–200, default 100**; the page carries a small GET form.
- Wired from the content-page H1: the namespace name is an index link. The
  numeric namespace id is used in the URL, so localized namespace names do not
  affect the target.
- `NamespacePageTitle::html()` renders
  `page <span class="wb-namespace-in">in</span>
  <span class="wb-namespace-name">Source</span>`. The page-title sanitizer
  keeps `<span class>` but strips `<a>`; a small module
  (`namespacepagetitle.js`) turns the name span into a link. Without JS the
  heading stays "<page> in <namespace>" and its "in" is still muted.
- Applies to **every content namespace** (not only the five classic entity
  namespaces).

### 2. AddSource help text

The `embeddablecontent-source-archive-check-help` message and its
`help-message` wiring are removed; the checkbox label alone is sufficient.

### 3. Archive banner

- The dismiss control is `<br><a href="#" class="wb-sourcearchive-dismiss">`;
  the script calls `preventDefault()` and removes the banner for the current
  view (still no storage; a refresh restores it).
- Screen colours use Codex tokens (`--background-color-warning-subtle`,
  `--color-base`, `--border-color-warning`, `--border-color-destructive`), so
  Vector 2022 night mode stays readable. Print stays forced black on white and
  hides the dismiss link and its line break.

### 4. Archive print cover

- `Hooks::wirePrintModule()` exposes `wbPrintArchive` on archive pages:
  `{ title, class, date, url, filename }`. `title` is the source page title
  without the namespace and without its ` (Class)` suffix; `class` is the
  item's source-class label (title-suffix fallback when the item cannot be
  resolved); `date` is the archive's first revision; `url` is the source's
  `url`/`accessUrl`; `filename` is the source title with the class.
- The print popup keeps the "Add a cover page" checkbox, **default-checked**
  on archive pages. The cover renders
  `<h1>{source title} ({class})</h1>` followed by
  "Text archived on {date} from {url}".
- **No contributor line** is fetched or rendered for an archive page: the
  text is external, not wiki-authored. With the cover unchecked, the header
  still names the source work, never `archivedText`.
- The Save-as-PDF filename uses the source title (not
  `…_archivedText.pdf`).

### 5. Citation authors and type map

- `StatementToCslConverter::addAuthor()` now emits **every non-deprecated
  author statement in the item's stored order**. That order is the byline
  order (the AddSource authors field writes one statement per author);
  statement GUIDs are random and must never be used for ordering.
- `citation-source-type-map.json` maps `scholarly article` to
  `article-journal`, so APA/Vancouver render the serial source
  (`Pediatrics, 136(Supplement_1), S10–S40`) instead of a monographic one.
- The engine emits the full author list to citeproc; the **style** decides
  the display. APA 7 (`et-al-min=21`, `et-al-use-first=19`) keeps the first
  19 + "…" + the last for 21+ authors; Vancouver keeps 6 + "et al."; BibTeX
  and RIS list all authors. This is the standard CSL behaviour and is
  deliberately not overridden.
- Title case and page-range hyphens are **data**, not engine concerns; stored
  values are left as-is.

## Implementation

| Piece | File |
|---|---|
| Index page | `extensions/EmbeddableContent/includes/Spec/SpecialEntityIndex.php`, `Spec/EntityIndexPaging.php` |
| H1 spans + link | `Spec/NamespacePageTitle.php`, `Hooks::applyDisplayTitle()`, `resources/namespacepagetitle.{js,css}` |
| Banner | `SourceArchive/SourceArchiveBanner.php`, `resources/sourcearchive.{js,css}` |
| Archive cover data/title | `SourceArchive/SourceArchiveBanner::coverData()`, `Spec/ArchiveCover.php` |
| Archive print | `Hooks::wirePrintModule()`, `resources/print.{js,css}` |
| Citation | `WikibaseCitation/includes/StatementToCslConverter.php`, `manifests/citation-source-type-map.json` |
| AddSource help | `Spec/SpecialAddSource.php`, `i18n/{en,fr,eo}.json` |

## Deployment

- Extension rsync + `php-fpm` restart + the usual message/object cache purge.
- **Re-run `maintenance/importCitationMap.php --force`** when the change ships:
  the engine reads the published `MediaWiki:Citation-source-type-map.json`,
  not the manifest file.
- No LocalSettings / vocabulary / config-map change; the new special page and
  resource modules are registered by `extension.json`.
- Verify: `Special:EntityIndex/Source`; a `Source:` page H1's index link and
  muted "in"; an archive page's banner/dismiss/print cover; APA for Q2937
  (all authors per APA 7) and BibTeX/RIS (all 22).

## Tests

`Spec/NamespacePageTitleTest`, `Spec/ArchiveCoverTest`,
`Spec/EntityIndexTest`, `StatementToCslConverterTest` (multi-author order +
deprecated skip), `CitationFormatterTest` (article-journal + 21+ authors),
`CitationMapReaderTest` (source type map), plus the E2E index/banner/config
checks (`run_e2e.py`, `run_pages_e2e.py`, `run_wiki_ux_e2e.mjs`).
