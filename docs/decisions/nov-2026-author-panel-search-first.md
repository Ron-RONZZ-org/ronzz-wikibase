# Author panels: search-first creation + content/upload author names

Status: implemented (awaiting deployment) — 2026-11-10

## Context

Three author-entry surfaces had drifted apart:

1. **AddSource's author/member panel** already parsed a pasted name list,
   resolved each name against local Persons (`action=authorresolve`), and
   required one explicit decision per name. But its **create-new** dialog
   opened `Special:AddPerson/manual` / `Special:AddCollective/manual`
   directly, so a contributor who wanted the external authority (Wikidata,
   ORCID, VIAF, ISNI) had to leave the popup and redo the search.
2. **`Special:AddQuotation` / `AddMath` / `AddCodeSnippet`** only offered a
   Q-id entity combobox for `attributedTo`: the contributor had to create
   the author elsewhere and paste the id.
3. **`Special:Upload`'s** "specify the author as a semantic entity" mode
   was the same raw Q-id combobox.

The request: make creation start with the external-authority search, and
adopt the AddSource name → match → confirm logic on the content Add* forms
and on Special:Upload (semantic-entity mode only).

## Decisions

### 1. Search-first creation dialog

The author/member panel's create dialog now runs the **search step** of the
real `Special:AddPerson` / `Special:AddCollective` page in its iframe:

```
Special:AddPerson?name=<row name>&autofetch=1&wbparentdialog=1&wbdialogkind=author
```

`SpecialAddExternalEntity::executeSearch()` applies the shared
`Spec/RequestPrefill` (a `?name=…` deep link prefills the search form) and,
when `autofetch=1` **and** the short-lived parent-dialog marker is set, calls
`onSearchSubmit()` directly — the same gate the website URL-entry autofetch
uses (`SpecialAddSource::executeUrlEntry`). Hits redirect to the normal
selection step; zero hits / provider errors render the prefilled search form
again.

The **"create the item manually instead"** link is always rendered (search
page, zero-hit page and selection page); on the selection/zero-hit paths it
carries the stored search token, so the manual form is still prefilled. A
plain deep link without `autofetch` never triggers an external fetch.

### 2. One panel implementation

- `Fields/PersonPanelFields` builds the form fields (names textarea, Search
  button + results container, hidden decisions) for AddSource, the content
  Add* forms and Special:Upload. The Search button carries
  `data-target="<field>"`; `resources/authorsearch.js` writes the confirmed
  item ids there (AddSource keeps the `wpauthors` fallback).
- `Flow/PersonPanel` is the shared server half: parse the names, require an
  explicit decision per name, create/reuse/update the decided Person or
  collective, and return the ids. `SpecialAddExternalEntity::applyPersonPanel`
  now delegates to it (behaviour preserved, including the AddSource
  `collectiveAuthorIds` side effect and the harvested-prefill marker); the
  content Add* forms use it for their `attributedTo` target.

The JS contract is the HTMLForm field keys (`wp<prefix>Names` / `Choices` /
`NamesHarvested`, `#wb-<panel>-search` / `-results`) — one module serves
every panel.

### 3. Content items: multi-author `attributedTo`

The content Add* forms' `attributedTo` becomes a **multi-value** field
(comma/semicolon-separated ids, the `wb-entity-combobox-multi` shape) and
`SpecialContentFlowService` writes **one `attributed to` statement per id**
(create and no-clobber update). `SpecialUpdateContentItem::recordFromItem`
prefills every stored author. `QuotationLookup::invalidateClassicPages`
splits comma lists (the cache invalidation callers pass the field value
verbatim).

The field contract (`contract/field-contract.json`) lists field NAMES only,
so it is unchanged: a single Q-id remains a valid value and existing API/MCP
clients are unaffected. `action=addspecialcontent` accepts a comma-separated
list as a superset (no new parameters were added — this batch is
web-form-first).

The author panel is added to the shared `buildFields()`, so it also appears
on `Special:UpdateQuotation/UpdateMath/UpdateCodeSnippet`; a blank panel is
a no-op and an update keeps the stored authors (no-clobber).

### 4. Special:Upload: panel only in semantic-entity mode

`UploadHooks` renders the panel (`UploadAuthorNames` / `UploadAuthorSearch` /
`UploadAuthorChoices`) between the "Specify the author as a semantic entity"
checkbox and the entity combobox; `resources/uploadform.js`'s
`syncAuthorMode()` hides/shows those rows with the checkbox.
`Hooks::onBeforePageDisplay` loads `ext.embeddableContent.authorsearch` and
exports `wbAuthorCollectiveClasses` on Special:Upload.

The confirmed ids land in the existing multi-value `wpUploadAuthorEntity`
field, so `ImageUploadHelper` / `ImageItemCreator` are unchanged. The plain
free-text author mode is untouched.

The Add* image dialog (`resources/mediaeditor.js`) keeps its current author
fields — the request scoped this to Special:Upload.

### 5. JS-off / degraded paths

- The content forms apply the panel server-side, so a names-without-decisions
  submit is a form error ("Every author needs a decision"), never a silent
  drop.
- The AddSource panels keep their existing server-side validation.
- On Special:Upload the panel is a progressive enhancement: JS-off leaves
  the free-text/entity fields working as before; the entity combobox remains
  the authoritative value the server reads.

## Consequences

- No vocabulary/seed/manifest/config-map change.
- i18n: the `embeddablecontent-add-attributedto-help` wording changed
  (single → several authors) in en/fr/eo; the panel labels/help reuse the
  existing AddSource keys.
- The person entities are created immediately when a name is confirmed (the
  AddSource model); an abandoned form leaves a valid free-standing Person
  item behind.
- Deploy = extension rsync + php-fpm restart + message/parser-cache purge.

## Tests

- `tests/Unit/SpecialContentFlowServiceTest` — multi-author validation (each
  id checked) and one statement per author, duplicates deduped.
- `tests/e2e/run_pages_e2e.py` `flow_addspecialcontent_api` — a multi-author
  update writes two `attributed to` statements.
- `tests/e2e/run_authorsearch_ux_e2e.mjs` — the create dialog opens the
  search step (not `/manual`) with the name + autofetch, keeps the manual
  fallback link, and still closes on Cancel. Optional `--user/--password-file`
  exercises the auto-search path.
- `tests/e2e/run_content_authorsearch_ux_e2e.mjs` — AddQuotation panel
  confirm writes the Q-id into `attributedTo`; the Special:Upload panel is
  visible only in semantic-entity mode.

## References

- `extensions/EmbeddableContent/includes/Flow/PersonPanel.php`
- `extensions/EmbeddableContent/includes/Fields/PersonPanelFields.php`
- `extensions/EmbeddableContent/includes/Spec/SpecialAddExternalEntity.php`
- `extensions/EmbeddableContent/includes/Spec/SpecialAddContentItem.php`
- `extensions/EmbeddableContent/includes/Flow/SpecialContentFlowService.php`
- `extensions/EmbeddableContent/resources/authorsearch.js`,
  `authorsearch-dialogs.js`, `uploadform.js`
