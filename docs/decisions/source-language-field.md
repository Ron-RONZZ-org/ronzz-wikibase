# Source language field (Special:AddSource)

## Context

`Special:AddSource` created every source item with an `en` (English) label and
description — the only term language the flow ever wrote. The instance is
multilingual (en/fr/eo): a French book or article should carry a French label,
and the work's language is itself citable metadata (CSL `language`).

## Decision

- **Field**: a `language` combobox on the AddSource review/manual forms
  (all classes) and on `Special:UpdateSource`, listing MediaWiki's supported
  languages; default `en` (the historical behaviour). The submitted value is
  a BCP-47 code.
- **Two effects, one field**:
  1. **Statement** — the code is written as the new string property
     `language` (manifest `properties.csv`, `schema:inLanguage` alignment,
     `sourceProperties.language` config key, `EmbeddableContentConfig::
     sourcePropertyIds()`). It surfaces as the CSL top-level `language` field
     (`StatementToCslConverter::SOURCE_LEVEL_FIELDS` +
     `citation-source-property-map.json`).
  2. **Term language** — the item label and description are stored under the
     chosen language, and **only** that language (chosen-language-only: no
     parallel English copy). The class-disambiguation suffix stays English
     (`The Hobbit (Book)`).
- **Create**: `SourceFlowService::buildItem` writes label/description under
  `termLanguage($record)` and `statementSpecs` writes the language statement.
- **Update**: `SourceFlowService::applyUpdate` and
  `UpdateExternalEntityFlow::applyUpdate` write under the record's language
  and **move** the term — the previous-language label/description is removed
  on a language change. `SpecialUpdateSource::recordFromItem` prefills the
  field from `itemTermLanguage($item)`; `updateTermLanguage()` is the
  overridable hook (default `en`, so person/software/collective unchanged).
- **Language-aware label lookups**: because a non-English item carries no
  `en` label, the create-or-skip reuse (`findItemIdByLabel`) and the
  duplicate-guard label signal (`EntityLabelMatcher` →
  `DuplicateFinder`/`DuplicateChecker`) take the record's language and search
  the term store in it. External-id/URL duplicate matching is unaffected.
- **Contract**: `language` is a `SourceFieldMap` field, so
  `action=addsource`, `action=addsource-fields` and the pinned MCP
  field-contract gain it automatically (re-emit
  `contract/field-contract.json` and regenerate the MCP server tools).

## Consequences

- **Re-seed required** (new property + config-map key) — D1 importers + full
  seed re-emission on deploy. Code deployed before the seed degrades
  gracefully: the label language still follows the field; only the statement
  needs the property.
- The update flow can move a label between languages. An editor-added label
  in a third language is not touched unless it is the item's current (first)
  label — the flow manages one term.
- `Special:UpdateSource` shows the field; `Special:UpdatePerson` /
  `UpdateSoftware` / `UpdateCollective` are unaffected (their
  `updateTermLanguage()` keeps `en`).

## Tests

- `tests/Unit/SourceFlowServiceTest.php` — language statement + fr-only label;
  blank defaults to en; invalid code rejected; update moves label/description.
- `tests/Unit/FieldMapTest.php` — every class exposes `language`.
- `tests/Unit/StatementToCslConverterTest.php` — the language statement maps
  to CSL `language`.
- `tests/e2e/run_pages_e2e.py` — creates a `text` source with
  `wplanguage=fr`; asserts the `language` statement and an fr-only label.
