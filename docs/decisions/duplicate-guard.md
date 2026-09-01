# Decision: Duplication guard on the Add* creation flows

- **Status**: Accepted (Aug 31 2026)
- **Scope**: `wikibase.ronzz.org` — all `Special:Add*` entity-creation flows
  (AddPerson/AddSource/AddSoftware/AddCollective/AddFictionalCharacter and
  the content pages), the entity-mode API modules (`addsemanticentity`,
  `addsource`, `addspecialcontent`)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The Add* flows already **silently deduplicated by exact label**:
`createOrSkipItem` / `createViaSemanticFlow` / `createViaFlow` reused the
existing item when the English label matched case-insensitively, with zero
explanation to the contributor. Identical **authority identifiers**
(Wikidata/OpenAlex/ORCID ids, URLs) were not checked at all — a second item
with the same ORCID but a differently-worded label slipped through. The
request: warn the contributor **as soon as a duplicate is recognizable**,
let them confirm, and never create a silent duplicate.

## Decision

### 1. The signals (an existing item is a "duplicate" when)

- **Identical external id** — the record's authority identifiers (Wikidata
  Q-id, OpenAlex work/author id, ORCID, DOI, ISBN, VIAF, ISNI, YouTube
  channel/video ids) match an existing item's statement for the same
  property, OR
- **Identical web URL** — official website / source repository /
  documentation URL / access URL, OR
- **Highly similar label** — `EntityLabelMatcher` score ≥ 0.75 (exact →
  prefix → token-containment → Levenshtein) against the flow's own class
  set.

### 2. The interaction (inline warning panels at the earliest trigger)

"We think this item may be a duplicate of [[Item:Qxxx|{label}]]" with the
existing fetch-match-confirm vocabulary:

- **[Yes, that's right]** → redirect straight to the existing item page (no
  creation).
- **[No]** → the creation flow continues; the create gate **force-creates**
  (the silent exact-label reuse is bypassed — the contributor's explicit
  "no" wins, even for an identical label; Wikibase labels need not be
  unique).

Trigger points, earliest-first:

| Trigger | When |
|---|---|
| Search flow | The **search-pick** step — an enriched record whose authority id already exists → `/duplicate/<index>/<Qid>` confirm |
| URL-first flow (website/webpage, YouTube) | The **URL-entry** step — an existing URL → inline warning + acknowledge checkbox on the URL page |
| Manual / review / content submits | The **create gate** — every final submit re-checks the record (ids + URLs + fuzzy label) |
| API / MCP modules | Return `{ duplicate: 1, duplicateOf, duplicateLabel, match }`, **no create**; `confirmDuplicate=1` force-creates |

### 3. Implementation notes

- `Spec/DuplicateFinder` (pure, unit-tested): one `VALUES (?p ?v)` SPARQL
  query over the exact pairs + the shared `EntityLabelMatcher` fuzzy label.
- `Spec/DuplicateGuard` (pure): record → (property, value) pair assembly
  shared by the forms AND the API modules — the guard cannot drift between
  the two surfaces.
- `Spec/DuplicateChecker`: thin MediaWiki facade (`sparqlUrl` config key +
  the `wgServer`-derived `wd:`/`wdt:` prefixes — the `SiteRootMatcher`
  pattern).
- **Exception-safe by contract**: an unreachable WDQS or an unseeded term
  store yields NO duplicate — the guard never blocks creation (it is an
  enhancement, not a gate).
- The confirm page's create-anyway POST is CSRF-gated (raw form, not
  HTMLForm). Warning labels pass through `LabelSanitizer::stripMarkup`
  (they ride a parsed message as wikilink display text).

## Alternatives considered

- **Client-side OOUI modal** (a literal "pop up"): rejected for the first
  iteration — the server-rendered inline panels are HTTP-E2E-testable (the
  repo's page-flow convention), work without JS, and can be re-skinned as a
  modal later without changing the backend.
- **Silent reuse with a notice**: rejected — the whole point is the
  explicit contributor decision, and the previous silent reuse was the
  problem.

## Consequences

- Contributors get one (or two: pick + create-gate) deliberate confirmations
  before a duplicate lands; identical authority ids can no longer slip in
  through a differently-worded label.
- Creation does one best-effort WDQS query (10 s timeout, exception-safe) —
  the parent-inference precedent; a hung WDQS delays creation by at most
  the timeout, never fails it.
- API/MCP clients must handle the `duplicate` response or pass
  `confirmDuplicate=1` — a documented contract change (the MCP tools'
  behavior is unchanged until they opt in).
