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

- **Entity-mode API modules (the MCP contract, `includes/Flow/` + `includes/Api/`)**: the
  Add* flows are also exposed as write API modules for machine clients (bot sessions,
  the MCP embeddable tools): `action=addsource` (citation sources), `action=addspecialcontent`
  (quotation/math/code-snippet), `action=addsemanticentity` (person/software/collective/
  fictional-character/other) — each with a read-only `-fields` discovery sibling
  (`action=addsource-fields`, …) reporting the accepted fields, required-on-create rules
  and resolved property ids. The field contracts live in `Flow/SourceFieldMap`,
  `Flow/SpecialContentFieldMap`, `Flow/SemanticEntityFieldMap` (one publisher per flow —
  the MCP tools and the discovery endpoints read them, so the "webpage rejects authors
  yet demands one" drift of 2026-08-30 cannot recur); the pipelines live in the
  `Flow/*FlowService` classes (pure PHP, unit-tested): validation (field whitelist,
  agent-class authors, parent exists + right class, date/URL/duration formats),
  book-excerpt year/authors/description inference, payload handling (math delimiter
  stripping, backslash-escaping), label derivation (person given/family,
  fictional-character suffix), collectiveClass preset resolution, no-clobber update
  (`qid`), and classic-page + sitelink creation via `Flow/ClassicPageCreator`. The
  browser forms keep their own field vocabulary and browser-only steps (external-authority
  search, URL-first metadata fetch, content review, image/file uploads); delegating the
  forms' statement building to the services is a documented follow-up.
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
- **FOSS:/Software: page split (ADR `docs/decisions/foss-software-page-split.md`)**:
  the classic page for a software item is a **FOSS:** page when its license
  qualifies as free/open-source, a **Software:** page otherwise — the
  review/manual form carries a **Page kind radio** (defaulting from the
  license: ANY chosen license classified `instance of` the new
  `free software license` class → FOSS:, no license keeps the historical
  FOSS: default). The preseed license rows gain a **`foss` flag** (the
  FSF/OSI line — everything except the CC BY-NC*/ND* variants) and the seed
  classifies `foss`-flagged licenses `instance of` BOTH `software license`
  (the license-combobox class) and `free software license` (re-classifying
  existing items idempotently); the seed emits `fossLicenseClasses`
  (`EmbeddableContentConfig::fossLicenseClasses()`). The base-class page
  machinery reads record-aware `pageNamespaceForRecord()` /
  `pageTemplateForRecord()` (default = the old `pageNamespace()` /
  `pageTemplate()` contract), so create, update-heal and update-rename all
  honor the per-record kind; `Software:` (NS 2016/2017) mirrors the FOSS
  namespace in dev config + production LocalSettings, `Template:Software`
  mirrors `Template:FOSS`. `Special:UpdateSoftware` recomputes the kind
  from the updated license and **moves** the page across namespaces on a
  flip (`renameClassicPage` derives the old title from the sitelink);
  `action=addsemanticentity` kind=software accepts an optional `pageKind`
  (default = license) via the shared `SoftwarePageKind` helper;
  `tools/backfill_classic_pages.py` accepts `{"software": true}` ns-map
  entries resolved per item (`--license-property` +
  `--software-license-ids`). **The item CLASS follows the page kind**: a
  FOSS: page writes `instance of` → `free and open-source software`, a
  Software: page writes `instance of` → the new plain **`software`** class
  (`softwareClasses` config, Q7397-aligned) — a non-FOSS item never carries
  the FOSS class. The class is written by `SemanticEntityFlowService` from
  the record's `pageKind` (a flow field in `SemanticEntityFieldMap`, not a
  statement), resolved by the form's `beforeCreate` / the API module before
  statement building.
