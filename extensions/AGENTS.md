# AGENTS.md — extensions Agent Instructions

## Summary

The two custom MediaWiki extensions of ronzz-wikibase: **EmbeddableContent**
(D3 + issue #7: embeddable quotation/code/math content, external-authority
entity creation, embed skin, toolbar gadget) and **WikibaseCitation** (D4:
citation formatting from Wikibase statements). Both are standalone
extensions — never forks of Wikibase.

Plus **vendored third-party** extensions: **DPLforum** (the forum — see
`DPLforum/VENDORED.md` for provenance and `../docs/decisions/forum-dplforum.md`
for the choice rationale) and **InputBox** (the `<inputbox type=create>`
thread-creation field on forum boards — `InputBox/VENDORED.md`). They are
upstream code copied into this repo (the instance deploy model), NOT
house-written extensions: do not modify their `src/`/`includes/` without a
documented reason; treat upgrades as re-vendoring the upstream commit.
DPLforum registers the `Forum:` (110) / `Forum_talk:` (111) namespaces via
its `extension.json` and provides the `<forum>` parser tag + `#forumlink`;
boards and threads are ordinary wiki pages.

Plus a second **vendored third-party** extension: **Diagrams** (integrated
diagram rendering — see `Diagrams/VENDORED.md` for provenance and
`../docs/decisions/diagrams.md` for the choice rationale). Same rules:
upstream code, do not modify, upgrade = re-vendor. It provides the `<uml>`
(PlantUML), `<graphviz>` and `<mscgen>` tags rendered **server-side** by
local binaries — apt `graphviz`/`mscgen` plus the **pinned PlantUML jar**
from `tools/install-plantuml.sh` (NOT the apt `plantuml` package: Ubuntu
noble's 1.2020.2 predates PlantUML security profiles), running under the
**SANDBOX** profile (no local-file access, no URL fetching) — and the
`<mermaid>` tag rendered **client-side** by the bundled mermaid.js. Rendered
SVG/PNG files are cached in the wiki file store under `images/diagrams/`
(per-source-hash). The config lives in `dev/config/Extensions.php` /
production LocalSettings (`$wgDiagramsDefaultFormat = 'svg'` +
`$wgDiagramsLocalCommands['plantuml'] = 'env PLANTUML_SECURITY_PROFILE=SANDBOX /usr/local/bin/plantuml'`).

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
- **Source: access row + Special:SourceFile (follow-up, ADR
  `docs/decisions/add-flow-followup-2.md`)**: the `{{#source-access:}}` parser
  function (magic word `sourceaccess`, spelling `source-access`, en/fr/eo)
  renders the "Access" infobox cell of a Source: page from the sitelinked
  item's statements — `file` (linked to `Special:SourceFile?item=<Q>&file=<File:`
  `title>`), else `access URL` (clickable link), else localized "N/A" — with
  the item registered as a parser-cache dependency (the WikibaseCitation
  `addTemplate` pattern). `Special:SourceFile` renders a self-hosted PDF
  iframe preview (no PdfHandler needed), the licence from the item's
  `license` statement (label + licence-text URL), and a download button gated
  on a required licence-acceptance checkbox (server-side; login-gated submit,
  public load). The 4 access-field templates that create pages
  (Book/ScholarlyArticle/Song/Film) gain `| Access || {{#source-access:}}` on
  the wiki.
- **Entity descriptions up to 2000 chars (follow-up)**: the term store's
  `wbt_text.wbx_text` column is widened to `VARBINARY(2000)` (production ALTER
  + a CI step after first-boot install) and
  `$wgWBRepoSettings['string-limits']['multilang']['length']` is raised to
  2000 (production + dev config). The shared `multilang` limit applies to
  labels/aliases too at the storage level, but the Add* forms keep the label
  field at 250 (page titles) and raise only the description field
  (`descriptionFieldSpec` maxlength 2000).
- **Class selection moved to the review step (follow-up)**: the search
  selection step dropped its "Class" field (it only picks the record); the
  review step's class field (pre-selected by the harvest inference) is where
  the class is chosen.
- **AddCollective parent organization + logo (follow-up)**: new P749-aligned
  `parent organization` property (manifest + `collectiveProperties` config
  section); the AddCollective review/manual form has an optional entity
  combobox writing the statement (empty = none, unparseable = skipped), plus
  an optional **logo** (local file or pasted URL → `File:<label>-logo.<ext>`,
  `image` + mandatory `license` statements — the AddSoftware logo pattern).
