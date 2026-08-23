# AGENTS.md — extensions Agent Instructions

## Summary

The two custom MediaWiki extensions of ronzz-wikibase: **EmbeddableContent**
(D3 + issue #7: embeddable quotation/code/math content, external-authority
entity creation, embed skin, toolbar gadget) and **WikibaseCitation** (D4:
citation formatting from Wikibase statements). Both are standalone
extensions — never forks of Wikibase.

## Purpose and Expected Behavior

### EmbeddableContent

- **Vocabulary manifests** (`manifests/`: `properties.csv`, `classes.csv`,
  `languages.csv`) + `maintenance/importVocabulary.php` — the D1 importer.
  Includes the issue-#7 ExternalId authority + citation-metadata properties
  and agent/work classes.
- **D3 renderers** (`includes/Content/`): QuoteRenderer, CodeRenderer,
  MathRenderer + FragmentSanitizer (XSS boundary).
- **Surfaces**: `Special:Embed` (bare-fragment embeds through a minimal
  `embed` skin; vendored KaTeX + highlight.js for math/code),
  `api.php?action=embed`, oEmbed, and the content pages
  (`Special:AddQuotation` / `AddCodeSnippet` / `AddMath` — all-language
  quotation input, `describes`/`implementation of` subject fields,
  code language combobox, math KaTeX preview + delimiter auto-strip,
  redirect-to-created-item).
- **Issue #7 external authorities** (`includes/Fetch/`): provider layer
  (Wikidata hub + dblp SPARQL, OpenAlex, Crossref, Open Library, ORCID —
  SSRF-allowlisted) driving `Special:AddPerson` / `AddSource` /
  `AddCollective` (login-gated, search → select → review → create, manual
  fallback, detailed candidates incl. descriptions + a "see record details"
  link to the canonical authority page; `Special:AddSource` also searches by
  author — free-text name or Wikidata Q-ids via a mode toggle). Page LOADS
  are not login-gated (bot-password sessions are API-only by MW design) —
  the search/manual SUBMIT handlers enforce login (the external-fetch /
  item-creation abuse surface).
