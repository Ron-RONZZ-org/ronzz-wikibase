# ronzz-wikibase

Customization project for **wikibase.ronzz.org** — the self-hosted Wikibase
(structured-data wiki) at ronzz.org.

[![CI](https://github.com/Ron-RONZZ-org/ronzz-wikibase/actions/workflows/ci.yml/badge.svg)](https://github.com/Ron-RONZZ-org/ronzz-wikibase/actions/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

The v1 plan lives in
[**GitHub issue #6**](https://github.com/Ron-RONZZ-org/ronzz-wikibase/issues/6)
(fresh-instance bootstrap + EmbeddableContent + WikibaseCitation).

## Status

| Deliverable | Status |
|---|---|
| D1 — vocabulary manifests + import scripts | implemented |
| D2 — seed orchestrator | implemented (`seed/`) |
| D3 — EmbeddableContent end-to-end | implemented |
| D4 — WikibaseCitation (citeproc-php) | implemented |
| D5 — tests & acceptance (unit + E2E + XSS + page flows) | implemented |
| D6 — deployment + docs | **deployed to production Aug 17 2026** (seeded + verified) |
| Issue #7 — external-authority entity creation | implemented + deployed (`Special:AddPerson` / `AddSource` / `AddCollective`, fetch layer, citation type/source overhaul) |
| Issue #11 — special-page visibility + `Special:Embed` 500 | fixed + deployed (`getDescription()`/`setHeaders()`, `showErrorPage()`, regression E2E) |
| Issue #12 — external-search UX | implemented + deployed (search → select → review → create, manual fallback, detailed candidates) |
| Embeds + autocomplete polish (PRs #15–#19) | implemented + deployed (visible toolbar w/ absolute snippet + language selector, bare-fragment embeds, KaTeX math + highlight.js code in a minimal embed skin, entity-combobox autocomplete, `describes`/`implementation of` fields, all-language quotation input, subdued provenance/printfooter) |
| Issue #26 — FOSS software documentation | implemented + deployed (FOSS namespace NS 2008/2009, 9 software properties + `free and open-source software` class Q179, `Template:FOSS`/`FOSS:Main`, `Special:AddSoftware` with Wikidata→GitHub fetch, creates item + `FOSS:` page + sitelink in one flow) |
| Add-flow follow-up — URL-first fetch, content review, per-class pages, fictional characters | implemented (awaiting deployment): website/webpage URL entry with SSRF-guarded metadata autofill, fetched page content (OpenAlex abstract/keywords, Wikipedia intros/Plot/Lyrics, site metadata) reviewed on a dedicated step and written to per-class `Source:`/`Person:` pages, `Special:AddFictionalCharacter`, entity-only Journal, access N/A mode, CC BY-SA data rights, combobox-autocomplete fix — see `docs/decisions/add-flow-followup.md` |
| Upload enhancements — `Special:Upload` + Add\* portrait/logo, Wikimedia 429 fix | implemented (awaiting deployment): browser-side Wikimedia metadata fetch + rate-limited server fetch layer (the `fceb99d` 429 fix), `api.php?action=uploadmeta`, semantic license combobox on `Special:Upload` (replacing the core dropdown), image author + additional-license-info fields, single max-size note, item-per-upload (sitelinked `image`-class items), shared `ImageUploadHelper` for the Add\* portrait/logo sections with the "I will upload a {image}" toggle + validate button + attribution statements — see `docs/decisions/upload-enhancements.md` |
| Diagrams (Extension:Diagrams, vendored) | implemented + CI green (awaiting deployment): `<uml>` (PlantUML) / `<graphviz>` / `<mscgen>` server-side (local binaries → SVG cached in the wiki file store) + `<mermaid>` client-side (bundled mermaid.js), PlantUML pinned jar (1.2026.6) running under the **SANDBOX** security profile, dedicated diagrams E2E — see `docs/decisions/diagrams.md` |
| Autofill confirm + Special:Update\* | implemented (awaiting deployment): entity-typed fields auto-filled from fetched source data are matched (exact label → fuzzy `EntityLabelMatcher`), prefilled AND confirmed with a "we think this corresponds to {label} (Q#)" banner ([Yes, that's right] / [No, let me correct]); the Add\* portrait/logo Validate button + Special:Upload license autofill wiring fixed (id → inner input → `input[name=…]`); new `Special:UpdatePerson` / `UpdateSource` / `UpdateCollective` / `UpdateSoftware` / `UpdateFictionalCharacter` pages re-edit an existing item with the exact same fields as its Add\* page (prefilled, update on submit, uploads preserved, classic page renamed on a label change), reachable via an "Update basic information" button under the Item-page title — see `docs/decisions/autofill-confirm-update.md` |
| Add\* image & place fixes | implemented (awaiting deployment): (1) **statement-driven infobox image** — `{{#item-image:}}` renders the item's `image` statement in `Template:Collective`/`Person`/`FOSS-Infobox` (`{{{logo|{{#item-image:}}}}}`), fixing classic pages whose skeleton predates the logo param (e.g. `Collective:National Geographic Partners`); (2) **Wikimedia URL percent-decoding** — encoded file names (`%28`/`%29`) in thumb URLs now reach the Commons API decoded, ending the "fetch failed: HTTP http-bad-status" fallback; (3) **AddPerson place of birth/death label match** — the harvested Wikidata QID is replaced by the place label, matched against local items with the [Yes]/[No] confirmation banner (no match → "External record: …" hint) — see `docs/decisions/infobox-image-from-statement.md` |
| Add-flow round 3 — official website, webpage parent, label suffix, toolbar | implemented (awaiting deployment): `Special:AddPerson`/`AddCollective` gain the shared **official website** URL field (P856-aligned, same property as AddSoftware; `Template:Person` gains the infobox row); `Special:AddSource/webpage` **auto-infers the parent Website** from the page URL's site root (autofill-confirm banner on a match, "No record found for {root} — add the website first" hint on none); `Special:AddSource` labels carry the **class disambiguation suffix** ("The Hobbit (Book)", "Example Domain (Website)") in the field default AND at creation (idempotent; updates keep the stored label as-is); the Item-page toolbar is **one row** ("Update basic information" + copy embed + copy citation) with a **citation format chooser** (APA/Vancouver/BibTeX/RIS) — see `docs/decisions/addflow-round3.md` |
| Aug 30 batch — duplicate-citation merge | implemented (awaiting deployment): `{{#cite:Q}}` cited several times on a page renders **one footnote per source** (N backlinks) instead of one footnote per use — stock Cite merges only named refs, so the rendered references list is post-processed (`ReferencesMerger` in WikibaseCitation's `ParserAfterTidy` hook, gated to pages that cited entities; named refs and `group=` lists untouched) — see the extensions AGENTS.md |

## Repository layout

- `extensions/EmbeddableContent/` — vocabulary manifests (`manifests/`, incl.
  the issue-#7 ExternalId authority + citation-metadata properties and
  agent/work classes) + `maintenance/importVocabulary.php`; D3 renderer,
  `Special:Embed` (bare-fragment embeds rendered through a minimal `embed`
  skin; vendored KaTeX + highlight.js for math/code), `api.php?action=embed`,
  oEmbed, the content pages (`Special:AddQuotation` / `AddCodeSnippet` /
  `AddMath` — all-language quotation input, `describes`/`implementation of`
  subject fields, redirect-to-created-item), the issue-#7 external-authority
  pages (`Special:AddPerson` / `AddSource` / `AddCollective`, login-gated,
  search → select → review → create, manual fallback, detailed candidates
  incl. descriptions), the issue-#26 FOSS pages (`Special:AddSoftware`,
  login-gated, search → select → review → create, Wikidata + GitHub fetch
  via `SoftwareProvider`, creates the item + `FOSS:` page (Template:FOSS
  skeleton) + page↔item sitelink, manual fallback), the `includes/Fetch/`
  provider layer (Wikidata hub + dblp SPARQL, OpenAlex, Crossref, Open
  Library, ORCID, GitHub — SSRF-allowlisted),
  entity-page toolbar gadget (copy embed with absolute URL + language
  selector / copy citation) and entity-combobox autocomplete. Issue
  follow-up: `AddPerson`/`AddSource`/`AddCollective` create classic
  `Person:`/`Source:`/`Collective:` pages (per-class templates, sitelinked;
  `bookExcerpt` excluded) — see `docs/decisions/pages-and-fields.md`.
  Issue #35 follow-up: entity-only publisher (item-typed property + string
  migration tool), the AddSource `access` field (access URL / direct
  download / local file with license + uploads), manual-addition entry
  points with search autofill, and auto full-name person labels — see
  `docs/decisions/publisher-entity-access-manual.md`. Issue follow-up:
  website/webpage URL-first entry (SSRF-guarded metadata fetch), the
  fetched page-content review step (OpenAlex abstract/keywords, Wikipedia
  intros + Plot/Lyrics, site metadata), content-driven per-class
  `Source:`/`Person:` page skeletons, `Special:AddFictionalCharacter`,
  entity-only Journal, access N/A mode, license-combobox options, and the
  combobox-autocomplete fix — see
  `docs/decisions/add-flow-followup.md`. Upload enhancements: the
  rate-limited fetch layer (`RateLimitedHttpClient` — WMF throttle + 429
  `Retry-After` backoff), the upload metadata fetch (browser-side Wikimedia
  via `origin=*` + the SSRF-guarded `api.php?action=uploadmeta` for other
  hosts, `resources/uploadmeta.js` validate button with preview + 429
  blob fallback), the shared `includes/Upload/` module (`ImageUploadHelper`
  — the Add\* portrait/logo fields + upload path, `ImageItemCreator` +
  `UploadHooks` — Special:Upload's semantic license combobox, attribution
  fields and item-per-upload), and the image vocabulary (`image` class,
  `image author`/`additional license information` properties) — see
  `docs/decisions/upload-enhancements.md`.
- `extensions/WikibaseCitation/` — citation map manifests +
  `maintenance/importCitationMap.php` (publishes the 4 admin-editable
  `MediaWiki:Citation-*` pages); D4 `api.php?action=citation` with
  **citeproc-php** (APA/Vancouver, vendored CSL styles in `styles/`) and native
  BibTeX/RIS serializers. Issue #7: CSL type follows the **source** class;
  harvested source fields (published in, publisher, pages, volume, issue, DOI,
  ISBN). No Node sidecar.
- `extensions/Diagrams/` — vendored third-party diagram extension
  (PlantUML `<uml>` / GraphViz `<graphviz>` / Mscgen `<mscgen>` server-side,
  Mermaid `<mermaid>` client-side; SVG cached in the wiki file store) — see
  `extensions/Diagrams/VENDORED.md` + `docs/decisions/diagrams.md`. The
  server-side renderers are installed via apt (`graphviz`, `mscgen`) and the
  pinned PlantUML jar via `tools/install-plantuml.sh` (SANDBOX profile).
- `seed/` — instance bootstrap orchestrator (`seed_instance.py`), API client,
  dogfood entities, config/report emission, self-verification (idempotent,
  exact-label skip).
- `tools/` — manifest generators + `fetch-smoke.php` (live provider smoke test) + `add-copy-to-syntaxhighlight.py` (enforces the on-wiki standard that every block-mode `<syntaxhighlight>` carries `copy`; `--audit`/`--dry-run`/`--apply`).
- `tests/` — PHPUnit unit tests (pure-PHP logic, no MediaWiki runtime) and the
  `tests/e2e/` suites against a seeded live instance: `run_e2e.py`
  (acceptance + XSS) and `run_pages_e2e.py` (issue-#7 page flows:
  AddPerson/AddSource/AddCollective + the AddQuotation form).
- `dev/` — reference-deployment dev/CI stack (wikibase-docker, WBS).
- `docs/` — instance documentation (stack, endpoints, contribution guide,
  CLI ops, ADR-style decisions).
- Each module directory carries its own `AGENTS.md` with module-specific
  agent instructions (see the table in `AGENTS.md` at the repo root).

## Development

### Unit tests (no MediaWiki — pure-PHP logic only)

```bash
docker build -f Dockerfile.test -t ronzz-wikibase-test .
docker run --rm -v "$PWD":/app -w /app ronzz-wikibase-test vendor/bin/phpunit
python3 -m unittest discover -s seed/tests
```

### CI (recommended for the integration layer)

GitHub Actions runs **two jobs** on every push/PR (public repo → unlimited
minutes; the full stack runs on 16 GB runners, so it never touches your
machine):

- `unit` — PHPUnit + seed tooling (~2–3 min)
- `integration` — real wikibase-docker stack (MW 1.46 + WDQS): D1 importers →
  D2 seed → config-map restart → E2E acceptance → XSS suite → **page-flow E2E**
  (issue-#7 Special pages) → forum E2E → **diagrams E2E**
  (PlantUML/GraphViz/Mscgen/Mermaid tags + SANDBOX probe)

**Recommended loop** (especially on a resource-tight machine): edit → local
unit tests above → push → CI gate. For an on-demand full validation without
pushing, run the workflow manually:
[Actions → CI → Run workflow](https://github.com/Ron-RONZZ-org/ronzz-wikibase/actions)
(`workflow_dispatch`). For interactive stack debugging, see `dev/README.md`
(local stack, ~2.5 GiB RAM).

E2E acceptance + XSS suite (against a seeded instance):

```bash
python3 tests/e2e/run_e2e.py check --base-url https://wikibase.ronzz.org \
    --quote Q5 --code Q6 --math Q7 --instance-of P1 --quotation-class Q1
python3 tests/e2e/run_e2e.py xss --api-url ... --user 'User@bot' --password '***'
python3 tests/e2e/run_pages_e2e.py --base-url https://wikibase.ronzz.org \
    --user SeedBot --password-file seed/.seedbot.pass   # page flows, self-cleaning
python3 tests/e2e/run_diagrams_e2e.py --base-url https://wikibase.ronzz.org \
    --user SeedBot --password-file seed/.seedbot.pass   # diagrams tags + SANDBOX probe + XSS
```

`--instance-of` is **P1** on production (`instance of`; P31 is *subclass of* — the
E2E example previously said P31). Property IDs are opaque and instance-specific:
CI and `dev/README.md` resolve them from `seed/generated/ids.json` instead.

## Documentation

Instance documentation: design decisions and contribution pointers stay
**in this repo** under `docs/`:

- `docs/README.md` — public-safe pointer (official links, ADR pointers)
- `docs/contribution-guide.md` — pointer: editing rules live on-wiki at the
  `Help:Contributing` family (so non-coders find them)
- `docs/wikibase-cli.md` — pointer stub (full CLI ops moved to the gated wiki)
- `docs/decisions/` — ADR-style decisions (`opaque-id.md`, `ontology-alignment.md`,
  `raw-rdf-in-blazegraph.md`, `cite-by-qid.md`, `static-llm-translation.md`)

**Instance deployment details (stack, endpoints, access control, uploads,
CLI ops) live on the gated wiki** at `RonzzIT:Wikibase`,
`RonzzIT:Wikibase/Reference`, `RonzzIT:Wikibase/CLI` (wikibase.ronzz.org,
`it` group) — moved out of this public repo on 2026-08-20. Private
ops/credentials (server config, OCI identity, `.env` paths) stay there too.

## Conventions

- MediaWiki extension conventions: `extension.json`, i18n, PHPUnit +
  MediaWiki integration tests; **GPL-2.0-or-later** for extensions.
- Commit with [Conventional Commits](https://www.conventionalcommits.org/).
- See `AGENTS.md` in this repo for agent guidance.

## License

[GPL-2.0-or-later](LICENSE) — the custom extensions and tooling. Wikibase
itself and the Wikimedia software it runs on keep their own licenses.
