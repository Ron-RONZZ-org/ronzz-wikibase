# Decision: Content-item "Edit content" pages (UpdateQuotation / UpdateMath / UpdateCodeSnippet)

- **Status**: Accepted (Sep 3 2026)
- **Scope**: `wikibase.ronzz.org` — the v1 content items
  (`Special:AddQuotation`/`AddCodeSnippet`/`AddMath`) and their Item pages
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Quotations, math snippets and code snippets are items WITHOUT classic pages.
Their content is edited today only through the raw Wikibase item page
(statement editing), which shows the **escaped-at-rest** payload
(`PayloadCodec`: a multi-line quotation is stored with `\n` sequences, since
the wiki's string values reject vertical whitespace) — awkward and
error-prone for real prose/code/TeX. The semantic entities (person, source,
collective, software, fictional character) already had a clean edit surface
("Update basic information" → the `Special:Update*` pages), but the content
kinds had none: the Item page carried no button, and no browser form could
update an existing content item (only the machine path,
`action=addspecialcontent` with `qid`).

## Decision

### 1. Three Update pages sharing the Add form

New `Special:UpdateQuotation`, `Special:UpdateMath`, `Special:UpdateCodeSnippet`
(URL `Special:UpdateQuotation/Q42`), each extending its `Add*` counterpart
through the new `SpecialUpdateContentItem` base class. The edit form is the
**exact same form as Add** (`SpecialAddContentItem::buildFields`, minus the
create-only "Add more" button):

- the payload textarea is prefilled with the **decoded** content
  (`PayloadCodec::decode`) — real newlines, exactly as entered in Add;
- the quotation language combobox follows the stored `MonolingualTextValue`
  language; the code lexer is pre-selected from the stored
  programming-language item (`lexerForItemId`); the subject lists
  (`describes` / `implementation of`) and the provenance fields
  (`attributedTo`/`source`/`sourceUrl`/`date`) carry the stored ids/values;
- the label is prefilled in one term language (the first config fallback
  language the item has a term for) and written back in that same language —
  an edit never duplicates the label under the editor's UI language.

The form→record conversion of the Add submit was extracted to
`SpecialAddContentItem::flowRecordFromForm()` (the validation of the
browser-only fields: quotation language code, code lexer, entity ids, URL,
date) so the Add and the Update forms share one vocabulary — the same
drift-proofing rule as the API/forms field maps.

### 2. Update semantics = the shared flow service (no-clobber)

Submit runs `SpecialContentFlowService::prepare(creating:false)` +
`applyUpdate` on a FRESH read of the item (never clobbering edits made
between page load and submit), then persists with GUIDs — the exact pipeline
the `action=addspecialcontent` qid update runs. No-clobber contract: only
the managed statements for which the form provides a NEW non-empty value are
replaced; a blank field keeps the existing statement (removal is an explicit
item-page edit). The class never changes.

### 3. Item-page button: per-kind label

`Hooks::updateUrlForItem` became `updateTargetForItem` and now also maps the
content classes (`classIds()`: quotation / math / code) to the new pages,
setting a per-kind button message key. Content items render an **"Edit
content"** button (`embeddablecontent-update-content-button`, en/fr/eo) in
the shared `.wb-embed-toolbar` (`updatebutton.js` reads
`wbUpdateBasicInfoLabel`, falling back to "Update basic information" for the
semantic entities). A config without the content vocabulary degrades to no
content button and never breaks the other mappings.

## Consequences

- Editing a quotation/code/math item now shows real multi-line content and
  keeps the no-clobber guarantees the semantic-entity Update pages give.
- Blanking the payload on the form is blocked by the shared required-field
  rule (label + payload are the essence of a content item); removing
  content stays an explicit item-page edit — consistent with the no-clobber
  contract.
- Machine clients are unaffected (the `action=addspecialcontent` qid path is
  unchanged); the browser form and the API still run the same service.
