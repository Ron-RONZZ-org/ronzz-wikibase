# Decision: "Update basic information" on a classic page returns to that page

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — the `Special:Update*` flows and the
  classic-page action toolbar (`Hooks::wireItemToolbar`)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A classic per-kind page (`Person:` / `Source:` / `Collective:` / `FOSS:` /
`Software:`) carries the same action toolbar as its item's `Item:` page,
including the **"Update basic information"** button (ADR
`classic-page-toolbar.md`). The `Special:Update*` submit used to redirect to
the **`Item:` page** unconditionally, so an editor who started from a
`Person:`/`Source:` page was thrown out of the page they were reading and into
the semantic entity view.

## Decision

The button on a classic page marks its Update URL with **`?frompage=1`**. The
Update form carries the marker as a hidden field (the query string is lost by
`HTMLForm::setTitle`'s action URL), and on success the flow redirects to the
**item's current `wikibase` sitelink page** — the classic page — instead of
`Item:`.

- The redirect target is **never** a user-supplied URL: it is read from the
  item's sitelink *after* the update, so a label change that **renamed** the
  page lands on the new title (and there is no open-redirect surface).
- The missing-page heal (`complete/<id>` finalize) still wins when it fires.
- The `Item:` page's own "Update basic information" button is unchanged (no
  marker → the historical `Item:` redirect).

## Consequences

- No vocabulary/config/data change; a pure extension-code change (deploy =
  extension rsync + php-fpm restart).
- `updatebutton.js` needed no change — it renders the URL the server provides;
  the marker rides `wbUpdateBasicInfoUrl`.
- Applies to every classic per-kind namespace, so `Person:`, `Source:`,
  `Collective:`, `FOSS:` and `Software:` all return to themselves.

## Tests

- E2E (`run_pages_e2e.py`, `flow_source_law`): the classic `Source:` page's
  Update URL carries `frompage=1`, the Update form renders the hidden marker,
  and the submit redirects back to the `Source:` page.
