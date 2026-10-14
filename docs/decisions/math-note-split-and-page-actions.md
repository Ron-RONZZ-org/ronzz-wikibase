# ADR — math note split, per-section copy controls, append "Add section"

Status: accepted (2026-10-14). Owner: ronzz.org.

> **Partly superseded (2026-10-14)** for **§2** (per-section controls) and
> **§3** (the append editor): the section controls are now each in their own
> `[ ]` bracket pair and gain "Add section below" / "Delete section"; the
> append editor uses a plain `wpTextbox1` textarea so WikiEditor attaches the
> Edit-parity toolbar, plus a Preview button and an auto-inferred summary.
> See `section-controls-and-editor.md`. **§1 (the math pre/post-content
> notes) stands.**

Three UX requirements on the EmbeddableContent surfaces. **Re-seed required**
(one new property); no other vocabulary/class/config change.

## 1. Math note split into pre-content and post-content notes

**Requirement**: `Special:AddMath`'s single "Note" becomes two — a
pre-content note (rendered *before* the expression) and a post-content note
(rendered *after* it). The existing `note` property becomes the post-content
note.

**Design**: a NEW string property **`pre-content note`** (config key
`preNote`, `EmbeddableContentConfig::preNotePropertyId()`, manifest row +
seed `preNote` emission), a math-only `preNote` textarea on Add/Update placed
**above** the Content field (mirroring the render order), and the render
order **pre-note → expression → post-note** on every surface:

- `ContentWikitext::math( $latex, $preNote = '', $note = '' )` (pure,
  unit-tested);
- `{{#content:}}` (`ContentPayload`), with the note flags now three bare
  flags — `noNote` (both, unchanged), `noPreNote` (pre only), `noPostNote`
  (post only) (`ContentArgs`);
- the embed renderer (`ContentRenderer::renderKind`: `.wb-embed-note` +
  `.wb-embed-note-pre` above, `.wb-embed-note` below);
- the AddMath live preview (`addmath.js`, a pre-note slot above the KaTeX
  expression, the post-note below).

The existing `note` property is **unchanged** — no data migration is needed
(its statements *are* the post-content notes) and the seed's exact-label skip
leaves it alone; only the form/help labels read "Pre-content note" /
"Post-content note". A pre-seed instance without `preNote` renders no
pre-note (the field and the property are nullable, like `note`).

## 2. Per-section "Copy internal mention" / "Copy URL"

**Requirement**: next to each section's `[edit]` link, two new buttons of the
same style.

**Design**: `resources/sectionactions.js` (module
`ext.embeddableContent.sectionactions`, loaded on the content-page branch of
`Hooks::onBeforePageDisplay`, which already sets `wbReferenceSnippet`) appends
two controls **inside** each `.mw-editsection`, so they inherit its small
inline link style:

- **Copy internal mention** copies `[[Page#section|` (NO closing brackets —
  the contributor types the display text). The page part reuses the
  server-resolved `wbReferenceSnippet` (`Spec\MentionSnippet`, the
  Main-namespace case normalization), so the section control and the
  page-level "Copy internal mention" agree.
- **Copy URL** copies the absolute
  `location.origin + mw.util.getUrl( page ) + '#' + anchor`.

The anchor is the heading element's `id` (MediaWiki already normalizes spaces
to underscores and encodes special characters), so it is valid both in a
wikitext `#…` fragment and in a URL.

## 3. "Add section" is an append action

**Requirement**: the content-page "Add section" button opens an Edit-like
form (Content + Summary + Watch; **no** minor-edit checkbox, **no** subject)
whose save **appends** the content to the end of the page.

**Design**: core has no append mode, and `action=edit&section=new` inserts a
`== Subject ==` heading derived from its subject field — so the old
`section=new` link is replaced by a custom page action:

- `extension.json` `Actions` → `appendsection`
  (`EmbeddableContent\Action\AppendSectionAction extends FormAction`, the OOUI
  form; `getRestriction()` = `edit`, so `checkCanExecute` enforces the core
  edit right). `Hooks::addSectionAction` links to `?action=appendsection`.
- The save reads the page's current wikitext and writes
  `AppendSectionText::append( $current, $added )` — one blank-line separator,
  **no heading** (pure, unit-tested) — through
  `WikiPage::doUserEditContent`, so edit filters, AbuseFilter, the page-save
  hooks and read-only checks all apply; the Watch checkbox is applied via
  `WatchlistManager::setWatch`.
- The form is a plain OOUI textarea, **not** WikiEditor — the cost of a
  clean, upstreamable append flow (no core `EditPage` hacking).

## Deploy

- **Re-seed required** (the `pre-content note` property + the `preNote`
  config key). Deploy = the usual extension rsync + php-fpm restart +
  message/objectcache purge (new i18n keys; the new `Actions` registration).
- The field contract is re-emitted (`preNote` joins the `action=addspecialcontent`
  vocabulary); the MCP server's pinned copy + generated tools are refreshed
  (which also pulls in the pre-existing semantic-entity `labelLanguage` drift).
- On-wiki: `Help:Contributing/math` (the note split + the flags),
  `Help:Contributing/links` + `Help:Contributing` (the section controls and
  the Add-section append flow).
