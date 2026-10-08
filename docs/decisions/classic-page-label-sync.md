# Decision: a direct label edit renames the sitelinked classic page

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — `EmbeddableContent` (`PageSaveComplete`
  hook + the shared classic-page rename primitive)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A classic per-kind page (`Source:` / `Person:` / `Collective:` / `FOSS:` /
`Software:`) is sitelinked to its item, and its **title is derived from the
item's label** (`Spec/PageTitle::fromLabel`: markup strip → first-letter
capitalization → title-forbidden-character normalization). The `Special:Update*`
forms already rename the page when the label changes
(`UpdateExternalEntityFlow::renameClassicPage`).

But a **direct label edit** — the Item page's label field, `wbeditentity`
(via the API or the MCP `wikibase-edit-entity`) — had no such path: the item's
label changed while its `Source:`/`Person:`/… page kept the OLD title, so the
sitelink pointed at a page whose title no longer matched the item.

## Decision

A new `PageSaveComplete` handler (`Flow/ClassicPageLabelSync`) keeps the two in
sync on Item: entity saves:

- it acts only on **Item: page saves** (not creates) whose item carries a
  `wikibase` sitelink;
- the new title is derived from the item's **English label** (fallback: the
  item's term-language label — e.g. an AddSource item stored under a
  non-English `labelLanguage`);
- when the derived title differs from the sitelinked page's current title, the
  page is **moved** to it — **a redirect is left behind** — and the sitelink is
  re-pointed (entity revision + sitelink table, synchronously).

The move logic is shared with the Update* flow through the new
`Flow/ClassicPageRenamer` primitive (one implementation, no drift). A
static suppression guard keeps the hook from re-entering while the Update*
flow owns the rename (its label-changing save) or while the renamer persists
the sitelink.

**Move-back over the redirect**: the renamer moves over the **single-revision
redirect** a previous rename left behind, so an accidental edit can be reverted
(label backtracking). A real page is never clobbered
(`Title::isSingleRevRedirect()` gates it).

## Consequences

- No vocabulary/config/data change; deploy = extension rsync + php-fpm restart.
- The direct-edit and the Update* paths rename (and leave a redirect)
  identically; a label edit that only changes a non-title-driving language
  (e.g. `fr` when an `en` label exists) does not rename.
- Moving over a redirect requires MediaWiki's normal move-over-redirect
  permission (`delete-redirect` / `delete`); an editor who lacks it keeps the
  old page (the label edit itself still succeeds).
- API and MCP label edits are covered too (they go through the same entity
  store), not only the web UI.

## Tests

- E2E (`run_pages_e2e.py`, `flow_label_rename`): a `Source:` item's en label is
  edited via `wbeditentity`; the page moves to the new title, the old title
  becomes a redirect, and the sitelink is updated; editing the label back moves
  the page over the redirect.
