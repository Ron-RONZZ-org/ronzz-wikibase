# Decision: AddSource `law` class (legal provision) — clause text, inherited language, translations

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSource/law` / `Special:UpdateSource`,
  the `action=addsource` contract, the `{{#content:}}` renderer, and the source vocabulary
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Editors cite a **particular clause** of a legislation (an article, a section, a
paragraph) rather than the whole act. The existing `legislation` source class
models the act; there was no way to record an individual provision with its
text, nor to attach translations of that text. The instance already had the
machinery for monolingual payloads + translations (`content text` /
`translation`, the AddQuotation cloner) and for source child classes
(`bookExcerpt → book`).

## Decision

### A new source class `law` = a legal provision

`law` is a **child of `legislation`** (a `part of` statement), manual-only (no
external authority), aligned to Wikidata **Q139959926** ("legal provision"). It
carries:

- **`reference code`** (new string property, Wikidata **P958**-aligned: the
  provision's identifier within the act — "Article 5", "§ 3");
- **`content`** — the clause text, a **monolingualtext** claim stored on the
  existing `content text` property (the quotation payload — rewritten as
  `PayloadCodec`-escaped at rest);
- **`translations`** — optional added translations, stored on the existing
  `translation` property, one claim per language (the AddQuotation storage
  shape + cloner UI);
- the inherited **`language`**: the clause text's language is read from the
  **parent legislation's `language` statement** (default `en`) and drives BOTH
  the payload claim's language AND the item label/description term language.
  The provision writes **no `language` statement of its own** — it is not an
  independent language choice.

### No title field — the label is derived

A provision has no title. The label is derived as
**`{reference code} of {parent legislation label}`** (e.g. "Article 5 of
Constitution of France"); no class-disambiguation suffix is appended (the
parent already disambiguates it). `SourceFlowService::labelFor('law', …)` is
the one place that derives it, shared by create, the page title and the update
(so the label can never drift between paths).

### Rendering — `{{#content:}}`

`ContentPayload` recognises the `law` class and renders it through the same
monolingual path as quotations: `{{#content:Q}}` renders the clause wikitext,
`{{#content:Q|fr|en}}` appends one translation block per requested language
(with the localized "{code} translation not found" fallback). The **no-arg**
form is a law exception: it renders the clause **and every available
translation** (the Source: page contract), where a quotation negotiates a
single language. The Source: page skeleton is `{{Law}}` + a `Text` section
containing `{{#content:}}` (the no-arg form resolves the page's sitelinked
item).

### Required fields

`title` is not a field; `requiredOnCreate('law')` =
`['referenceCode', 'content', 'parent']`. Authors, year, URL, etc. are not
exposed (a provision's facts are the code, the text and its parent).

## Consequences

- **Re-seed required**: the `legal provision` class + the `reference code`
  property + the `sourceClasses`/`sourceParents`/`sourceProperties` config
  keys reach the instance only through a full seed re-emission (never
  `--only=config`). Before that, the extension degrades gracefully — the
  class and fields are simply absent.
- **On-wiki template**: `Template:Law` (infobox) must exist, and
  `Template:Legislation` gains the laws child row (`{{#child-items-of:}}`).
- **API contract change** (documented by `action=addsource-fields`, and
  propagated to the MediaWiki MCP server's pinned field contract): `law` is a
  new `class`; `referenceCode`/`content` are new string fields;
  `translations` rides as a JSON array string.
- The clause is a **source** item, so it carries the standard source toolbar
  (Update, copy citation, copy mention) like every other source class.

## Tests

- Unit: `SourceFlowServiceTest` (law label/content/language/translations, no
  language statement, required fields, same-as-original translation rejected),
  `TranslationListTest` (the shared translation normalizer),
  `FieldMapTest::testSourceLawClassContract`, `FieldContractTest`
  (regenerated).

## See also

- `docs/decisions/quotation-translations.md` — the translation storage/flow.
- `docs/decisions/source-language-field.md` — the source `language` statement
  that a provision inherits.
- `docs/decisions/class-first-addsource.md` — the child-class (`part of`)
  model.
