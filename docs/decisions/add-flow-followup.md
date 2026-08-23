# Decision: Add-flow follow-up — URL-first fetch, content review step, per-class Source pages, fictional characters

- **Status**: Accepted (Aug 24 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSource` / `Special:AddPerson` /
  `Special:AddFictionalCharacter`, their vocabulary, and the page-content fetch layer
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The issue-#7/#35 creation flows left several editor-facing gaps: the `website`/`webpage`
classes demanded a URL, `scholarlyArticle` could not express its journal or its access
situation, fetched records carried no page prose (nobody wants to hand-write an abstract),
the `Author(s)` combobox autocomplete was dead on every page, and there was no way to add a
fictional character.

## Decision

### 1. Website/webpage URL-first flow

- `Special:AddSource/<classKey>` for `website`/`webpage` is now a **URL entry page**, not a
  jump to the manual form. Submit → SSRF-guarded metadata fetch (title/description/intro/
  keywords from `<title>`, `og:*`, `meta description`, first paragraph) → session-token
  autofill into the manual form.
- SSRF defence is layered: the pure literal guard (`SsrfGuard`: scheme/hostname/IP-literal
  checks) runs first; the transport (MediaWiki `HttpRequestFactory` with `rejectLocalUrls`)
  refuses private/loopback resolution at connect time.
- A `/website` URL is collapsed to its **site root** (`https://www.bbc.co.uk/article1` →
  `https://www.bbc.co.uk`); the `/website` **year field is removed** (a website is dynamic).
- The metadata extraction (`HtmlMetadataParser`) is regex-based, pure, length-capped and
  unit-tested — no DOM dependency.

### 2. Fetched page content is reviewed on its own step, then written to per-class pages

- Content (scholarlyArticle abstract + keywords; book summary; song lyrics; film plot;
  Wikipedia lead intros; person biography) is fetched at **harvest-on-pick**
  (`harvestContent` hook), best-effort and never fatal.
- Sources: **OpenAlex** (abstract reconstructed from `abstract_inverted_index` + keywords,
  Crossref direct-text fallback), **Wikipedia** (REST summary intro; `== Plot ==`/`== Lyrics ==`
  sections from the article wikitext via the enwiki sitelink — fixed-host provider,
  SSRF-safe by construction), and the **site's own metadata** for website/webpage.
- A dedicated **content review step** (`/review/<i>/content`, `/manual/content`) presents the
  fetched prose in multi-line textareas with a `from {source}:` attribution line (Wikipedia
  text carries its CC BY-SA attribution). The step is **skipped when nothing was fetched**.
- `Source:`/`Person:` page skeletons are **class-aware and content-driven**: sections render
  only when their content survived the review (no blank scaffolds, no `== See also ==`);
  `website`/`youtubeChannel` take a heading-less intro paragraph.
- **Instance data rights changed CC0 → CC BY-SA 4.0** (`dataRightsUrl`/`rdfDataRightsUrl`),
  matching the CC BY-SA sourced content and contributor licensing.

### 3. scholarlyArticle review-form fixes

- **Journal** is entity-only (new `journal (entity)` property, P1433-aligned), replacing the
  string "Published in" field; the citation source map's `container-title` now points at it
  (the CSL converter already resolves entity values to labels).
- The **access** toggle gains an **N/A** mode (access only via archives, etc.); the **license**
  combobox is pre-populated with the instance's known license items (the seed emits the
  preseed licenses as a `licenses` config map).
- **OpenAlex IDs are stored bare** (`W2741809807`, not the full URL); `OpenAlex author ID`
  (P5092-aligned) added for persons.

### 4. Combobox autocomplete fix (three root causes)

Verified live with Playwright against the dev stack: (a) MW 1.46 renders the field-layout
wrapper AND the widget with the `wb-entity-combobox` class + `data-ooui` — infusing the
wrapper as a combobox threw and aborted the wiring loop; (b) this OOUI's
`ComboBoxInputWidget` has no `.input` sub-widget (`$input` is the text input); (c) the
instance's `wbsearchentities` is case-sensitive (upstream Wikibase T242644, `wbx_text` is
VARBINARY) — the JS now queries raw + title-cased + uppercase variants and merges hits.

### 5. Special:AddFictionalCharacter

- New class `fictional character` (Q95074-aligned) + `present in work` property
  (P1441-aligned, multi-value "Appears in"). Wikidata search → select → review → create
  (item-only, no classic page). Label auto-generates as `{given} {family} (fictional
  character)` (harvested-label fallback); description auto-generates as
  `fictional character in {…}` when blank.

### 6. UX strings

- Page titles match their URLs (`Special:AddSource` / `Special:AddPerson` /
  `Special:AddCollective` / `Special:AddSoftware`); the AddSource search step says
  `Search for {book|scholarly article|song|film} … from an external authority`; the
  "No matching record?" preface is gone (just "Create the item manually instead"); the
  picker lost its redundant "(part of …)" suffixes, its "Source type" label and the manual
  checkbox; "Web page" → "Webpage"; the AddPerson legend is "Search for a person".

## Consequences

- The `published in` string property remains in the vocabulary (immutable datatype) but is no
  longer written for scholarly articles; the `container-title` citation-map entry moved to
  `journal (entity)` (re-imported via `importCitationMap.php --force`).
- Content fetches add latency to harvest-on-pick and creation submits (short timeouts,
  best-effort); live external authorities (Wikidata/OpenAlex/Wikipedia/Crossref) make the
  page-flow E2E occasionally flaky — re-run per the E2E conventions.
- The seed must re-emit the config (`--config-out`) for the new vocabulary keys
  (`journal`, `openalexAuthor`, `fictionalCharacterClasses/Properties`, `licenses`,
  person `image`/`license`) and the CC BY-SA data rights; the D1 importers must run first.
- On-wiki contributor guidance (`Help:Contributing` family) should describe the new steps.
