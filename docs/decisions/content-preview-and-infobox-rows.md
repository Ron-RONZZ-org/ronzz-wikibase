# Decision: Content-item preview, wikitext Note preview, conditional infobox rows, Person quotations

- **Status**: Accepted (Oct 5 2026)
- **Scope**: `wikibase.ronzz.org` — the EmbeddableContent extension
  (AddMath preview, Item: pages, the classic-page infobox templates,
  `Special:QuotationsOf`) and the on-wiki infobox templates
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Four related gaps / requests:

1. **`Special:AddMath` Note preview did not render wikitext.** The Note is
   stored as full wikitext and rendered as such on the real page
   (`ContentWikitext::math` → the parser; `RichTextRenderer` on the embed
   surfaces), but the client-side live preview (`resources/addmath.js`)
   rendered it as **plain text with inline `$…$` KaTeX only** — links,
   emphasis, `[[File:…]]` and templates showed literally.
2. **Content items had no rendered preview on their Item page.**
   Quotation / math / code-snippet items carry NO classic page; the Item
   page showed only the statements, so a reader had to copy the embed code
   to see the actual content.
3. **Infoboxes showed labelled empty rows.** The classic-page templates
   (`Template:Person`, `Collective`, `FOSS/Infobox`, `Software/Infobox`, and
   every Source per-class template) are plain wikitables whose data rows are
   `| Label || {{#statements:property}}`. A property with no statement renders
   an empty cell — the row stays visible with its label and a blank value.
4. **Person: pages had no "Quotations" row.** The Source: pages auto-link
   their quotations through `{{#quotations-of:}}` (the `source` statement),
   but a person has no equivalent row (quotations link to them through
   `attributed to`).

The empty-row hiding is constrained by the instance: **ParserFunctions'
`#if` is NOT installed**, so the existing house pattern is a parser function
that emits a complete row conditionally (`{{#quotations-of:}}`,
`{{#child-items-of:}}`).

## Decision

### 1. The AddMath Note preview renders wikitext through `action=parse`

`resources/addmath.js` now renders the Note by calling the MediaWiki parse
API (`action=parse`, `contentmodel=wikitext`) and injecting the returned HTML,
then typesetting the inline `$…$` segments over the parsed text nodes with the
same vendored KaTeX (SimpleMathJax does not run inside the preview). A
latest-wins sequence guard discards stale responses; any failure degrades to
the previous plain-text rendering — the preview never goes blank.

### 2. A content item's Item: page renders the embed fragment below the toolbar

`Hooks::onBeforePageDisplay`'s Item branch detects the content kind
(quotation / math / code-snippet — the same instance-of scan
`updateTargetForItem` uses) and loads the new
`ext.embeddableContent.contentpreview` module with `wbContentPreviewItem`.
`resources/contentpreview.js` fetches `action=embed&entity=Q…&output=html`
(the exact framed fragment the embed surfaces render), injects it into a
`.wb-content-preview` container directly below the `.wb-embed-toolbar` row,
and fires `ext.embeddableContent.embedContentAdded` so `math.js` / `code.js`
(re-)render the injected KaTeX / highlighted code. The toolbar ordering is
order-independent: when the toolbar does not exist yet the preview inserts
after `#firstHeading`, and the toolbar modules later insert themselves with
`.after('#firstHeading')`, landing above the preview.

`math.js` / `code.js` now expose the re-render on the
`ext.embeddableContent.embedContentAdded` hook; `code.js` skips
already-highlighted elements so the re-fire is idempotent.

### 3. `{{#statement-row:Label|property}}` — the conditional infobox row

New parser function (`includes/ParserFunctions/StatementRow.php`, magic word
`statement-row`, en/fr/eo) — the `{{#quotations-of:}}` pattern for ordinary
infobox rows:

- resolves the page's sitelinked item (or an explicit third argument);
- resolves the property label through the **WikibaseClient property-label
  resolver** (the same one `{{#statements:}}` uses) — a `P…` id is accepted
  directly; the OSM keywords `osm-birth` / `osm-death` / `osm-jurisdiction`
  render the `{{#osm-place:}}` cell and check its backing config property
  (including the legal-text international marker);
- emits a **complete table row** `\n|-\n| Label || {{#statements:P…}}`
  (with `|from=Q…` for an explicit item) only when the item carries a
  statement for the property, and **nothing** otherwise;
- never hides data it cannot verify: a resolver failure fails OPEN (emits the
  row); an unknown property label (the row would be empty) hides it;
- registers the item as a parser-cache dependency.

The nested `{{#statements:}}` is expanded by the parser (a `noparse=false`
function return is `preprocessToDom`-ed — the `{{#content:}}` contract), so
the value formatting/linking is unchanged.

The image cell (`{{#item-image:}}`, a colspan row the template's
`logo`/`portrait` parameter may override) and the Access cell
(`{{#source-access:}}`, which renders a localized "N/A") are deliberately left
untouched.

### 4. Person: pages gain the "Quotations" row

- `QuotationFinder::findForPredicate()` generalizes the SPARQL query over the
  linking property; `findForSource()` is a wrapper, and
  `QuotationLookup::findByAuthor()` uses the `attributed to` provenance.
- New `{{#quotations-by:}}` parser function (magic word `quotations-by`,
  en/fr/eo) mirrors `{{#quotations-of:}}`, emitting `| Quotations || N` linked
  to `Special:QuotationsOf/<Qid>/author`.
- `Special:QuotationsOf` accepts the `<Qid>/author` subpage and lists
  quotations attributed to the item (mode-specific title/intro/back/none
  messages, en/fr/eo).
- `QuotationLookup::invalidateClassicPages()` (the renamed
  `invalidateSourcePages`) is called with the source **and** author ids by the
  content create/update paths, so the source's and the author's classic pages
  both refresh (the linked item's own revision does not change).

## Deployment

Two ordered steps:

1. **Extension deploy** — rsync the EmbeddableContent files, restart php-fpm,
   purge the parser cache (the new parser functions change output). No
   re-seed: no new properties/classes/config keys.
2. **Template migration** — the `{{#statement-row:}}` function must exist
   before the templates use it (an unknown function is a wikitext error), so
   `tools/rewrite_infobox_templates.py` rewrites the ~30 infobox templates
   (dry-run by default, `--apply` writes) and adds `{{#quotations-by:}}` to
   `Template:Person`. Then purge the affected pages' parser cache.

## Alternatives considered

- **Install ParserFunctions and use `#if` in the templates** — a new
  extension dependency, and every data row doubles the `{{#statements:}}`
  render (one for the test, one for the value); the house already hides
  conditional rows in parser functions.
- **A `ParserAfterTidy` DOM pass that strips empty rows** — server-side and
  template-independent, but implicit (applies to every wikitable on those
  namespaces) and harder to reason about than an explicit per-row call.
- **Client-side JS to hide empty rows** — no-JS readers keep the empty rows;
  the project favors server-side rendering.

## Consequences

- `Special:AddMath`'s Note preview and the real page agree (both wikitext).
- Content items are readable on their Item page without copying embed code.
- Infoboxes no longer show labelled empty rows; a row appears exactly when the
  item has data (and the `{{#quotations-by:}}` / `{{#quotations-of:}}` rows
  already behaved that way).
- The template migration is a one-off, idempotent, unit-tested tool; future
  templates use `{{#statement-row:}}` directly.