- **Classic pages for Person/Source/Collective (issue follow-up, ADR
  `docs/decisions/pages-and-fields.md`)**: the AddSoftware page machinery
  (afterCreate → sitelink → `complete/<id>` finalize) moved into the base
  class; `Special:AddPerson` creates a `Person:` page (Template:Person),
  `Special:AddCollective` a `Collective:` page (Template:Collective),
  `Special:AddSource` a `Source:` page with a per-class template
  (Book/ScholarlyArticle/Website/Song/Film/Video/YouTubeChannel/
  YouTubeVideo/Webpage). **bookExcerpt creates NO page** (part of a book).
  Namespaces Person (2010/2011), Source (2012/2013), Collective (2014/2015),
  Software (2016/2017 — the non-FOSS software pages, see the FOSS:/Software:
  split bullet) in dev config + production LocalSettings.
- **AddPerson lifecycle fields**: VIAF/ISNI search (Wikidata-hub-only),
  day-precision date of birth/death + a "This person is deceased" toggle
  revealing the death fields; `personProperties` config section (P569/P19/
  P570/P20-aligned + the OSM external-id keys). The label field is gone
  (issue #35): the label is the full name, auto-derived from given/family
  (`primaryLabel`); a harvested label-only candidate keeps its label. The
  search `name` box autofills the manual form as given/family (every word
  except the last = given, last word = family — pure `NameSplitter`).
- **AddPerson places live in OpenStreetMap (osm-places, ADR
  `docs/decisions/osm-places.md`)**: the place-of-birth/death fields are OOUI
  comboboxes (cssclass `wb-osm-combobox`) wired by `resources/osmsuggest.js`
  to the **Nominatim search API browser-first** (CORS-open, per Nominatim's
  client-side guidance; the server never proxies search-as-you-type), picking
  a suggestion fills the field with the canonical **`node|way|relation/<id>`**
  value (the formatter URL `https://www.openstreetmap.org/$1` dereferences
  it). The statements write the NEW external-id properties `place of birth
  (OSM)` / `place of death (OSM)` (manifest + `personProperties` config keys
  `placeOfBirthOsm`/`placeOfDeathOsm`); the item-typed P19/P20 properties
  stay in the vocabulary but the forms no longer write them (production had 0
  statements — no migration). **Harvested Wikidata place LABELS are
  auto-matched on Nominatim at harvest-on-pick** (`NominatimProvider`, fixed
  host allowlist + the shared rate limiter at Nominatim's 1 req/s minimum,
  invoked from `SpecialAddPerson::harvestContent`): a top match prefills the
  field AND renders the fetch-match-confirm [Yes, that's right] / [No, let me
  correct] banner (the portrait-license pattern); no match → the "External
  record: {place} — search OpenStreetMap to confirm" hint (`-osm-hint`
  messages); a stored id (the Update flow) prefills unchanged, no banner.
  Server-side `OsmPlace::isValidId` gates the submitted value — a raw place
  NAME is a form error in `beforeCreate`, never a silent drop.
  `Template:Person` renders the OSM rows (`{{#statements:place of birth
  (OSM)}}` / `…(OSM)}}`) as openstreetmap.org links, matching the
  VIAF/ORCID/ISNI row pattern.
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
- **Fulltext combobox search (ADR `docs/decisions/foss-software-page-split.md`)**:
  the entity comboboxes (Add\* pages, Special:Upload, the sitelink-tab
  dialog) now query the extension's **`action=entitysearch`** module, not
  `wbsearchentities` — the instance's term store (no CirrusSearch) only
  matches exact/prefix, so "AGPL" could never find "GNU AGPL-3.0" and
  "Einstein" never "Albert Einstein". The module runs a CONTAINS match
  (`LIKE %term%`) over the same `wbt_*` term tables Wikibase's
  `DatabaseMatchingTermsLookup` reads (label + alias, term-type ids
  hardcoded per upstream `TermTypeIds`), querying the raw + title-cased +
  uppercase variants (case-sensitive VARBINARY `wbx_text`), merging deduped
  hits, and resolving display labels/descriptions with the configured
  fallback order; result shape mirrors `wbsearchentities`
  (`search[].id/label/description`). The direct `wbt_*` SQL is a documented
  deviation from the stable-API rule (read-only, in-process, schema stable
  across 1.4x); CirrusSearch + WikibaseCirrusSearch (real token fulltext,
  needs Elasticsearch ~1–1.5 GB) is the documented upgrade path for a
  larger instance.
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
  Add\* validation then replaces the managed statements **for which the form
  provides a NEW non-empty value** (no-clobber: a blank managed field keeps
  the existing statement — removal is an explicit item-page edit; the old
  unconditional `baseManagedPropertyIds` removal set is gone) + updates the
  en label/description (a blank description keeps the existing one); uploads
  are opt-in (an existing portrait/logo survives; the AddSource access file
  is kept via a relaxed access validation); a label change
  best-effort-renames the classic page (MovePage + sitelink update). The
  Update\* include toggles say the image is REPLACED ("I will upload a NEW
  portrait/logo image … (replacing existing)" — per-kind message keys via
  `portraitIncludeMsgKey()`/`logoIncludeMsgKey()` hooks on the Add\* classes).
  The Item page ("Update basic information" button under the title,
  `updatebutton.js` + the config-derived class→Update map in
  `Hooks::onBeforePageDisplay`) links to the Update page for any item whose
  class has one.

