# Decision: "Add more" on Special:AddSource/law (rapid clause entry)

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSource/<law>` and the shared
  Add* "Add more" popup (`Flow/ClassicPageRenamer` is unaffected)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The content-creation pages (`Special:AddQuotation` / `AddMath` /
`AddCodeSnippet`) have a second **"Add more"** submit button: the submit
creates the item, then reopens the page with the provenance carried over so a
contributor can enter several items from the same source in a row
(ADR `add-more-and-access-collapse.md`). The **legal-provision** form
(`Special:AddSource/law/manual`) — one clause of a legislation, entered many at
a time — had only the single "Create" button, so entering a run of provisions
meant retyping the parent legislation every time.

## Decision

The `law` manual form gains the same **"Add more"** submit button, scoped to
the `law` class only:

- on `wpaddMore`, the provision **and its `Source:` page** are created exactly
  as a normal submit (the `complete/<id>` finalize still runs — the page's
  `wikibase_item` property must be set), then the flow redirects back to
  `Special:AddSource/law/manual?addmore=1&created=<Qid>&parent=<Qid>`;
- the **parent legislation is preserved**; the reference code, the clause text
  and the added translations reset for the next provision;
- the reopened form shows the shared **success popup**
  (`resources/addmore.js`): the just-added provision's in-wiki preview plus the
  item actions — "Update basic information" (→ `Special:UpdateSource/<Qid>`),
  "Copy embed code" and "Copy citation".

The generic plumbing lives in `SpecialAddExternalEntity` (a
`manualExtraSubmits()` hook, the `addMoreReturnStep()`/`addMoreCarryFields()`
contract, the session-flag intent that survives the duplication-guard confirm
round-trip, and `maybeWireAddMorePopup()`); `SpecialAddSource` activates it for
`law`. The popup's primary-button label is now config-driven
(`wbJustAddedEditLabel`), so a content item still says "Edit content" while a
provision says "Update basic information".

## Consequences

- No vocabulary/config/data change; deploy = extension rsync + php-fpm restart
  + a parser-cache/message purge is unnecessary (no message change).
- The popup's "Copy embed code" button depends on the provision being
  embeddable — delivered by ADR `law-embed-code.md` in the same batch.
- Other AddSource classes are untouched (the hooks return empty/default).

## Tests

- E2E (`run_pages_e2e.py`, `flow_source_law_addmore`): the `law` form renders
  the button; the `wpaddMore` submit reopens the form with
  `addmore=1`/`created`/`parent`, prefills the parent and resets the reference
  code + clause; a second submit creates a second provision from the same
  parent; the return trip wires the popup (`wbJustAddedItem` + the `addmore`
  module).
