# Quotation translations (`{{#content:Q|fr|en}}`) + the "Add translation" field

## Context

A quotation item stores its text as a `content text` monolingualtext claim
(one claim per language). `Special:AddQuotation` writes exactly one claim (the
language chosen in the form), and `{{#content:Q}}` negotiates the display
language from the page language → `en` → the first claim. The seed dogfood
quotation carried en/fr/eo claims under `content text`.

Editors need to (a) attach explicit translations to a quotation and (b) render
the original plus a chosen set of translations from one call, with a visible
fallback when a translation is missing.

## Decision

### Storage — the original is the payload, translations are a new property

- **New monolingualtext property `translation`** (manifest `properties.csv`,
  the seed's config map `translation`, `EmbeddableContentConfig::translationPropertyId()`).
  The `content text` claim is the **original** (the text entered at creation);
  each added translation is one `translation` claim in its own language.
- Keeping the two in SEPARATE properties makes "original vs translation"
  explicit (no reliance on statement order) and the update no-clobber clean:
  a partial update that omits `translations` never touches them; a
  present-but-empty list clears them.
- The seed dogfood quotation becomes base `en` + `fr`/`eo` translations
  (`seed/dogfood.py` `QUOTATION_ORIGINAL_LANGUAGE`; `seed/seed_instance.py`).
  `tools/migrate_quotation_translations.py` moves the extra `content text`
  claims of pre-existing items (e.g. the production dogfood) to `translation`.

### "Add translation" field (browser + API)

- `Special:AddContentItem` (quotation only) gains an HTMLForm **`cloner`**
  field (`translations`): a language combobox + a translated-text textarea per
  row, with the cloner's add/remove controls and array submission
  (`wptranslations[i][language|content]`). A small `resources/translations.js`
  turns the delete control (label `✕`) into an `OO.ui.confirm()`-guarded
  removal — the capture-phase listener runs before the cloner's own handler.
- `SpecialContentFieldMap` gains `translations` (quotation only);
  `SpecialContentFlowService::prepare()` validates each language code, rejects
  a blank/duplicate/same-as-original language, and escapes the text;
  `statementSpecs()` writes the base to the payload property and the
  translations to `translation`. Blank rows are dropped.
- `action=addspecialcontent` accepts `translations` as a **JSON array of
  `{language, content}` rows**; the discovery endpoint reports the resolved
  `translation` property id.
- `Special:UpdateQuotation` prefills the rows from the item's `translation`
  claims (decoded).

### `{{#content:Q|fr|en}}`

- `ContentArgs::languages()` reads the language codes after the optional item
  id (`noNote` and non-codes are skipped). With no language arguments the
  historical page-language negotiation runs over the base AND the translations
  (`ContentPayload::negotiateText`).
- With arguments, `ContentPayload` renders the **original** (the base claim)
  followed by one block per requested language via
  `ContentWikitext::quotationTranslations()`:

  ```
  ''original''

  '''fr translation:'''

  Le texte traduit.

  '''eo translation:'''

  eo translation not found
  ```

  The header and the `{code} translation not found` fallback are localized
  (`embeddablecontent-content-translation-header` /
  `-notfound`, en/fr/eo); the language **code** is shown (not its name).
- The embed surfaces (`Special:Embed`, `action=embed`, `Special:QuotationsOf`)
  keep their framed chrome; `ContentRenderer::extractPayload()` unions the
  `translation` claims so `lang=fr` / `lang=eo` / `lang=all` keep working after
  the originals move to the dedicated property.

### Mention label strip

The "Copy internal mention" button now drops a trailing parenthetical group
from its display label (`LabelSanitizer::stripParentheticalSuffix()`),
matching the documented `''[[Source:Beloved (Book)|Beloved]]''`; the link
target keeps the full page title. See
`mention-and-quotation-attribution.md` (amended).

## Consequences

- **Re-seed required**: the new `translation` property + the config-map key.
  Deploy = extension rsync + `importVocabulary.php --type=property` + a seed
  config re-emission (full vocabulary, never `--only=config`) + php-fpm restart
  + parser-cache + message-blob purges. No LocalSettings change.
- Production data: run `tools/migrate_quotation_translations.py` (dry-run
  first) to move the existing multi-claim `content text` quotations to the new
  model.
- `{{#content:Q}}` with no arguments is unchanged for existing items (the
  negotiation now unions the translations); `{{#content:Q|langs}}` is the new
  explicit form. Only quotations accept `translations`.

## Tests

- Unit: `ContentArgsTest` (language parsing), `ContentWikitextTest`
  (translation blocks + not-found), `SpecialContentFlowServiceTest`
  (escape/write, validation, clear-on-empty, preserve-when-absent),
  `FieldMapTest` (quotation-only), `SsrfGuardTest` (IDN hosts),
  `LabelSanitizerTest` (suffix strip).
- E2E: `run_e2e.py rich` (`{{#content:Q|fr|eo}}` renders the translation +
  the not-found fallback); `run_pages_e2e.py`
  `flow_quotation_translation` (the cloner form stores the claim and
  `Special:UpdateQuotation` prefills it); the classic-page toolbar flow still
  asserts the stripped mention label.
- Tools: `tools/tests/test_migrate_quotation_translations.py`.