- **Upload UX fixes (upload-ux-fixes ADR, todo.md batch)**: (a)
  `uploadmeta.js` validate is latest-wins — a per-URL-field generation
  counter (`validateSeq`) discards stale responses (double-clicking
  Validate no longer lets the first fetch overwrite the second) and the
  license-confirmation banner is deduped by `data-field` (only the LATEST
  dialog shows); the `licenseInfo` "only when empty" guard is gone — the
  latest fetch overwrites the auto-filled fields. (b) `SpecialAddCollective`/
  `SpecialAddPerson` page skeletons pass `|logo=`/`|portrait=` to their
  templates, so the uploaded image renders in the classic-page infobox
  (  requires the on-wiki Template:Collective/Person image cell — deploy
  checklist item). (c) The portrait/logo **mode radio is file | url |
  existing with NO default** — a leading "Choose a source…" placeholder
  option carries the `''` value (OOUI resets an unmatched empty value to
  the FIRST option, so the placeholder is what keeps the group visibly
  unselected) — the user picks the source themselves (the
  file/url/existing inputs stay hidden until then); `existing` is a **File:
  search combobox** (`existingField`, `resources/fileselect.js`:
  `action=query&generator=search&gsrnamespace=6` + `iiurlwidth=64`
  thumbnails, 220 px preview on selection) whose `File:<name>` value is
  validated server-side (`reuseExistingFile`) — no upload, the
  image/license statements + infobox param work exactly as for an upload.
  (d) The manual-form legend is context-aware: "Item details" when prefilled
  from fetched URL metadata (the website/webpage URL-first flow), "Create
  the item manually" otherwise. (e) `Special:Upload`'s source radio defaults
  to **Url** on a fresh load (`onUploadFormSourceDescriptors` flips the
  checked flag when no `wpSourceType` is posted).

