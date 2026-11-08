# AddSource author panel — progressive confirmation, ORG: collectives, immediate creation

Status: implemented (awaiting deployment) — 2026-11-08

## Context

The AddSource "Search for authors" panel already parsed a pasted author
list, resolved local Person candidates and recorded one explicit decision
per name. Three defects and two missing capabilities surfaced:

1. Clicking a result row re-ran the search instead of opening its dialog.
2. Every action re-rendered the whole results panel (a visible flash).
3. The "Add the individuals" group-of-humans shortcut replaced the **whole**
   author list with the group's names.
4. Collectives could only be authors through the Q-id field plus a confusing
   "genuinely an organisation" checkbox.
5. New Persons/Collectives could only be created at final submit (a deferred
   decision that kept the Q-id field empty until the Source was saved).

## Decisions

### Result rows are not inside an OOUI LabelWidget

The panel was an HTMLForm `info` field. In OOUI mode that renders as an
`OO.ui.LabelWidget` (`<label>`), and a click on any descendant button fires
the label's activation on its first labelable control — the "Search for
authors" button. New `Fields/RawHtmlField` renders the panel in a plain
`<div>`, so a result row's own click handler wins.

### Progressive rendering

Confirmed rows keep their DOM nodes; only the affected row's flag/status is
updated in place. A re-search replaces the unconfirmed rows while confirmed
rows stay on top. No `results.textContent = ''` on confirm/remove/undo.

### Immediate entity creation

Confirming "Add new person"/"Add new collective" posts
`action=addsemanticentity` immediately, gets the item id and injects it into
the Author entity ID(s) field. The duplicate and exact-label collision
guards are handled with a small choice dialog ("Use the existing item" /
"Create anyway" / "Create a duplicate"). The created entity is a normal
Person:/Collective: item even if the Source form is later abandoned — the
user accepted this; a free-standing agent entity is valid Wikibase data.

### ORG: collectives (no checkbox)

The author list accepts `ORG:name` (case-insensitive prefix, **no literal
parentheses or braces**). The name runs to the next `,`/`;`; use semicolons
when an organisation name contains a comma. `Spec/AuthorNameList` returns a
`kind` (`person`/`collective`) on every row; `Flow/AuthorResolver` searches
the collective agent classes for ORG rows; `Flow/AuthorCreator` creates/
reuses/updates a collective (with its `Collective:` page).

The "One or more authors are genuinely an organization" checkbox and the
`allowCollectiveAuthor` API parameter are removed. The legacy group-of-humans
name-list guard still blocks a Q-id typed directly into the entity field;
an ORG: row is the explicit declaration. `PersonPanelPayload` /
`SpecialAddExternalEntity::applyPersonPanel` pass the declared collective
Q-ids as `collectiveAuthorIds`, which `GroupMembers::expand` and the
`SourceFlowService` guard consult.

The authorChoices decision shape is additive: person rows keep
`{index, action, qid?, person?}`, collective rows add
`{index, action, qid?, collective?}`. The browser sends `reuse` decisions
with the Q-id it just created; API clients may still use `create`.

### Group-of-humans banner

The banner reads "{string} matches an existing Group of humans (Q: label)."
[Add all members at once] expands **only that group** (its `has member`
Persons, or the group label's parsed names created immediately) and marks
the group row removed. [Dismiss] hides the banner. It never rewrites the
whole author list.

### Multiple parents

The child-source `parent` field and the AddCollective
`parentOrganization` field now accept a comma-separated list. Each id is
validated against the parent class and written as its own `part of` /
`parent organization` statement. An update prefills every stored id and a
blank field still keeps the existing statements (no-clobber). The
book-excerpt/law inference that needs a single parent uses the first id.
`SourceFlowService`/`SemanticEntityFlowService` emit an array of
`EntityIdValue`s for the property (the same shape the multi-value authors
field already uses).

### Additional-statements help

The Add* forms say "Add additional semantic statements."; the Update* forms
say "Add or update additional semantic statements." The help key is chosen
from the presence of the existing item (`AdditionalStatementsCloner::spec`
takes a message key).

## Dialog construction (fix)

The first implementation created a bare `OO.ui.Dialog` and appended to
`dialog.$body` before opening it. Two OOUI contracts were violated:

1. `OO.ui.Window.initialize()` — which creates `$body` — only runs when the
   window is opened, so the append threw
   `Cannot read properties of undefined (reading 'append')`;
2. `OO.ui.Dialog.static.name` is the empty string, so
   `WindowManager.addWindows()` would have thrown "Windows must have a
   `name` static property defined".

Every row click therefore failed before a dialog could render: the "add
one" action and the search-result rows looked dead. The author panel and the
parent-creation dialog now build a named `OO.ui.ProcessDialog` subclass per
open with a unique `static.name`, build the body in `initialize()`, and
detach the window on the `openWindow(...).closed` promise. The whole author
row is the activation target (the ✕/↶ button and removed rows excepted), so
clicking the "No local match found — add one" text opens the creation
dialog instead of doing nothing.

## Tests

`AuthorNameListTest` (ORG rows, mixed lists, semicolon org names, empty ORG),
the full PHPUnit suite, and the AddSource page-flow E2E. The browser UX is
covered by the manual Playwright suite —
`tests/e2e/run_authorsearch_ux_e2e.mjs` (read-only): an unmatched row's
"add one" status opens the "Add person" dialog, a candidate row opens the
"Confirm author" dialog, Cancel closes it, and the run fails on any page or
console error.

## Deployment

Extension rsync + php-fpm + message/objectcache purge. No seed/config-map/
LocalSettings change. The `mediawiki-mcp-server` tool descriptions are
updated separately (the `allowCollectiveAuthor` parameter is gone; ORG: is
documented).

## Parent-creation dialog (PR 2)

The child-source `parent` field and the AddCollective `parentOrganization`
field render a "Create it now" link (always visible). The link opens an OOUI
dialog containing a same-origin iframe pointed at the REAL
`Special:AddSource/<parentKey>` / `Special:AddCollective` flow:

- website parent: the known site root is passed as `?url=<root>&autofetch=1`;
  `executeUrlEntry()` calls `onUrlEntrySubmit()` directly so the root
  metadata is fetched and the manual form opens prefilled;
- book / YouTube channel: the normal search page is shown first;
- legislation: the manual form;
- parent organization: the normal AddCollective search/review flow.

`wbparentdialog=1` sets a short-lived session marker. `afterCreate()`
returns the `parentdone/<Qid>` step instead of the classic-page redirect;
that step posts `{type:'wb-parent-created', qid, label}` to the opener, which
appends the Q-id to the multi-value field. Links to an existing item (the
duplicate guard's "Yes, that's right") are intercepted in the frame and post
the same message. The opener sends a close beacon
(`?wbparentdialog=close`) to clear the marker; it also expires after 2 h.

Files: `resources/parentdialog.js` + `.css`, the base
`SpecialAddExternalEntity` dialog helpers/routes, and the
`SpecialAddSource::executeUrlEntry()` auto-fetch. No seed/config/LocalSettings
change.
