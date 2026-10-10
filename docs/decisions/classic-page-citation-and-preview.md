# Decision: classic-page citation + content-item preview batch

- **Status**: Accepted (Oct 7 2026)
- **Scope**: `wikibase.ronzz.org` — EmbeddableContent (ordinary content-page
  toolbar, content-item preview, Add* success popup) + WikibaseCitation
  (page citation, source-level URL/year). Builds on
  `content-page-reference-toolbar.md`, `content-preview-and-infobox-rows.md`,
  `mention-and-quotation-attribution.md`, `add-more-and-access-collapse.md`.
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A batch of user requests around the classic (non-entity) wiki pages and the
content-item preview:

1. The ordinary content page's "Copy internal reference" button was a
   misnomer (it copies an internal *link*).
2. A math item's Item-page preview rendered its note with the `.wb-embed`
   chrome (a stray blue left line) and did not typeset the note's inline
   `$…$`.
3. A quotation's Item-page preview showed only one negotiated language; the
   reader wanted the original, the translation in their language, and the
   attribution.
4. Classical wiki pages should gain a "Copy citation" button; webpages must
   follow the citation guideline and include the URL; a source's year (the
   last edition) should be read from the source item.
5. The Add* "Add more" flow should confirm the addition with a preview popup
   and the entity actions.

Two distinct citation targets exist:

- **`Source:xxx` / per-kind pages** cite the *item* (the source it refers
  to) — the source item's `date` (last edition) and `URL` matter.
- **Ordinary content pages** (e.g. `Orthonormality`) cite the **page itself**
  — its title, canonical URL and **last-revision date**.

## Decision

### Ordinary content-page toolbar: mention + citation

- The old "Copy internal reference" button is renamed **"Copy internal
  mention"**; its snippet is resolved server-side by the pure
  `Spec\MentionSnippet` (`[[Page name]]`, Main-namespace first letter
  lowercased unless the title is a proper name; other namespaces kept) — see
  `content-page-reference-toolbar.md`.
- A **"Copy citation"** button is added; it opens the shared citation popup
  for the PAGE (`wbCitePage`), not a sitelinked item.

### Shared citation popup (`resources/citationpopup.js`)

An OOUI `PopupWidget` anchored to the button: a format dropdown (APA default
/ Vancouver / BibTeX / RIS), a live preview of the formatted citation, and a
copy button. It supports both targets (`{ entity }` and `{ page }`). The
entity/classic-page toolbar (`gadget.js`) and the content-page toolbar both
use it; the inline format `<select>` next to the old button is gone. The
action controls live in the shared `resources/entityactions.js` module.

The popup carries `.wb-citation-popup-widget` (`gadget.css`, `z-index: 500`)
so it stacks ABOVE an OOUI modal dialog: opened from the Add* "Item added"
`ProcessDialog`, the bare `PopupWidget` (`z-index: 1`) used to hide behind the
dialog (`z-index: 450`) and was unclickable.

### Page citation (`action=citation&page=`)

`action=citation` accepts `page=<title>` (mutually exclusive with `entity`).
`PageCitationBuilder` (pure) builds a CSL `webpage` from the page title, the
site name, the canonical URL and the last-revision date; `CitationEngine
::renderPage` formats + caches it. No author.

### Item citation: source URL + last-edition year

`StatementToCslConverter` reads the cited SOURCE item's `URL` property (a
content item's own `source URL` wins) and its **latest** `date` (the last
edition). The source type map gains `web page` → `webpage`; the source
property map gains `URL` (both re-published by
`maintenance/importCitationMap.php` on deploy).

### Content-item preview (`action=embed&preview=1`)

A new **preview** render mode (used by the Item-page preview and the Add*
success popup; the framed third-party embed surfaces are unchanged):

- **Quotation**: original payload + the reader-language translation (when it
  exists and differs from the original) + the `-author, ''source''`
  attribution line (the shared `Content/ProvenanceWikitext`, also used by
  `{{#content:}}`).
- **Math**: the note loses the `.wb-embed` chrome (plain `.wb-embed-note`);
  the Item-page preview typesets the note's inline `$…$` with the vendored
  KaTeX via the shared `resources/inlinekatex.js` (SimpleMathJax is not
  loaded on the Item page).

### Add* "Add more" success popup

When the "Add more" button is used, the submit redirects back with
`?addmore=1&created=<Qid>`; `SpecialAddContentItem` sets `wbJustAddedItem` /
`wbJustAddedEditUrl` and loads `resources/addmore.js`, which opens a dialog
with the in-wiki preview (the same `preview=1` render) and the entity
actions (Edit content / Copy Embed code / Copy citation). The reopened form
stays ready for the next item.

## Consequences

- No vocabulary / seed / config-map change. Deploy = extension rsync +
  php-fpm restart + **re-run `maintenance/importCitationMap.php`** (the two
  new map entries) + parser/message-cache purge.
- The framed embed surfaces (`Special:Embed`, `action=embed` without
  `preview`, oEmbed, `Special:QuotationsOf`) are byte-compatible for
  content items — only the quotation preview mode and the math note class
  change.
- Tests: `MentionSnippetTest`, `ProvenanceWikitextTest`,
  `PageCitationBuilderTest`, the extended `StatementToCslConverterTest`,
  `run_e2e.py` (`check` page citation + `rich` preview/source-URL checks),
  `run_pages_e2e.py` (Add-more `?created=`), `run_wiki_ux_e2e.mjs`
  (mention + citation popup), and the new self-cleaning
  `run_contentpreview_ux_e2e.mjs`.