- **Semantic-first image facts + Add\* upload fixes (image-facts-semantics
  batch, issue report)**: the logo/portrait **license, author and
  additional-license-info now attach to the FILE, never to the entity using
  the image**. A NEW Add\* upload (file/url mode) creates the sitelinked
  `image`-class item via `ImageItemCreator` (the Special:Upload
  item-per-upload service — `handleUpload` gained a `$config` parameter)
  holding those facts + the file description page text carries the
  `== License ==` (`[[Q42|label]]`, never a `{{Q42}}` call) and
  `== Attribution ==` blocks (`pageText()`, mirroring `UploadHooks`); the
  consumer `statementSpecs` (AddCollective/AddPerson/AddSoftware) write ONLY
  the `image` statement. **Reuse-existing mode** (mode=existing) hides the
  license/author/license-info fields (their hide-if gained
  `Mode !== existing`) and needs no license — the reused file already
  carries its facts; the consumer still gets just the `image` statement.
  Destination file names are **whitespace→dash normalized**
  (`destName()`/`accessDestName()`: `\s+` → `-`, so "European Space Agency"
  uploads as `File:European-Space-Agency-logo.png`), matching the
  Special:Upload `normalizeDestName` convention. The Add\* uploads' file
  page now records the pasted URL as the Source when the browser-blob path
  carried none. `Special:AddSource`'s **access file survives the content
  review step**: `validateAccessField` re-runs `beforeCreate` on
  `/review/<i>/content` without the browser file — a stored `fileTitle`
  (uploaded at the record-review step) is kept instead of erroring
  ("Select a file to upload." regression), on the review AND manual paths.
  The upload validate **probe reads dimensions per format**
  (`UploadMetadataFetcher::readImageDimensions`): `getimagesize()` first,
  then SVG width/height/viewBox (PHP has no SVG support — the reported
  "could not read the image dimensions (the probe is capped at 131072
  bytes)" logo-URL warning), JPEG SOF-marker scan (a huge EXIF APP1 pushes
  SOF past the cap), PNG IHDR / GIF / BMP / WebP header parses on the capped
  body; the warning names the cap only when the transfer was truncated.
  New **`intergovernmental organization`** agent class
  (`classes.csv` Q245398 + `AGENT_CLASS_KINDS` + `agentClasses()` keys) on
  `Special:AddCollective` (UN/WHO-type collectives).

- **Statement-driven infobox image cell (`{{#item-image:}}`, ADR
  `docs/decisions/infobox-image-from-statement.md`)**: the classic-page
  logo/portrait is now rendered from the item's `image` statement, not only
  from the creation-time page param — `Template:Collective`/`Person`/
  `FOSS-Infobox` cells are `{{{logo|{{#item-image:}}}}}` /
  `{{{portrait|{{#item-image:}}}}}` (param wins when hand-set, statement
  fallback otherwise), fixing pages created before the param existed (e.g.
  `Collective:National Geographic Partners`, item Q880) without a backfill.
  The parser function resolves the page's sitelinked item (or an explicit
  `{{#item-image:Q42}}`), reads the `image` statement URL (union of the
  configured image property ids across the person/collective/foss/image
  config sections), renders `[[File:<title>|frameless|220px]]` and registers
  the item as a parser-cache dependency — the `{{#source-access:}}` pattern.
  The URL→title extraction percent-decodes the path segment. E2E: a scratch
  page transcluding `{{#item-image:<qid>}}` must render the uploaded file
  (the CI stack has no page templates, hence the explicit-id argument).

- **Wikimedia blob fallback fixes on the Add\* pages (follow-up, same ADR)**: the
  shared `uploadmeta.js` submit-time blob fallback had a chain of latent bugs
  that   only manifest on the OOUI Add\* forms (Special:Upload's php-mode form
  was unaffected) — "Logo upload failed: unreachable or unsupported URL" on
  AddCollective with a Wikimedia URL. Fixed: (1) the submit handler resolved
  the URL field at WIRING time, but the OOUI hide-if removes/re-inserts the
  collapsed fields, so the closure read a detached input — resolve from the
  CURRENT DOM at submit; (2) the OOUI radio groups strip the name from the
  visible radios and carry the value in the widget's hidden value input
  (`:checked` never matched, `modeVal` was always empty) — fall back to the
  non-radio value input, and set it to `file` in the converted resubmit (the
  form submits the hidden input, not the visible radios); (3) the file input
  is removed from the DOM while url mode is active — switch the mode first
  and wait for the input to reappear; (4) SERVER case mismatch: the server
  read the upload as `'wp' . ucfirst($prefix) . 'File'` (`wpLogoFile`) but
  the forms submit `wp+key` as-is (`wplogoFile`) — every real-browser Add\*
  file upload was silently dropped (the E2E masked it by posting the
  uppercase names); aligned server + E2E to the rendered names; (5) the
  browser's Accept header makes Wikimedia serve WEBP at `.png` thumbnail
  URLs — name the blob file after its actual MIME extension (and reject an
  HTML error page served with HTTP 200 before filling the upload).
