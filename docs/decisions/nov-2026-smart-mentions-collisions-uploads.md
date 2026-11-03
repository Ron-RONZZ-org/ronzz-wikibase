# Smart internal mentions, Add* collision recovery, upload loading, GIF thumbnails

## Context

Four production reports share one cause-or-surface pattern: a silent fallback
that hides the real outcome from the contributor.

- `[[Person:Edwin Hubble]]` rendered the namespace prefix, so the "Copy
  internal mention" button had to copy a piped link with a separately
  resolved label.
- `Special:Upload` URL mode gave no visible progress on a slow external
  fetch, and the source toggle left the deselected source's preview on
  screen.
- Large animated GIF thumbnails were frozen to their first frame.
- `Collective:Club of Rome` rendered an empty infobox with no sitelink. The
  accidental Main-namespace `Club of Rome` page had auto-created item Q2655
  (label + sitelink only); the page was deleted; `Special:AddCollective`
  then hit `createViaSemanticFlow()`'s **silent exact-label reuse**, reused
  Q2655 without applying the class/website/logo record, and `afterCreate()`
  refused to move Q2655's stale sitelink. The new page was left unlinked
  (`unexpectedUnconnectedPage`) and the deferred page-deletion job later
  removed the old sitelink.
- `Special:AddQuotation` required `attributedTo` even when a source with its
  own authors was selected.

## Decision

### Smart internal mention

`Hooks::onHtmlPageLinkRendererBegin()` handles **unpiped** links to the five
classic per-kind namespaces (`Person`, `Collective`, `Source`, `FOSS`,
`Software`):

- the caption is the title without the namespace (`Person:Edwin Hubble` →
  `Edwin Hubble`);
- a `Source:` caption is italicised (`<a …><i>Beloved</i></a>`);
- piped links, self-links and comment-rendered links are untouched.

`Spec/InternalMention` owns the namespace rules (unit-tested). The
"Copy internal mention" button now copies `[[Person:X]]` /
`[[Source:X]]` — the renderer supplies the caption, so `wbMentionLabel`
and `wbMentionItalic` were removed.

### Special:Upload

- URL-mode submissions arm a 1-second timer; if the upload/conversion is
  still running, a modal overlay with an indeterminate bar blocks repeated
  clicks. It is cleared on success, failure, conversion failure and review.
- Switching the source clears the **deselected** source's preview and
  status; switching back re-renders a still-selected local file.

### GIF thumbnails

MediaWiki animates a GIF thumbnail only when its total frame area is within
`$wgMaxAnimatedGifArea` (default 12.5 MP). The instance's large animations
(21–31 MP) were rendered static. Production and the dev mirror set
`$wgMaxAnimatedGifArea = 60 MP` (matching `$wgMaxImageArea`); existing static
GIF thumbnails are deleted so the on-demand 404 handler regenerates them.

### Orphan item cleanup

`Hooks::onPageDeleteComplete()` (Main namespace) deletes the sitelinked item
when it has **no statements, no descriptions and no aliases**, and its only
sitelink is the deleted page. `Flow/OrphanItemDeleter::shouldDelete()` is the
pure rule. An authored item always survives; a failed deletion is logged,
never fatal.

### Add* exact-label collision

`Spec/CollisionFinder` detects the class-filtered duplicate guard's blind
spot: an existing item with the record's exact label whose own `wikibase`
sitelink points at a different page. (A target page already owned by another
item remains the existing duplicate/link-confirm flow — "keep current logic
for potential duplicates".)

The browser Add* flows route to a `<token>/collision` warning with three
CSRF-gated options:

1. **reuse** — apply the new record to the existing item through the flow's
   `applyUpdate`, move its sitelink to the new page, create/finalize the
   page; the old page keeps existing but is unlinked;
2. **delete** — delete the existing item (permission-checked) and create a
   new one;
3. **duplicate** — create a new item with the next free suffix (` (2)`,
   ` (3)`, …; `Spec/LabelSuffix`); a Source suffix goes before the class
   suffix (`Title (2) (Book)`).

The API (`action=addsemanticentity`, `action=addsource`) returns
`{ collision: 1, collisionOf, collisionLabel, currentSitelink, targetTitle }`
instead of creating, and accepts `collisionAction=reuse|delete|duplicate`.
`confirmDuplicate=1` now also uses the next suffix when an exact-label item
exists, instead of creating a second identical label.

### AddQuotation attribution

`attributedTo` is no longer a required form field. The server rule is:
**error only when both `attributedTo` and `source` are blank**. When the
author is blank and the source is given:

- exactly one source `attributed to` (P6) value → inherited;
- several → the request is rejected with "The source has multiple authors;
  please specify the author";
- none → the quotation is accepted with the source only.

`Spec/QuotationSourceAuthor` implements this and is called by the browser
form and `action=addspecialcontent`. Update keeps the no-clobber rule (a
blank author keeps the existing author). The canonical contract gains
`requiredAnyOf`, and the pinned MCP contract + descriptions were regenerated.

## Consequences

- No vocabulary/config-map change. The GIF cap is a LocalSettings change
  (and its dev mirror).
- The mention renderer changes parser output: deploy with a parser-cache
  purge.
- The collision warning is a new step on the browser Add* flows and a new
  API result; the existing duplicate/link-confirm logic is unchanged.
- The `Club of Rome` data repair (Q2655: P1=Q25, P38 official website,
  P34 logo, P13 Wikidata ID; sitelink `Collective:Club of Rome`; pending
  marker removed; page purged) is a one-off production data fix.
- Side finding fixed in the same batch: `SemanticEntityFlowService` and
  `UpdateExternalEntityFlow` called the nonexistent `Item::getAliases()` on a
  label-language move; production's data model only has `getAliasGroups()`.
  Both sites now use the compatible accessor (unit-tested).

## Tests

- `tests/Unit/InternalMentionTest.php`, `LabelSuffixTest.php`,
  `OrphanItemDeleterTest.php`, `QuotationSourceAuthorTest.php`.
- `SpecialContentFlowServiceTest` — author-only / source-only / both-blank.
- `tests/e2e/run_pages_e2e.py` — smart-mention parse, orphan deletion,
  collision warning + reuse, API collision + duplicate, source-only
  quotation inheritance (and the both-blank rejection).
- `tests/e2e/run_wiki_ux_e2e.mjs` — source-toggle preview clearing.
- The MCP `field-contract.test.ts` covers the regenerated contract.