- **AddSoftware logo license (follow-up)**: the logo upload now requires a
  license (the AddPerson portrait contract) — the new `Logo license` entity
  combobox writes the shared P275 license statement alongside the software's
  own license facts; validated in `beforeCreate` (required only when a logo
  is provided).
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
  When NO content was fetched, the item description is the page's
  placeholder lead — `== Overview ==` for Person/Collective/FOSS and the
  section-based Source classes, a heading-less intro for
  website/youtubeChannel (`pageSkeleton` contract, E2E-asserted).
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
- **Upload enhancements (todo.md batch)**: `Special:Upload` + the Add\*
  portrait/logo sections share one upload model. Fetch layer: the whole
  provider cascade + Wikipedia content run through `RateLimitedHttpClient`
  (WMF min-interval throttle + 429 `Retry-After`/backoff — the `fceb99d`
  fix); `ProviderException` carries status + `Retry-After`. Upload metadata
  ("Validate" button, `resources/uploadmeta.js` + `api.php?action=uploadmeta`):
  Wikimedia hosts are fetched from the **browser** (`commons.wikimedia.org/
  w/api.php?origin=*` — CORS-open, residential IP) with server-side fallback;
  other hosts use the SSRF-guarded server API. A Wikimedia URL-mode upload is
  converted at submit time into a browser-supplied file upload (429 blob
  fallback, 100 MB client-side cap — `MAX_BLOB_BYTES`, matched to
  `$wgMaxUploadSize['url']`). The modules driving this (entitysuggest +
  uploadmeta) are loaded on the Add\* pages AND on Special:Upload
  (`Hooks::onBeforePageDisplay`, `$title->isSpecial('Upload')`) — without
  that the validate button never rendered and the blob fallback never fired
  on Special:Upload (follow-up fix; the submit-time URL-mode check is
  case-normalised because Special:Upload's core radios are `Url`/`File`, not
  lowercase `url`). PR #51 follow-up: the submit handler now parses
  `new URL(url).hostname` before `isWikimediaHost` (passing the full URL made
  the host check always false and the blob fallback never fired — the 429's
  real root cause); the fetched description is capped at 2000
  (`DESCRIPTION_CAP`, sentence-boundary cut — never mid-sentence) and the
  dest-name autofill is normalized via `normalizeDestName` (lowercase, any
  word separator — space/underscore/camelCase/dash — → dash, unicode-aware;
  extension appended by MW core from MIME). `includes/Upload/ImageUploadHelper` owns
  the shared portrait/logo field specs + upload path (mode toggle, collapse
  "I will upload a {image}" checkbox, license combobox, free-text author +
  license-info, dest naming, verify+performUpload) — AddPerson portrait and
  AddSoftware logo use it; AddSoftware's logo gained the mandatory license.
  `UploadHooks` (Special:Upload): semantic license combobox replacing the
  core dropdown, author/license-info fields, single max-size note + a URL-cap
  note (per-key `$wgMaxUploadSize`: `'*'`/file 1 GiB, url 100 MB — MW 1.46's
  array form needs the `'*'` wildcard for the general default), File-page
  attribution block (`[[Q42|label]]`, never a `{{Q42}}` template call), and
  marker-gated `UploadComplete` item-per-upload via `ImageItemCreator`
  (sitelinked `image`-class item with image/license/imageAuthor/
  imageLicenseInfo/source statements). Vocabulary: `image` class +
  `image author` (P2093) + `additional license information` (unaligned)
  properties, config `imageClasses`/`imageProperties` + person/FOSS keys.
  MsUpload (production-only, not in dev/CI) coexistence is a deploy-time
  verification item.
- **Entity-page toolbar gadget** (copy embed with absolute URL + language
  selector / copy citation) and entity-combobox autocomplete.
- **Autofill confirm + Special:Update\* pages (autofill-confirm-update
  batch, ADR `docs/decisions/autofill-confirm-update.md`)**: entity-typed
  fields auto-filled from fetched source data (license on Special:Upload /
  Add\* portrait/logo validate; harvested publisher/journal on AddSource;
  harvested developer/license/programming-language on AddSoftware; a
  free-text author NAME in the AddSource search) are matched — exact label
  first, then fuzzy (`EntityLabelMatcher`, pure-PHP scorer: exact → prefix →
  token-containment → Levenshtein, threshold 0.75, over the same
  `EntitySearchHelper` the combobox uses, case-variant queries for the
  instance's case-sensitive term store) — and a good match PREFILLS the
  combobox AND renders a confirmation banner in the field row
  (`entityConfirmHtml` server-side / `uploadmeta.js` client-side;
  `entityconfirm.js` wires the buttons): "{field} fetched from source:
  {value}, we think this corresponds to {label} (Q#)." [Yes, that's right]
  / [No, let me correct] (No clears + focuses the combobox). No good match
  → the plain hint flow. ⚠️ `uploadmeta.js` field targets are HTMLForm
  field NAMES — the lookup is id → inner input → `input[name=…]` (the OOUI
  forms give the `<input>` an auto-generated id and the explicit id lands on
  the widget wrapper; without the name fallback the Add\* validate button
  never rendered and the Special:Upload license autofill silently no-opped).
  New **Special:Update\* pages** (UpdatePerson/UpdateSource/UpdateCollective/
  UpdateSoftware/UpdateFictionalCharacter, URL `Special:UpdatePerson/Q42`):
  each extends its Add\* counterpart and mixes in `UpdateExternalEntityFlow`
  — the exact same review fields prefilled from the item's statements
  (`recordFromItem`, the reverse of `statementSpecs`), submit re-runs the
  Add\* validation then REPLACES the managed statements
  (`baseManagedPropertyIds` ∪ the new specs) + updates the en label/
  description; uploads are opt-in (an existing portrait/logo survives; the
  AddSource access file is kept via a relaxed access validation); a label
  change best-effort-renames the classic page (MovePage + sitelink update).
  The Item page ("Update basic information" button under the title,
  `updatebutton.js` + the config-derived class→Update map in
  `Hooks::onBeforePageDisplay`) links to the Update page for any item whose
  class has one.

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
