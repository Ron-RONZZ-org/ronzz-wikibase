# Decision: systematic abstract/summary step on AddSource + API/MCP parity

- **Status**: Accepted (Nov 2026)
- **Scope**: `Special:AddSource` (scholarlyArticle/book), `action=addsource`,
  the MediaWiki MCP `embeddable-add-citation-source` tool
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The AddSource content-review step (`/review/<i>/content`, `/manual/content`)
was fetch-driven: it only appeared when a provider returned page prose. A
manual scholarly-article or book creation therefore never asked for an
abstract/summary, and the created `Source:` page fell back to the item
description. The API/MCP path could not write that prose at all: the
`ClassicPageCreator` skeleton only rendered the description.

## Decision

1. **The content step is systematic for `scholarlyArticle` and `book`.** A
   manual create with no fetched content still routes to the step; the title
   is **Abstract** / **Summary** and the legend asks the contributor to write
   it (no “optional/Add” wording). Other classes keep the fetch-driven step.
2. **Page prose, not statements.** `abstract` and the book summary stay in the
   classic `Source:` page body. They are not new properties and do not appear
   in `statementSpecs`.
3. **One page-skeleton implementation.** `Spec\SourcePageSkeleton` owns the
   class→section map (`Abstract`, `Summary`, `Key words`, `Lyrics`, `Plot`,
   `Overview`, the law clause, the website intro, the attribution line). The
   browser form and `ClassicPageCreator` both call it, so the API/MCP page is
   byte-for-byte the same body as the form’s.
4. **API/MCP field names.** `abstract` is the scholarly-article field. A
   book’s summary is exposed as **`bookSummary`** because the `action=addsource`
   `summary` parameter is the edit summary. `ApiAddSource` maps
   `bookSummary` → the page-skeleton `summary` key before the flow service and
   the page creator run. The service accepts `summary` as an internal
   (page-only) field.
5. **Contract refresh.** `SourceFieldMap::ALL_FIELDS`/class fields gained
   `abstract` (scholarly-article) and `bookSummary` (book); the canonical
   `contract/field-contract.json` was re-emitted and the MCP server’s pinned
   copy + generated tool schema refreshed.

## Consequences

- A manual scholarly-article/book creation always asks for the prose, even
  offline; the contributor can leave it blank and the page falls back to the
  description.
- MCP-created Source: pages carry `== Abstract ==` / `== Summary ==` exactly
  like the form. Updates (`qid`) do not rewrite the page (page prose remains
  create/edit-on-wiki), matching the form.
- `SourceFlowService::pageSpecFor` also gained the `transcript` and `text`
  page templates the form already had, so the API no longer silently skips
  those pages.
- Deploy: extension rsync + `php-fpm` restart + parser-cache purge; seed
  re-emission is not needed (no new property/class), but the field contract
  must be re-emitted for the MCP pin.
- Tests: `SourcePageSkeletonTest`, `SourceFlowServiceTest` prose acceptance,
  page-flow E2E (manual systematic step + API page-prose parity), MCP
  typecheck/build/vitest + the generated-contract guard.

## References

- `includes/Spec/SourcePageSkeleton.php`,
  `includes/Spec/SpecialAddSource.php`,
  `includes/Spec/SpecialAddExternalEntity.php` (content-step title/legend),
  `includes/Flow/ClassicPageCreator.php`,
  `includes/Flow/SourceFlowService.php`,
  `includes/Flow/SourceFieldMap.php`,
  `includes/Api/ApiAddSource.php`
- `contract/field-contract.json`
- mediawiki-mcp-server: `embeddable-add-citation-source.ts`,
  `contract/field-contract.json`, `generated/fieldContract.ts`
