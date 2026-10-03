# Fictional-character aliases

## Context

`Special:AddFictionalCharacter` (Wikidata search → review → create, item-only)
already captured given/family names and the works a character appears in, but
had no way to record alternate names (aliases), and the Wikidata harvest
dropped the entity's aliases.

## Decision

- **Field**: a comma-separated `alias` text field on the review/manual form
  (`SpecialAddFictionalCharacter::reviewFieldSpecs`), defaulting to the
  harvested Wikidata aliases (joined with `, `). It is a fictional-character
  field only: `SemanticEntityFieldMap::KIND_FIELDS['fictional-character']`.
- **Harvest**: `WikidataCore::harvestRaw` now requests `aliases` and returns
  them; `WikidataPersonProvider::byWikidataId` maps them through the new
  `WikidataCore::aliasValues` (English, falling back to the first language
  that has any) onto the new `PersonRecord::aliases`.
- **Persistence**: aliases are Wikibase terms, not statements.
  `SemanticEntityFlowService::buildItem` sets them on create;
  `applyUpdate` (the API path) and `UpdateExternalEntityFlow::applyUpdate`
  (the form path) set them on update with a **no-clobber** rule — only a
  non-empty field replaces the stored set. `splitAliases` (comma/semicolon,
  trimmed, de-duplicated) is the shared pure helper.
  ⚠️ `Item::setAliases( $lang, string[] )` takes an **array of strings**, not
  a `TermList` (verified in data-model 9.6.1 and master) — do not wrap it in
  a `TermList`.
- **Update prefill**: `SpecialUpdateFictionalCharacter::recordFromItem` reads
  `$item->getAliasGroups()->toTextArray()['en']`.
- **Navigation**: `MediaWiki:Sidebar` gains the semantic-tools entry
  `** special:addfictionalcharacter|addfictionalcharacter` (the bot may lack
  `editinterface`; the page edit is a deploy checklist item).

## Consequences

- No new property or config-map key — aliases are terms — so **no re-seed**.
  Deployment is an extension rsync + php-fpm restart + a message-cache purge.
- The `action=addsemanticentity` API module and the `-fields` discovery
  endpoint expose `alias` for `kind=fictional-character` automatically
  (they are driven by the field map).

## Tests

- `tests/Unit/FieldMapTest.php` — `alias` is fictional-character-scoped.
- `tests/Unit/SemanticEntityFlowServiceTest.php` — create writes aliases;
  update is no-clobber; `splitAliases` trims/dedupes.
- `tests/e2e/run_pages_e2e.py` — the AddFictionalCharacter flow posts
  `wpalias`, then asserts the created item's English aliases.
