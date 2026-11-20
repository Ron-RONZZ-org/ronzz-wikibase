# Decision: Update\* blank fields remove statements (with clear/undo + snapshot guard)

- **Status**: Accepted (Nov 2026)
- **Scope**: `Special:Update*` pages (content items, semantic entities, `UpdateFile`)
- **Decider**: Rongzhou (`ron@ronzz.org`)
- **Amends**: `upload-ux-fixes.md` §5, `content-item-edit-pages.md` §2

## Context

Every Update\* form is prefilled from the item. The old no-clobber rule made a
blank field mean “do nothing”, so a contributor could not remove an erroneous
statement: clearing a field and submitting left the old value in place, and
removal required an explicit item-page edit. That is the reported
`Special:UpdateMath` “note” case (and any other managed statement).

Blindly treating every blank as a removal is equally wrong: a field may never
have had a statement, the form may not have rendered it, and another editor
may have changed the statement between the page load and the submit.

## Decision

1. **A blank field removes its statements only when the item is unchanged
   since the form opened.** Each Update form carries a hidden page-open
   snapshot (`Spec\StatementSnapshot`) of the GUIDs + value hashes for every
   populated managed property. On submit, a property is cleared only when:
   - the form rendered the owning field and it is blank, **and**
   - the item's current statements for that property still match the snapshot.
   A concurrent edit therefore keeps the newer value instead of being silently
   deleted; a property with no statements is a no-op.
2. **Terms keep their no-clobber rule.** `label`, `description` and `aliases`
   are terms, not statements; a blank field still keeps the existing term. The
   class (`instance of`) and the portrait/logo hidden list are never cleared by
   a blank.
3. **Clear/undo control.** `resources/clearfields.js` adds a × to every
   editable value field on `Special:Update*`. Clicking it blanks the field
   (and the OSM label sibling); the button becomes an indefinite ↶ undo.
   Undo always restores the value the field had **when the form was opened**.
   If the field now holds different content, a confirmation warns that undo
   discards those changes.
4. **API/MCP partial updates are unchanged.** `action=addspecialcontent`,
   `action=addsource` and the semantic-entity update still treat a blank/absent
   field as “keep” (their callers send partial records). Only the browser
   Update forms pass the cleared-property list.
5. **`Special:UpdateFile`** uses `ImageItemCreator`'s existing `clearFields`
   list for the fact fields (license/author/author entity/source/source
   entity/license info/source URL/source text); removing the entity author
   suppresses the “inherit the source's authors” rule. The description term
   keeps no-clobber.

## Consequences

- A cleared field is now the explicit removal gesture the forms imply.
- Concurrent edits are preserved by the snapshot comparison; a blank field
  with no snapshot (an old form) keeps the existing statement.
- New content-item guard: a content item cannot lose its label/payload, and a
  quotation must keep an author or a source.
- The hidden snapshot is user-controllable, but it only names properties the
  current item already has and the same user could edit the item page
  directly; malformed rows are ignored.
- Tests: `StatementSnapshotTest` (snapshot/match/clear/concurrent), the
  `SpecialContentFlowService` explicit-clear unit test, and the page-flow E2E
  (UpdatePerson place-of-death cleared, description kept; UpdateMath note
  cleared).

## References

- `includes/Spec/StatementSnapshot.php`,
  `includes/Spec/SpecialUpdateContentItem.php`,
  `includes/Spec/UpdateExternalEntityFlow.php`,
  `includes/Flow/SpecialContentFlowService.php`,
  `includes/Spec/SpecialUpdateFile.php`,
  `includes/Upload/ImageItemCreator.php`
- `resources/clearfields.js`, `resources/clearfields.css`
- `tests/Unit/Spec/StatementSnapshotTest.php`,
  `tests/e2e/run_pages_e2e.py`
