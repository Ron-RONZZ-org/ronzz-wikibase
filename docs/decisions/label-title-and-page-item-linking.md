# Decision: Label→title capitalization + the Main-page→item auto-link

- **Status**: Accepted (Sep 2026)
- **Scope**: `wikibase.ronzz.org` — the classic-page title machinery
  (`Spec/PageTitle`, `Flow/ClassicPageCreator`, `Flow/NewItemPageCreator`,
  `Spec/SpecialAddExternalEntity`, `Spec/UpdateExternalEntityFlow`) and a new
  `Flow/PageItemCreator` hook (Main-namespace page → item)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Two user reports:

1. **A lowercase-initial item label silently lost its classic page.** An item
   created through `Special:NewItem` with the label "vector space" (Q1862,
   2026-09-29) got no page and no sitelink. Every prior NewItem creation that
   worked had an UPPERCASE-initial label ("Philosophy", "Logic", "Logical
   proof"), so the defect went unnoticed.
2. **A page written directly by an editor had no item.** The classic
   wikitext workflow (`Screw theory`, `Classical mechanics`, …) creates a
   Main-namespace page with no corresponding Wikibase item — the reverse of
   the gap the NewItem hook closes.

### 1. Root cause of the missing page

`Title::makeTitle()` does NOT normalize the first letter, and
`Title::isValid()` rejects a lowercase-initial title while `$wgCapitalLinks`
is on:

```
Title::makeTitle( 0, "vector space" )->isValid()  → false
Title::newFromText( "vector space", 0 )            → "Vector space" (valid)
Title::capitalize( "vector space", 0 )             → "Vector space"
```

Every label→title site derived the page title with `makeTitle` + `isValid()`,
so a lowercase-initial label produced an invalid title and the path returned
early — before the sitelink write and the page creation. `LabelSanitizer`
normalizes the title-forbidden characters (`# < > [ ] { } |` → `-`) but
deliberately leaves the case alone, so it did not catch this.

Confirmed live: the deployed hook code matched `main` byte-for-byte, was
registered and callable, and an E2E-driven NewItem creation (uppercase label)
still worked — the failure was the label case alone.

## Decision

### 1. One label→title contract: `Spec/PageTitle::fromLabel`

New `Spec/PageTitle::fromLabel( string $label, int $namespace ): ?Title`:

1. `LabelSanitizer::normalizeForTitle` (strip markup, `# < > [ ] { } |` → `-`);
2. `Title::capitalize( $label, $namespace )` — the namespace's capitalization
   rule (`$wgCapitalLinks` / `$wgCapitalLinkOverrides`), exactly as
   `Title::newFromText` would, but WITHOUT parsing a namespace prefix out of
   the label (a label may legitimately contain a colon);
3. `Title::makeTitle` on the namespace ID + the namespace/validity check.

All four label→title sites now use it — `Flow/ClassicPageCreator::pageTitleFor`
(the Add\* browser + API flows), `Flow/NewItemPageCreator::createMainPage`,
`Spec/SpecialAddExternalEntity::pageTitleForRecord`, and
`Spec/UpdateExternalEntityFlow::renameClassicPage` (the label-change rename).
The item LABEL is never changed — only the derived page title.

### 2. The reverse direction: `Flow/PageItemCreator`

New `PageSaveComplete` handler wired next to `NewItemPageCreator` in
`Hooks::onPageSaveComplete`. When a NEW Main-namespace page (EDIT_NEW, not a
redirect) is saved and is NOT already sitelinked:

- **reuse** an existing item whose label matches the page title exactly
  (case-insensitive), sitelinking it — never duplicate a label, never steal an
  item that already has a sitelink;
- otherwise **create** an item (label = the page title in the content
  language, no description) and sitelink it to the page.

Scope is deliberately narrow:

- a page already sitelinked is skipped — in particular the page the NewItem
  hook just created (it writes its sitelink BEFORE the page, so the reverse
  hook always sees the link);
- the Add\* flows' pages live in the custom namespaces (Person:/Source:/…) and
  are skipped; the Item: save this handler triggers is skipped by the same
  namespace gate + the re-entrancy guard;
- redirects and non-new saves are skipped.

Never fatal: a failure is logged at WARNING (`EmbeddableContent` channel) and
the page save succeeds — a missing item is a cosmetic gap, not a broken edit.

The two hooks are mutually exclusive by construction: NewItemPageCreator only
acts on a `Special:NewItem` request, PageItemCreator only on new NS_MAIN
saves.

### 3. Backfill for existing gaps: `tools/backfill_page_items.py`

The hook only covers NEW pages; the pages created before it need a one-off
backfill. `tools/backfill_page_items.py` (stdlib only, `seed/wikibase_api.py`)
lists the Main-namespace non-redirect pages that are not sitelinked, applies
the same reuse-or-create rule, and is **dry-run by default** (`--apply`
writes, `--verify` re-checks). A `--exclude` regex (default: `Main Page`,
`Sandbox*`) filters the housekeeping/pseudo pages that also live in the Main
namespace — REVIEW the dry-run list before applying.

## Consequences

- A lowercase-initial label now yields a page (and the Add\*/Update\* flows
  are fixed by the same change, since they share the contract).
- A new Main-namespace page auto-creates (or reuses) its item. This includes
  pages created by bots/scripts (the MCP writer, `action=edit`) — desired:
  they are content pages too.
- **No vocabulary / config-map / manifest / LocalSettings change.**
- Regression coverage: the page-flow E2E now uses a lowercase-initial NewItem
  label (and asserts the page links to exactly the created item), plus a new
  `flow_main_page_item` (Main page → item + sitelink, label = title).

## What this does NOT do

- No change to the item label (the term keeps its exact case).
- No page-title length truncation — an over-long label still yields the
  item-only fallback.
- No automatic backfill on deploy — `tools/backfill_page_items.py` is run
  explicitly, after a reviewed dry-run.
- No change to `wbsearchentities` or the Sitelink-tab popup.