- **Special:AddSoftware (issue #26)**: FOSS item + `FOSS:` page + sitelink;
  entity-combobox facts (developer/license/OS/user-interface/has-use,
  multi-value), programming language via the shared lexer combobox,
  validated URL facts (website, repository, documentation URL), optional
  logo upload (local file or pasted URL → `File:<Name>-logo.<ext>`, `image`
  statement + rendered in the FOSS infobox), `beforeCreate` hook on the
  base class for pre-creation side effects. Preseeded vocabulary
  (`manifests/preseed.csv` + seed `preseed` phase): common operating
  systems, FOSS licenses and user interfaces classified under their parent
  classes.
- **Classic pages for Person/Source/Collective (issue follow-up, ADR
  `docs/decisions/pages-and-fields.md`)**: the AddSoftware page machinery
  (afterCreate → sitelink → `complete/<id>` finalize) moved into the base
  class; `Special:AddPerson` creates a `Person:` page (Template:Person),
  `Special:AddCollective` a `Collective:` page (Template:Collective),
  `Special:AddSource` a `Source:` page with a per-class template
  (Book/ScholarlyArticle/Website/Song/Film/Video/YouTubeChannel/
  YouTubeVideo/Webpage). **bookExcerpt creates NO page** (part of a book).
  Namespaces Person (2010/2011), Source (2012/2013), Collective (2014/2015)
  in dev config + production LocalSettings.
- **AddPerson lifecycle fields**: VIAF/ISNI search (Wikidata-hub-only),
  day-precision date of birth/death + entity-combobox place of birth/death
  with a "This person is deceased" toggle revealing the death fields;
  `personProperties` config section (P569/P19/P570/P20-aligned). The label
  field is gone (issue #35): the label is the full name, auto-derived from
  given/family (`primaryLabel`); a harvested label-only candidate keeps its
  label. The search `name` box autofills the manual form as given/family
  (every word except the last = given, last word = family — pure
  `NameSplitter`).
- **AddSource bookExcerpt**: optional chapters (new string property,
  P2635-aligned) + volume fields; blank description auto-generates as
  "Pages a-b (Volume c) of {book}"; blank year/authors infer from the
  parent book's `date`/`attributed to` statements. The class picker says
  "Source type" + "Continue". Persistence fixes: the description is stored
  as the item's en term and the year as a `date` statement (both were
  discarded before).
- **Special:AddSource class-first (issue follow-up, ADR
  `docs/decisions/class-first-addsource.md`)**: the root page is a class
  picker routing to class-scoped subpages (`/<classKey>`, `/<classKey>/manual`,
  `/<classKey>/<token>[/review/<i>]`). Per-class search and review fields;
  child classes (`bookExcerpt→book`, `webpage→website`,
  `youtubeVideo→youtubeChannel`) require an existing parent-class item
  (entity combobox + "import it yourself" link, server-side validated) and
  auto-write a `part of` statement. Every class requires ≥1 author entity
  (agent-class validated → `attributed to` statements). `website`/`webpage`/
  `bookExcerpt` are manual-only classes. Duration is entered as
  `(HH):MM:SS` and stored as seconds in the `quantity`-datatype property.
  YouTube import (Data API v3, key deploy-injected + IP-restricted, never
  in the repo): name search capped at 10, URL lookups exact-only.
- **AddSource publisher is entity-only (issue #35)**: book/scholarlyArticle
  take the publisher as an entity combobox (item-typed `publisher (entity)`
  property, P123-aligned) — no free-text mode. A harvested STRING publisher
  resolves to an existing item by exact label, otherwise shown as context
  with a "create the item first" hint. The legacy string `publisher`
  property is no longer written; `tools/migrate_string_publishers.py`
  converts existing string statements (find-or-create publisher item →
  entity statement → remove string statement; `--dry-run`/`--verify`).
- **AddSource access field (issue #35)** on book/scholarlyArticle/song/film/
  bookExcerpt: `accessMode` toggle url (non-direct `access URL` statement,
  P953-aligned) | download (server-side `UploadFromUrl`, SSRF-guarded) |
  file (browser `UploadFromFile`). download/file require a license entity
  (reuses the P275 license property) + show the copyright warning, and save
  the file as `File:<label>.<ext>` (auto-named, original filename ignored,
  auto-generated page text) linked via the `file` property (P1325-aligned).
  `$wgFileExtensions` must include pdf/epub/djvu (production + dev config).
  A filled-in access field that cannot be honoured aborts creation with a
  form error.
- **Manual-addition entry points + search autofill (issue #35)**: the
  "No matching record? Create the item manually instead" link appears on
  the zero-hit search page AND the candidate-selection step (both carry
  `?token=`); the AddSource class picker has a "create manually instead"
  checkbox routing to `/<classKey>/manual`. Search inputs are stored in the
  session under the token and prefill the manual forms: AddPerson
  name→given/family (`NameSplitter`), AddSource title/author(entity)/
  isbn/doi, AddSoftware/AddCollective name→label. (Follow-up: the picker
  checkbox was removed — the link says just "Create the item manually
  instead", and the titles match their URLs, e.g. `Special:AddSource`.)
- **Website/webpage URL-first flow (follow-up)**: `/<classKey>` for
  website/webpage is a URL entry page; the submit runs an SSRF-guarded
  metadata fetch (`SsrfGuard` literal checks + MW `rejectLocalUrls`
  transport; `HtmlMetadataParser` regex extraction of `<title>`/`og:*`/
  `meta description`/first paragraph) and autofills the manual form via the
  session token. A `/website` URL collapses to its site root; `/website`
  has no year field.
- **Fetched page-content review step + per-class pages (follow-up)**: page
  content — scholarlyArticle abstract+keywords (OpenAlex inverted-index
  reconstruction, Crossref fallback), book/song/film/person Wikipedia lead
  intros and `== Plot ==`/`== Lyrics ==` sections (fixed-host
  `WikipediaContentProvider`, SSRF-safe), website/webpage site metadata —
  is fetched at harvest-on-pick (`harvestContent` hook, best-effort),
  reviewed on a dedicated `/review/<i>/content` (and `/manual/content`)
  step of multi-line textareas with `from {source}:` attributions, then
  written to content-driven per-class `Source:`/`Person:` page skeletons
  (sections only when content exists; no blank scaffolds, no See also).
- **AddSource scholarlyArticle (follow-up)**: entity-only **Journal**
  (new `journal (entity)` property, P1433-aligned; the citation source map
  `container-title` points at it), access toggle gains an **N/A** mode,
  and the license combobox is pre-populated from the seed's `licenses`
  config map. OpenAlex ids are stored **bare** (`W2741809807`), and
  `OpenAlex author ID` (P5092-aligned) covers persons.
- **Special:AddFictionalCharacter (follow-up)**: Wikidata search → review →
  create (item-only); given/family + multi-value `present in work`
  ("Appears in", P1441); label autogen `{given} {family} (fictional
  character)`, description autogen `fictional character in {…}`.
- **Combobox autocomplete fix (follow-up)**: the entity-suggest module
  targets `.wb-entity-combobox.oo-ui-comboBoxInputWidget` (not the
  FieldLayout wrapper, whose data-ooui is a different type), reads the text
  input via `combo.$input` (no `.input` sub-widget in this OOUI), and
  queries raw + title-cased + uppercase variants of the typed text because
  the instance's `wbsearchentities` is case-sensitive (upstream T242644).
- **Instance data rights are CC BY-SA 4.0** (seed `dataRightsUrl` /
  `rdfDataRightsUrl`), matching the CC BY-SA sourced page content and
  contributor licensing.
- **Sitelink tab (issue follow-up)**: `SkinTemplateNavigation::Universal`
  adds a red/blue **Sitelink** tab next to Page/Discussion on every content
  page — red = not linked (click → OOUI dialog, `wbsearchentities` search or
  direct Q-id → `wbsetsitelink`), blue = linked (→ Item page).
- **Entity-page toolbar gadget** (copy embed with absolute URL + language
  selector / copy citation) and entity-combobox autocomplete.

### WikibaseCitation

- **Citation map manifests** (`manifests/`) + `maintenance/importCitationMap.php`
  (publishes the 4 admin-editable `MediaWiki:Citation-*` pages).
- **D4 `api.php?action=citation`** with **citeproc-php** (APA/Vancouver,
  vendored CSL styles in `styles/`) and native BibTeX/RIS serializers.
- Issue #7: CSL type follows the **source** class; harvested source fields
  (published in, publisher, pages, volume, issue, DOI, ISBN). No Node sidecar.
- **Cite-by-QID (issues #24 v1 + #25 v2, spec `docs/decisions/cite-by-qid.md`)**:
  `{{#cite:Q42|style=|output=}}` parser function (usable inside `<ref>` with the
  stock Cite extension) + `{{#citations:}}` bibliography collector, both backed
  by the shared `CitationEngine` service (the single rendering path — entity id
  → item → CSL-JSON → formatted string with the revId-keyed BagOStuff cache).
  `{{#citations:}}` accumulates source ids via ParserOutput extension data
  (deduped by source item) and substitutes its placeholder in `ParserAfterTidy`;
  explicit `{{#citations:Q42|Q7}}` renders immediately. v2: multi-entity refs
  (`{{#cite:Q42|Q7}}`), ParserCache invalidation via `ParserOutput::addTemplate()`
  on cited entities + sources (templatelinks/RefreshLinksJob — the 1.46
  substitute for the non-existent `addCacheDependency()`), embed auto-collect
  (HTML scan at ParserAfterTidy for `Special:Embed`/`action=embed` ids), and a
  `page(s)` qualifier on the `source` statement → CSL locator.
  The `html` output is allowlist-sanitized (`CitationSanitizer`) before it
  reaches any caller. Self-cite prerequisite: a source-class item cited
  directly reads source-level fields from itself
  (`WikibaseCitationSourceClasses` config, defaulted from
  `EmbeddableContentConfig['sourceClasses']`).

## Constraints and Invariants

- **Standalone extensions, never forks of Wikibase** — deep integration via
  the stable Wikibase service API (`WikibaseRepo::getEntityLookup()`,
  `WikibaseRepoEntityTypes` hooks), like WikibaseMediaInfo / EntitySchema.
- Upstreamable from day one: `extension.json`, i18n (**en/fr/eo**), MediaWiki
  coding conventions, PHPUnit + MediaWiki integration tests,
  **GPL-2.0-or-later**.
- **No Node sidecars/services** — the citation sidecar was removed; D4 is
  in-process citeproc-php.
- **No number-mirroring of Wikidata P-numbers** — ontology alignment as data
  (mirror properties + equivalent-property statements).
- Vendored third-party assets (`resources/katex/`, `resources/highlight/`,
  `styles/*.csl`) — never re-fetch from CDNs at runtime.
- The fetch providers must stay **SSRF-allowlisted** (no arbitrary URLs).
- The XSS suite is mandatory for EmbeddableContent — injections must not
  survive rendering on any embed surface.

## Input/Output Expectations

- **Input**: manifest CSV/JSON (vocabulary, citation maps), Wikibase
  entities (statements), user input on the `Special:` pages / embed API.
- **Output**: rendered embed fragments (quote/code/math), formatted
  citations (APA/Vancouver/BibTeX/RIS), created entities from external
  authorities, oEmbed responses.
- **Unit-test surface**: `tests/Unit/` (pure-PHP, no MediaWiki runtime) —
  renderers, sanitizer, manifest readers, citation converters/serializers.

## Documentation Reference

- `docs/README.md` — stack table (installed extensions + skins/Translate)
- `docs/decisions/` — `opaque-id.md` (fork maintenance rejected),
  `ontology-alignment.md`, `raw-rdf-in-blazegraph.md`
- `docs/contribution-guide.md` — pointer to the on-wiki `Help:Contributing`
  family (editor-facing usage of the content pages)
- `composer.json` — dev-only PHPUnit/data-model deps; WikibaseCitation's
  own `composer.json` (citeproc-php) installs its `vendor/` in the
  extension dir

## Domain-Specific Rules for Agents

- Never modify Wikibase itself — if integration needs more surface, use the
  service API or hooks; document why in a decision.
- Keep the fetch layer boundary clean: providers implement
  `HttpClientInterface` and return `ProviderResult`/`*Record` DTOs — mock
  providers only at that boundary in tests.
- Sanitize all user content before rendering (FragmentSanitizer) — the XSS
  suite is the gate.
- i18n changes must ship all three languages (en/fr/eo).
- Manifest changes are data, not code: review them like schema migrations
  (they feed D1 importers and the seed's exact-label skip).
