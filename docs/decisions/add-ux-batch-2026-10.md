# Add\* UX batch (October 2026)

**Status**: implemented.

## Context

Four independent reports against the Add\* / content-page UX:

1. “Add section” / “Add section below” landed at the top of the view page after
   saving instead of at the section just added.
2. `Special:AddQuotation`’s **Attributed to** typeahead suggested every item on
   the instance — e.g. Q2413, a `document` source item.
3. `Special:AddPerson`’s place-of-birth confirmation banner was invisible in
   Vector 2022 night mode (dark text on a dark background); the same
   hard-coded-palette pattern had already been hit on the AddSource final-label
   preview.
4. `Special:AddPerson` fetched BCE dates as positive years (Q868 / Gautama
   Buddha: Wikidata `-0384-…` was written as 384 CE).
5. `Special:AddSource` needed a `transcript` class.

## Decisions

### 1. The append editor redirects to the new section

`AppendSectionEditPage` is core’s `EditPage` with a whole-page edit
(`section` stays empty), so core never derives a `sectionanchor`. On a
successful save the editor now sets `$resultDetails['sectionanchor']`:

- the **topmost heading of the typed block** (`SectionSummary::topmostHeading`,
  the same heading the auto-summary uses), encoded with core’s
  `Parser::guessSectionNameFromWikiText` + `urlencode`;
- when the block has no heading, the **target section’s own heading** (the
  `[Add section below]` case);
- a page-end append with no heading has no anchor.

The save still appends (no new heading); only the redirect fragment changes.

### 2. “Attributed to” is scoped to agent classes

`SpecialAddContentItem` passes `array_values($config->agentClasses())` to the
shared `EntityCombobox` for `attributedTo` (quotation, math and code share the
field). The suggestions are restricted to person + organization/group/collective
classes; a typed Q-id is still accepted (the server-side parse is unchanged).
`source` stays unscoped — it points at a source, not an agent.

### 3. House CSS uses Codex theme tokens

Every house CSS colour in the audited files now uses a Codex token with a
light-mode fallback (`var(--color-base, #202122)`,
`var(--background-color-neutral-subtle, #f8f9fa)`, …). The reported banner’s
`.wb-entity-confirm-line` was the concrete bug: the parent surface was remapped
to a dark background by `ThemeToggle`, while the child text stayed `#202122`.
`tests/Unit/ThemeTokensTest` fails on a new hard-coded colour literal in the
audited files, so the class of bug is caught before it ships. Vendored assets
and the deliberately white embed/player skins are excluded.

### 4. BCE dates are signed day strings

The date **widgets** were replaced by text fields that accept `YYYY-MM-DD`
(CE) or `-YYYY-MM-DD` (BCE). `WikidataCore::timeValue` preserves the
TimeValue’s sign; `WikibaseTime` validates/parses the signed day;
`SemanticEntityFlowService` writes a negative `TimeValue` with the proleptic
Julian calendar model (`Q1985786`) for BCE (CE keeps the existing Gregorian
model). Year-only harvests still normalize to `01-01` day precision, the
existing CE behaviour; preserving the source precision is a separate
improvement. `resources/datefields.js` was removed (there are no date widgets
left to normalize).

The API (`action=addsemanticentity`) accepts the same signed date strings; the
MCP server’s generated schema is refreshed from the field contract.

### 5. `transcript` source class

A new `transcript` class (French *transcription*, Esperanto *transskribo*), a
manual-only AddSource class with a **metadata-only** field set:

`title`, `description`, `language` + `additionalLanguages` / `otherLanguage` /
`labelLanguage`, `authors`, `year`, `access`.

The transcript itself is the access resource (access URL / download / local
file), not a payload stored in the item. No publisher/URL/Wikidata field. The
class maps to CSL `document`; `Source:` pages transclude the new
`Template:Transcript`. There is no Wikidata search alignment (no usable
authority for an arbitrary transcript).

## Consequences

- **Re-seed required** for the transcript class item + config map; the
  citation-source-type map must be re-imported; the new i18n message and
  parser/template output need the usual message/object/parser cache purges.
- The MCP server’s pinned `field-contract.json` is re-copied and regenerated
  (`npm run gen:field-contract`) so its `addsource` tool gains the class.
- Existing CE dates keep their current behaviour; a BCE date is now stored as
  a negative, Julian-calendar TimeValue instead of a positive Gregorian one.

## Tests

- `SectionSummaryTest` (`topmostHeading`), `run_section_actions_e2e.py`
  (redirect hash + heading id), `run_wiki_ux_e2e.mjs` (scroll/focus).
- `run_pages_e2e.py`: attributed-to scope (person/organization in, book out);
  BCE manual person (`-0384-01-01` + Julian); transcript picker + manual
  creation.
- `WikibaseTimeTest`, `WikidataPersonProviderTest` (Q868 shape),
  `SemanticEntityFlowServiceTest` (negative TimeValue).
- `ThemeTokensTest` + the dark-mode contrast checks in `run_wiki_ux_e2e.mjs`.
- `FieldMapTest` / `FieldContractTest` for the transcript class.
