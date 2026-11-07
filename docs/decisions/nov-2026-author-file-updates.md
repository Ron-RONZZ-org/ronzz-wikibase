# Author-name resolution, semantic-first File pages, and collective reclassification

Status: implemented (awaiting deployment) — 2026-11-07

## Context

Three related data-quality problems showed up in the November 2026 source
imports:

1. **Author lists stored as collectives.** A contributor pasted a whole
   author list (``Moro, Christian ; Phelps, Charlotte ; …``) into
   `Special:AddCollective` → *Group of humans*, then linked that one item as
   the source's `attributed to` author. A read-only SPARQL report found
   **28 such group items referenced by 33 source items**; they are bare
   items (only `instance of` + a `Collective:` page) and are not persons, so
   the citation/author machinery cannot use them.
2. **File page metadata drifted from the image item.** The upload form wrote
   static `== Licensing ==` / `== Attribution ==` text into the File page.
   Editing that text (the `Q2780` report) did not update the sitelinked image
   item, and editing the item did not update the page.
3. **`Special:UpdateCollective` could not change the class.** The form hid
   the class field, so a misclassified collective could not be corrected.

## Decisions

### Author-name resolution (AddSource)

- New pure `Spec/AuthorNameList` parses a pasted author string:
  - only commas → comma separates authors, each `Given… Family`;
  - commas + semicolons → semicolon separates authors, comma = `Family, Given`;
  - semicolons only → semicolon separates, last word = family;
  - no comma/semicolon → split on ` and ` / ` & `; otherwise one author;
  - `Moro, Christian` (one comma, two single words) is **one family-first**
    author; an even count of all-single-word comma segments is paired as
    `Family, Given` and flagged;
  - trailing `et al.` / `and Contributors` is dropped + flagged;
    generational suffixes stay with the family; parenthetical alternates are
    removed before splitting; CJK names and mononyms are flagged for
    confirmation.
- New pure `Spec/AuthorNameMatcher` scores candidates (exact → given-prefix
  (middle-name case) → initial → fuzzy given → family-only). A match is
  **never** auto-accepted: every name needs an explicit reuse/create/update/
  skip decision (common names such as “John Smith” are ambiguous).
- New read-only `action=authorresolve` returns the parsed names, flags, local
  Person candidates (score, authority ids) and any group-of-humans item whose
  own label is a person-name list.
- New `Flow/AuthorResolver` (local search) and `Flow/AuthorCreator` (create/
  reuse/update Person items + `Person:` pages, using the AddPerson flow
  service/page machinery). `AuthorCreator::validateDecisions` is the
  read-only pre-flight used by the review step; the Persons are created only
  after the duplicate guard, in `createFromRecord`/`manualCreate`.
- `Special:AddSource` gains the Author names / Search for authors / hidden
  decisions / collective-override fields; `resources/authorsearch.js` renders
  the per-name flags and the confirmation dialog. `action=addsource` accepts
  `authorNames`, `authorNameFormat`, `authorDecisions`, `authorDetails`,
  `authorUpdateExisting` and `allowCollectiveAuthor`; without decisions it
  returns `authorConfirmationRequired` and writes nothing. The MCP server
  exposes `embeddable-resolve-authors` and the same parameters on
  `embeddable-add-citation-source`.
- Legacy cleanup: `tools/report_group_authors.py` (read-only) and
  `tools/split_group_authors.py` (dry-run default; `--apply`,
  `--delete-groups`) relink the 28 groups to individual Persons and delete
  the group item + `Collective:` page once unreferenced. Ambiguous,
  mononym, CJK and probable-only matches are skipped for manual review.
- The old Q-id combobox remains as an advanced input. A group-of-humans
  author whose label parses as a person-name list is rejected unless the
  contributor ticks the collective override (form) or passes
  `allowCollectiveAuthor=1` (API).

### Semantic-first File pages

- New `{{#image-facts:}}` parser function renders the Licensing/Attribution
  block from the sitelinked image item's statements (license, `attributed
  to`/`image author`, `source`/`image source`, source URL, additional license
  info). It registers the item as a parser-cache dependency, so any item edit
  updates the File page with no page edit.
- `UploadHooks::onUploadForm_getInitialPageText` writes the parser function
  instead of the static block for itemized uploads (the non-itemized
  MsUpload/API path keeps the old static fallback).
- New `Special:UpdateFile` edits the image item's facts and optionally
  uploads a new file version from a local file or URL; `ImageUploadHelper::
  replaceFile` performs the revision upload (reuses the SSRF/fetch helpers)
  and preserves the page text. A File-page edit shows a warning banner with
  “Update basic information” and Dismiss; the File page toolbar gains the
  same Update button.
- `maintenance/backfillImageFacts.php` rewrites existing generated static
  blocks to `{{#image-facts:}}` (dry-run default; `--apply`, `--verify`).
  Only blocks at the end of the page are touched; hand-customised pages are
  skipped.

### Collective reclassification

`Special:UpdateCollective` now shows an editable Class select; the chosen
class rides `withChosenClass()` into the flow record and replaces the item's
`instance of` statement. The other Update* pages keep the hidden fixed-class
field.

### Affiliation

New `affiliated with` property (P1416-aligned, wikibase-item, multi-value),
emitted as `personProperties.affiliatedWith`. `Special:AddPerson` and
`Special:UpdatePerson` expose a multi-value entity combobox scoped to the
collective agent classes; `SemanticEntityFlowService` writes one statement
per id. **Re-seed required.**

### Additional semantic statements (Add* / Update*)

Every Add* and Update* form (semantic entities, Source, content items) gains
an `additionalStatements` cloner: rows of {property, value} for statements
the form has no dedicated field for. `Spec/AdditionalStatements` validates
the property datatype (wikibase-item, string, external-id, url, quantity,
time), builds DataValues, and:
- on **create**, appends the rows after the managed statements;
- on **update**, prefills the cloner with the item's NON-managed supported
  statements and applies the submit as a diff — unchanged rows keep their
  GUID/qualifiers/references, removed rows are deleted, new rows added.
  Managed properties (the form's own fields, plus `instance of`) are
  rejected in the cloner. The API/MCP contracts are unchanged (form-only
  for now).

### Author-year internal mention

The Source: "Copy internal mention" button gains a second choice that copies
the stable marker `[[Source:X|name-author]]`. `Spec/AuthorYearMention`
derives the current `Family1-Family2 (Year)` from the source item's
`attributed to` + `date` at parse time; `Hooks::onHtmlPageLinkRendererBegin`
renders it italic and tags the link with the item id, and
`onParserAfterTidy` registers every tagged source item as a parser-cache
dependency. A later author/year correction therefore updates every mention
without editing the page.

## Deployment

1. Extension rsync + php-fpm restart + message/objectcache/parser-cache purge.
2. Re-seed (new `affiliated with` property + config map).
3. Run `maintenance/backfillImageFacts.php --dry-run`, then `--apply`.
4. Run `tools/report_group_authors.py` and review
   `tools/split_group_authors.py` in dry-run, then `--apply --delete-groups`.
5. Re-copy the field contract to the MCP server and regenerate its generated
   tools (`npm run gen:field-contract`, `npm run build`).

## Tests

`AuthorNameListTest`, `AuthorNameMatcherTest`, the full PHPUnit suite, and the
existing E2E suites. New E2E coverage for the author panel, `Special:
UpdateFile`, the parser function, the collective reclassification and the
affiliation statement is part of the deployment validation.
