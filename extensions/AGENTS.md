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