- **Wikimedia file-title percent-decoding (fix)**: the extracted Commons
  file title was never percent-decoded — a thumb URL like
   `…/Magnus-manske-2024_%28cropped%29.jpg/250px-….jpg` yielded the literal
   `%28` title, which the Commons `imageinfo` query cannot match; the browser
   metadata path then fell back to the server-side probe and drew Wikimedia's
   server-IP 429/403 ("image metadata warning: fetch failed: HTTP
   http-bad-status"). `WikimediaFileUrl::fileTitle()` and the JS
   `extractFileTitle()` mirror now decode each extracted path segment
   (`rawurldecode` / `decodeURIComponent`, `+` stays literal — path segments
   encode spaces as `%20`) in every branch (`/wiki/File:`, `Special:FilePath`,
   upload.wikimedia.org original + thumb).
- **Add-flow round 3 (ADR `docs/decisions/addflow-round3.md`)**: (a)
  **Official website on AddPerson/AddCollective** — the shared P856-aligned
  `official website` URL property joins the `personProperties`/
  `collectiveProperties` config maps (one property, three entity kinds —
  AddSoftware already had it); the shared `websiteFieldSpec()`/
  `websiteStatementValue()` helpers (base class) feed a plain URL field on
  all three review forms, written as a validated string statement;
  `UpdatePerson`/`UpdateCollective` prefill it from the item. (b)
  **Webpage→website parent inference** — the `webpage` URL-entry submit
  fetches the site root's metadata, resolves the site name against
  website-class items (exact→fuzzy autofill-confirm; the exact branch is
  class-rechecked and falls through to the class-filtered fuzzy matcher —
  stale term-store hits for deleted items are skipped), prefills the parent
  combobox with the confirmation banner, or stores the "No record found for
  {root} — add the website first" hint (the site is real, our record isn't);
  `parentFieldSpec` renders banner/hint, `part of` + `validateParent`
  unchanged. **(follow-up: normalized-host auto-assign)** — the root URL's
  normalized host is matched FIRST against website items' URL statements via
  one WDQS query (`sparqlUrl` config key, seed-emitted with
  `--config-sparql-url` for the dev/CI container; entity prefix derived from
  `$wgServer . '/entity/'` like Wikibase's default `entitySources`); an exact
  host match **auto-assigns the parent silently** (no [Yes/No] banner — the
  combobox stays editable, `part of` + `validateParent` unchanged). The
  site-name inference above remains the fallback when WDQS is unavailable or
  stale (a website created minutes ago). `SiteRootMatcher` (pure, unit-tested)
  normalizes hosts (lowercase, trailing dot, `www.` collapse) and matches
  SPARQL rows; the whole host-match path is exception-safe — any failure
  degrades, never 500s the URL-entry flow. (c) **Source-label class
  disambiguation** — the review/manual
  title default AND `primaryLabel()` carry ` ({English class label})`
  ("The Hobbit (Book)", "Example Domain (Website)") — idempotent
  (case-insensitive ends-with), English because labels are `en` terms;
  `Special:UpdateSource` overrides `applyLabelSuffix()=false` so updates
  keep the stored label as-is. (d) **Item toolbar one row + citation format
  chooser** — `updatebutton.js` and `gadget.js` share one
  `.wb-embed-toolbar` flex row (create-or-reuse `getToolbar()`, update
  button prepends); copy citation gains an APA (default)/Vancouver/BibTeX/
  RIS selector (`wb-embed-toolbar-style`), fetched lazily and cached per
  format (the APA probe doubles as the first text).
- **Content-page "Add more" + label prefill (ADR
  `docs/decisions/add-more-and-access-collapse.md`)**: `Special:AddQuotation`/
  `AddCodeSnippet`/`AddMath` prefill the `label` field with the parenthetical
  class marker (`(quotation)` / `(code snippet)` / `(math snippet)` — the
  AddSource label convention) and gain a second **"Add more"** submit button
  (`submitbutton` field `addMore`, distinguishable via the `wpaddmore`
  request value): the submit creates the item, then redirects back to the
  page with `?addmore=1` and the submitted provenance fields as query params
  (attributedTo/source/sourceUrl/date/language/lexer/describes/
  implementationOf) — **label resets to the default prefill, payload to
  empty** (`carryOverParams()` gates the prefill to the addmore return
  trip). The content-page submit is now **login-gated** (the write surface;
  page loads stay open).
- **Label sanitization for page titles (fix, same ADR — the Q1232 bug)**:
  harvested titles carry HTML markup (OpenAlex `<i>…</i>` italics), which was
  stored verbatim in the item label and made the classic-page title invalid
  (`Title::isValid()` rejects `< >`) — `afterCreate()` silently fell back to
  the item redirect (item Q1232 "Planck 2018 results (Scholarly article)"
  has statements but no `Source:` page). New pure `LabelSanitizer::stripMarkup`
  (decode entities → strip tags → collapse whitespace, unit-tested) is
  applied in `SpecialAddSource::disambiguatedTitle()` and — defense-in-depth —
  in `pageTitleForRecord()`; `afterCreate()` now renders a **warning + link
  to the item** instead of a silent redirect when the page namespace exists
  but the title is unusable. The afterCreate sitelink/page block was
  refactored into `linkPageToItem()` / `createClassicPage()` (no behavior
  change).
- **Update* missing-page heal (fix, same ADR)**: after a successful update,
  when the kind declares a page namespace, the item has no wikibase sitelink
  and the label is a usable title, the classic page is created (sitelink
  first + marked page, the `afterCreate` pattern) and the flow routes through
  the `complete/<id>` finalize (`executeComplete` routing added to the
  Update `execute()`). Heals Q1232-class damage through the normal update
  flow; `tools/backfill_classic_pages.py` heals the already-orphaned items
  (stdlib, `--ns-map` class → namespace/template, `wbsetsitelink` first so
  the separate page-creation request sets `wikibase_item` immediately).
- **UpdateSource access collapse (UX, same ADR)**: the access section
  (`accessMode` radio + accessUrl/downloadUrl/accessFile/license) is hidden
  behind an **"I will update the resource access instructions"** checkbox
  (`accessInclude`, default unchecked — the `ImageUploadHelper::includeField`
  hide-if pattern; `accessFieldSpec` is now protected so `SpecialUpdateSource`
  can gate the parent's fields). An unchecked submit **neutralizes the
  access record keys** in `beforeCreate`, so `statementSpecs` writes nothing
  and `applyUpdate` removes nothing — the item's access statements survive
  byte-identical (no re-upload, no GUID churn). The Add* flow keeps the
  section always visible.
- **Statement GUIDs on flow-created items (fix, 2026-08-31)**: the flow
  services (`SemanticEntityFlowService`/`SourceFlowService`/
  `SpecialContentFlowService`) built statements via `addNewStatement()`
  WITHOUT a GUID, bypassing Wikibase's ChangeOps (which auto-assign GUIDs in
  `ChangeOpStatement::apply`). The entity-page client matches server-rendered
  statement DOM to the entity JSON **BY GUID**
  (`ViewFactory.getStatementGroupListView` → `getStatementForGuid`), so a
  GUID-less statement never matched and rendered as an **EMPTY edit-mode row
  for logged-in users** — "item page in edit mode with content gone"
  (anon users get read-mode rendering, hence the anon-OK/logged-in-broken
  split; every flow-created item since the delegation refactor was affected,
  e.g. Q1402/Q1428 `claim id = None`). Fix: `Flow/StatementGuidAssigner`
  (pure, idempotent) + **create paths save twice** — the first save assigns
  the item id, GUIDs are generated from it (`GuidGenerator::newGuid`), the
  second persists them (the `ImageItemCreator` pattern) — and `applyUpdate`
  assigns GUIDs inline (item id known). `maintenance/assignStatementGuids.php`
  backfills existing GUID-less claims (idempotent, `--dry-run`/`--verify`).
  E2E regression net: every flow-created item's claims must carry ids
  (`assert_claim_ids` in `run_pages_e2e.py`).
- **Duplication guard on the Add* flows (ADR
  `docs/decisions/duplicate-guard.md`)**: an existing item carrying an
  identical authority external id (Wikidata/OpenAlex/ORCID/DOI/ISBN/VIAF/
  ISNI/YouTube) or web URL (official website/source repo/docs URL/access
  URL), or a highly similar (≥ 0.75, class-filtered) label, triggers the
  confirm panel "We think this item may be a duplicate of
  [[Item:Qxxx|label]]" — [Yes, that's right] → the existing item page; [No]
  → the flow continues and the create gate **force-creates** (the previous
  silent exact-label reuse in `createOrSkipItem`/`createViaSemanticFlow`/
  `createViaFlow` is bypassed). Trigger points, earliest-first: the
  **search-pick** step (a picked record whose authority id exists →
  `/<token>/duplicate/<index>/<Qid>`), the **URL-entry** step (website/
  webpage/YouTube — an existing URL warns inline with an acknowledge
  checkbox), and the **create gate** (every review/manual/content submit
  re-checks the final record). The API modules (`addsemanticentity`/
  `addsource`/`addspecialcontent`) return `{ duplicate: 1, duplicateOf,
  duplicateLabel, match }` instead of creating; `confirmDuplicate=1`
  force-creates. `Spec/DuplicateFinder` (pure — one `VALUES (?p ?v)` WDQS
  query + `EntityLabelMatcher`), `Spec/DuplicateGuard` (record → pairs,
  shared by forms + API), `Spec/DuplicateChecker` (MW facade, `sparqlUrl`).
  **Exception-safe**: a WDQS/term-store failure yields no warning, creation
  never blocks. The create-anyway POST is CSRF-gated; warning labels pass
  `LabelSanitizer::stripMarkup`. No vocabulary/config-map change.

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
- **Duplicate-footnote merge (fix)**: stock Cite merges only *name*-attributed
  refs — an unnamed `<ref>{{#cite:Q985}}</ref>` used several times on a page
  rendered one footnote per use (the classic Manske page: 5 identical
  footnotes for the same source). `ReferencesMerger::mergeDuplicateFootnotes`
  post-processes the rendered references list in the existing `ParserAfterTidy`
  hook (which runs after Cite's `ParserAfterParse` auto-append): footnotes with
  identical reference text collapse into the first occurrence, the merged
  in-text superscripts are re-pointed at the surviving footnote (relabelled
  like Cite's named-ref UX — every anchor stays valid both ways), and the
  surviving footnote gains one ↑ backlink per merged usage. Gated to pages that
  cited entities (extension data non-empty), so plain pages keep stock Cite
  behavior; only the default group's numeric-id footnotes merge (named refs and
  `group=` lists are untouched).
- **Collective authors render as family-only names (fix, ADR
  `docs/decisions/foss-software-page-split.md`)**: an item-typed author whose
  `instance of` classes do NOT include the person class (a collective /
  organization — Wikimedia Foundation, a band, an institution) renders as a
  FAMILY-ONLY CSL name, never a split given/family ("Foundation, W."). ⚠️ The
  CSL `literal` field is dropped by the in-process citeproc-php processor
  (v2.7.1 — verified: a literal author renders NO author), so the family form
  is the rendering-correct one (all five styles). The
  person class id comes from the new `WikibaseCitationPersonClass` config
  (fallback: `EmbeddableContentConfig['agentClasses']['person']`); an author
  item with NO class statements keeps the legacy person-name split (no signal
  to contradict it); string-valued authors keep the legacy split (no class
  information); the single-word string fallback is family-only too (a literal
  would render an empty author).

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
