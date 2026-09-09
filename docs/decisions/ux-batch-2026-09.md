# Decision: UX fixes batch (Sep 2026) — banner clicks, item toolbar, embed chooser, listings

- **Status**: Accepted (Sep 2026)
- **Scope**: `wikibase.ronzz.org` — Add\* confirm banners, Item/Source page
  toolbars, `Special:QuotationsOf`, `Special:AddSource` label field
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A round of user-requested UX fixes:

1. `Special:AddPerson` place-of-birth auto-match **[Yes, that's right] /
   [No, let me correct] buttons were dead** on the review step.
2. `Person:` pages show the raw OSM id (`relation/3169865`) for the place of
   birth — see the separate `place-labels.md` ADR for the human-readable
   label work.
3. `Special:AddSource` — while typing the Title (item label) in the
   manual/review form, the class-disambiguation suffix (`(Book)`,
   `(Book excerpt)`, …) is only appended at CREATION — the contributor
   cannot see the final label before submitting.
4. `Item:` pages of source-class items lack the **Copy internal citation**
   button their `Source:` pages have.
5. `Source:` pages of child-capable classes (book → bookExcerpt, YouTube
   channel → YouTube video) lack a **child-items aggregation** — see the
   separate `source-children-listing.md` ADR.
6. `Special:QuotationsOf` renders each quotation as a flat `<li>` text row.
7. The Item-page toolbar's **Copy embed code** only copies the external
   `<iframe>` snippet — no on-wiki (internal) option.

## Decisions

### 1. Autofill-confirm buttons: delegated binding (fix)

**Root cause**: MediaWiki's OOUI HTMLForm renders field layouts
server-side but marks them `mw-htmlform-autoinfuse`; on page load OOUI
re-creates them client-side from their `data-ooui` config (which embeds
the help HTML — and with it the server-rendered `.wb-entity-confirm`
banner). `entityconfirm.js` bound its [Yes]/[No] click handlers to the
server-rendered nodes at module load; OOUI then REPLACED those nodes, so
the visible banner carried no live handlers — both buttons appeared dead.
The uploadmeta license banner was unaffected because it is created
client-side AFTER infusion.

**Fix**: bind ONCE at `document` level (delegation) and resolve the banner
from `event.target` + the field input at CLICK time. This survives any
number of OOUI re-renders and also covers banners inserted later (hide-if
toggles). The [No] handler now re-syncs the OOUI widget value with native
`change`/`input` events so clearing actually clears an infused combobox
(the `uploadmeta.js` lesson). Covers every server-rendered banner on all
Add\* pages.

### 2. (OSM place labels — see `place-labels.md`)

### 3. AddSource live label preview

A read-only "Final label" field sits to the right of the Title field on the
class-scoped manual/review steps (`Special:AddSource/<class>/manual`,
`/<class>/<token>/review/<i>`). It mirrors the server's idempotent
`disambiguatedTitle` rule live: as the user types, the preview shows the
exact label that creation will store (`{title} ({English class label})`);
a title that already ends with the suffix is not double-suffixed. The
suffix text is server-provided (`wbLabelSuffix` config var); the module is
loaded ONLY where the suffix applies — never on the class-picker root,
never on `Special:UpdateSource` (which keeps stored labels as-is).
Input events are delegated and a MutationObserver re-creates the preview
when OOUI autoinfuse rebuilds the field layout.

### 4. Copy internal citation on Item pages

`sourcecite.js` (the `<ref>{{#cite:Q42}}</ref>` copy button) previously
rendered only on `Source:` classic pages. Item pages of source-class items
now receive the same wiring: the server detects the class over the
instance-of statements (`isSourceClassItem`, extracted from the
updateTarget scan) and loads the module + `wbInternalCiteItem` config. This
matters most for source items WITHOUT a classic page (bookExcerpt) — the
Item page is the only surface to copy the snippet from. The module appends
into the shared `.wb-embed-toolbar` row.

### 5. (child-items listing — see `source-children-listing.md`)

### 6. QuotationsOf blockquote formatting

Each quotation on `Special:QuotationsOf` renders as a
`<blockquote class="wb-embed wb-embed-quotation">` — the visual language of
the on-wiki `{{#content:}}` quotation fragment — with the decoded
multi-line text (`white-space: pre-line` preserves the author's line
breaks), the item link below in a subdued source line, and a margin-spaced
entry wrapper (the requested blank line between quotations). Text stays
HTML-escaped (`Html::element`): the payload is decode-at-render, never raw
HTML on this surface.

### 7. Embed-code chooser: internal vs external

The Item-page toolbar's "Copy embed code" button now offers TWO snippet
flavours on click instead of copying the iframe directly:

- **internal** — `{{#content:Q42}}` (the parser-function wikitext for
  embedding on this wiki; language negotiated from the embedding page, so
  no `lang` parameter);
- **external** — the existing `<iframe src=…/Special:Embed/Q42>` snippet
  for third-party pages (keeps the language selector).

The chooser opens inline in the toolbar row; picking a flavour copies it
and closes; clicking elsewhere dismisses. en/fr/eo messages.

## Consequences

- Every server-rendered autofill-confirm banner works after OOUI infusion
  (and future banners work by construction).
- The AddSource contributor sees the final (suffixed) label before submit.
- Source items are citable from their Item page.
- Quotations read as blockquotes with breathing room.
- One toolbar action, two clearly-labelled embed snippets.

## Tests

Module-source regressions (delegated binding present + per-node binding
gone; chooser buttons/snippet builders shipped; label-preview wiring on
`/book/manual` and absent on the picker root) and page-flow E2E (Item-page
sourcecite config; parser-function scratch pages where the CI stack lacks
templates). Browser-level behaviour (clicks, clipboard) verified live at
deploy.
