# ADR — wiki bug + language-fallback batch 2026-10

Status: accepted (2026-10-12). Owner: ronzz.org.

Seven items from an IT report, fixed/extended in one batch: three bugs and
four language/UX requirements. No vocabulary, seed or config-map change.

## 1. `<references/>` — "(Book) in (Book)" (WikibaseCitation)

**Report**: `<ref>{{#cite:Q1982}}</ref>` on `Ordinary differential equation`
rendered *"Trench, W. F. (2013). Elementary Differential Equations with
Boundary Value Problems (Book). In Elementary Differential Equations with
Boundary Value Problems (Book). LibreTexts."*

**Cause**: two independent defects. (a) `StatementToCslConverter` fell back
to `container-title = <source label>` for a `book`-type citation, and Q1982
is a **self-cited** book (it is its own source), so the container duplicated
the title. (b) The title label carries the AddSource
class-disambiguation suffix `" (Book)"`.

**Fix**: skip the book-label `container-title` fallback on a self-cite, and
strip a single trailing parenthetical group from the CSL
`title`/`container-title` — a local mirror of
`EmbeddableContent\Spec\LabelSanitizer::stripParentheticalSuffix` (kept local
to avoid coupling the two extensions). Unit test in
`tests/Unit/StatementToCslConverterTest.php`.

## 2. `Special:AddMath` note preview — `$$…$$`

**Report**: the accompanying Note's `$$ … $$` rendered the **inner** `$…$` as
inline math and left the outer `$` literal.

**Cause**: `resources/inlinekatex.js` `typesetInlineMath` matched only
`/\$([^$]+)\$/g`; on `$$ b = c $$` it matched `$ b = c $` (inner) plus two
stray `$`. Confirmed live against Q2052's note (`where $…$ … with $$ … $$`).

**Fix**: a **longest-first** delimiter tokenizer — `$$…$$` (and `\[…\]`) as
DISPLAY before `$…$` (and `\(…\)`) as inline, mirroring the instance's
SimpleMathJax config; an unbalanced opener is skipped whole, never paired
with a later delimiter. The `addmath.js` `renderNotePlain` fallback delegates
to the same helper (`contentpreview.js` shares it too). Playwright regression
in `tests/e2e/run_addmath_ux_e2e.mjs`.

## 3. `Special:AddPerson` date of birth rejected

**Report**: picking a date from the calendar displayed "Thu, 1 Jan 1970" and
the submit failed with `htmlform-date-invalid`.

**Cause**: MediaWiki's OOUI `DateInputWidget` keeps a value it only *loosely*
parses **verbatim** — a raw Wikibase time such as `+1970-01-01T00:00:00Z`
DISPLAYS the parsed date but POSTs the raw string, which
`HTMLDateTimeField::parseDate` rejects. The exact entry point could not be
reproduced on the manual / `UpdatePerson` forms (the server feeds a strict
`Y-m-d` default there, which the widget normalizes on selection). One
concrete contributor was found: `UpdateExternalEntityFlow::timeValueForProperty`
emitted `YYYY-00-00` for a year/month-precision date (the harvest normalizes
to `YYYY-01-01`).

**Fix (defensive, per the report)**: (a) normalize year/month `00` → `01` in
`timeValueForProperty` (consistent with `WikidataCore::timeValue`); (b) a
new `resources/datefields.js` (loaded on Add/UpdatePerson) rewrites every
date widget to strict `Y-m-d` on submit — a no-op for a canonical value,
clearing a value that cannot be parsed at all.

**Superseded (October 2026)**: the date widgets were replaced by signed text
fields (`YYYY-MM-DD` / `-YYYY-MM-DD` for BCE), so `resources/datefields.js`
and the `addmodule` call were removed. See
`docs/decisions/add-ux-batch-2026-10.md` §4.

## 4. AddSource child language inheritance (bookExcerpt)

**Report**: a book excerpt should default its source language and label
language from the parent book.

**Fix**: **server-side at form-load** — `SpecialAddSource::inheritBookExcerptLanguage`
fills `language` / `additionalLanguages` / `labelLanguage` from the parent
book's `language` statement(s) and its label term language when the parent
is already known (the `?parent=Q…` deep link or a previous submit); a value
the record already provides wins, so the user's/URL's choice is never
overridden. `youtubeVideo` is out of scope (its parent is not known at
form-load). `law` already inherits server-side; the `webpage` URL-first flow
inherits from the resolved website.

## 5. Content-page "Add section"

**Report**: an ordinary content page has no way to start a new section from
the page actions.

**Fix**: `Hooks::addSectionAction` inserts a `views['addsection']` link
(target `?action=edit&section=new`) **between "Edit" (or "View source") and
"View history"** on content pages, gated on edit permission. Core only
renders this action for talk pages / pages carrying `__NEWSECTIONLINK__`. New
i18n key `embeddablecontent-addsection` (en/fr/eo).

## 6 + 7. Label/description fallback on search results and Item: pages

**Report**: an item labelled only in `fr` showed a bare `(Q2086)` (empty
label) in the search results and "No label defined" on its `Item:` page,
although the instance is multilingual.

**Cause**: Wikibase builds the term fallback chain from the **reader**
language with `LanguageFallbackChainFactory`; its chain for `en` is
`[en, mul]` — the instance's `fallbackLanguages` config (`['fr','en','eo']`)
is consulted only by our `action=entitysearch`, never by Wikibase.

**Fix**: a `MediaWikiServices` hook (`Hooks::onMediaWikiServices`) decorates
Wikibase's `WikibaseRepo.LanguageFallbackChainFactory` **and**
`WikibaseClient.LanguageFallbackChainFactory` (each only when present) with a
`LanguageFallback` that appends the instance languages — the documented
MediaWiki `addServiceManipulator()` extension point, no Wikibase fork. A
request in `en` then resolves through `[en, fr, eo, mul]`, so the search
result (`ShowSearchHitHandler` / `HtmlPageLinkRendererEndHookHandler`) and
the `Item:` page (`EntityTermsViewFactory` →
`LanguageFallbackLabelDescriptionLookup`) render the `fr`/`eo` label. The
GLOBAL `LanguageFallback` service is left untouched, so MediaWiki **message**
fallback is unaffected. The append logic is the pure
`Spec\FallbackChain` (unit-tested).

## Consequences

- **No vocabulary/seed/config-map change** for any of the seven.
- **Deploy** = extension rsync + php-fpm restart + a **message blob-store
  purge** (the new `embeddablecontent-addsection` key) + an **objectcache /
  parser-cache purge** (the client fallback changes rendered label output on
  sitelinked content pages).
- The fallback decoration depends on the Wikibase factory service names; a
  missing service is skipped (`hasService`), so the extension still loads on
  a client-less or Wikibase-less install.
- Tests: `StatementToCslConverterTest` + `FallbackChainTest` (CI `unit`),
  the `run_addmath_ux_e2e.mjs` note regression (manual/production), and the
  existing page-flow suite covers the Add* forms.
