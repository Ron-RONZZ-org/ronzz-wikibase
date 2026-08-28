# Decision: Statement-driven infobox image cell (`{{#item-image:}}`)

- **Status**: Accepted (Aug 28 2026)
- **Scope**: `wikibase.ronzz.org` — the classic-page infobox templates
  (`Template:Collective`, `Template:Person`, `Template:FOSS/Infobox`) and the
  Add\* page machinery that feeds them
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

`Collective:National Geographic Partners` did not display its logo although
its sitelinked item (Q880) carries an `image` statement pointing at the
uploaded `File:National Geographic Partners-logo.png`.

Root cause — two layers:

1. The classic-page skeletons (AddCollective / AddPerson / AddSoftware)
   passed the uploaded image to the template as a **creation-time page
   parameter** (`|logo=` / `|portrait=`). Every page created before that
   parameter existed — including all 23 `Collective:` and 42 `Person:` pages
   on the live instance — renders a bare `{{Collective}}` / `{{Person}}` and
   the image cell stays empty.
2. Even for new pages, the parameter duplicates what the item's `image`
   statement already records — the infobox reads every other row from the
   item (`{{#statements:…}}`) but the image from a page parameter: two
   sources of truth for one fact.

The fix must (a) render the image from the **item statement** — the single
source of truth — so old pages, Update\*-edited pages and hand-created pages
all display it, and (b) keep the page parameter as an override for hand-edited
pages.

## Decision

New `{{#item-image:}}` parser function (the `{{#source-access:}}` pattern,
ADR `source-access-rendering.md`):

- Resolves the item from the **current page's sitelink** — or, with an
  explicit id (`{{#item-image:Q42}}`), from the named item (scratch pages and
  pages whose page↔item link is missing).
- Reads the `image` statement (a `File:` page full URL, written at
  creation/update/reuse) and renders `[[File:<title>|frameless|220px]]`.
- Uses the **union** of the configured `image` property ids
  (`personProperties` / `collectiveProperties` / `fossProperties` /
  `imageProperties` — all carry the same shared property on this instance) so
  the function stays class-agnostic without hardcoding ids and keeps working
  if the sections ever diverge.
- Registers the item as a **parser-cache dependency** (`ParserOutput::
  addTemplate()`), so editing the item re-renders every page showing the cell.
- Returns wikitext (participates in the template's normal parse).

The templates' image cells become `{{{logo|{{#item-image:}}}}}` /
`{{{portrait|{{#item-image:}}}}}`: the parameter wins when hand-set, the
statement covers everything else. The Add\* skeleton parameter is kept
(harmless, and the raw-page E2E assertions depend on it).

## Consequences

- **Old pages fixed without a backfill script**: the statement is rendered
  wherever the cell is, on every page that links an item with an `image`
  statement.
- The `File:`-title extraction from the statement URL percent-decodes the
  path segment (an encoded file name must still match) — same decoding as the
  upload-metadata title fix.
- The CI stack has no page templates, so the regression is E2E-asserted
  through a scratch page transcluding `{{#item-image:<qid>}}` (the explicit-id
  argument), which must render the uploaded file.
- On-wiki template edits are deploy-time steps (the templates are not in the
  repo; the seed does not emit them).
