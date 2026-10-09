# Decision: Label language on the semantic-entity Add* forms

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddPerson` / `AddSoftware` /
  `AddCollective` / `AddFictionalCharacter` (and their `Update*` counterparts)
  + the `action=addsemanticentity` API contract
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Some entities have no readily available English name. A collective, a person
or a fictional character whose name is written natively (Greek, Chinese,
Arabic, …) was previously forced into an English-typed label: the
`SemanticEntityFlowService` hardcoded `en` when writing the item's label,
description and aliases (`buildItem` / `applyUpdate`).

`Special:AddSource` already solved the same problem with a dedicated
`labelLanguage` field (ADR `source-language-field.md`): the source language(s)
are citable metadata, while the **term language** the label/description are
stored under is a separate, user-chosen value (default `en`).

## Decision

1. **Every semantic-entity kind gains the `labelLanguage` field** (person,
   software, collective, fictional-character, other) — the same contract as
   AddSource, not a collective-only special case. It is added to
   `Flow/SemanticEntityFieldMap::ALL_FIELDS` and to every `KIND_FIELDS` entry,
   so the `action=addsemanticentity` API and its `-fields` discovery endpoint
   expose it too.
2. **`SemanticEntityFlowService` writes the terms under it** —
   `buildItem()` stores the label/description/aliases in the chosen language
   (default `en`); `applyUpdate()` writes them there and **moves** the term
   (the old-language term is removed — the chosen-language-only contract). A
   **blank** `labelLanguage` on update keeps the item's current language
   (no-clobber): the shared `UpdateExternalEntityFlow::updateTermLanguage()` is
   now generic (the AddSource override is deleted).
3. **One shared field builder** —
   `SpecialAddExternalEntity::labelLanguageFieldSpec()` renders a combobox
   over MediaWiki's own language set (valid term languages, English names),
   code-labelled `"{code} — {name}"` (the AddSource shape). The four Add\* forms
   expose it; the `Update*` `recordFromItem()` prefills the item's current
   label language.
4. **The label/description term language is independent of the item class**
   and of any statement; only the terms move.

## Consequences

- A native-language entity is stored under its real language; the classic
  page title is still derived from the label text (capitalization unchanged).
- The field contract is re-emitted (`contract/field-contract.json`) and the
  MediaWiki MCP server's pinned copy + generated tool validators are
  refreshed (the generator's `satisfies Record<Field, …>` guard forces the
  new `labelLanguage` validator).
- No vocabulary/seed/LocalSettings change — terms are not statements.
- Deploy = extension rsync + php-fpm restart + parser-cache/message purge.

## References

- `extensions/EmbeddableContent/includes/Flow/SemanticEntityFieldMap.php`
- `extensions/EmbeddableContent/includes/Flow/SemanticEntityFlowService.php`
- `extensions/EmbeddableContent/includes/Spec/SpecialAddExternalEntity.php`
  (`labelLanguageFieldSpec`)
- `docs/decisions/source-language-field.md` — the AddSource precedent
- `tests/Unit/SemanticEntityFlowServiceTest.php`, `tests/e2e/run_pages_e2e.py`
