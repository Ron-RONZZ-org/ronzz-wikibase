# Decision: Class-first `Special:AddSource`, source child/parent classes, YouTube import, required authors, Sitelink tab

- **Status**: Accepted (Aug 22 2026)
- **Scope**: `wikibase.ronzz.org` — the source-import flow (`Special:AddSource`), the page↔item Sitelink tab, and their vocabulary
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

`Special:AddSource` was class-blind: one search form (DOI/ISBN/title/author) and one review
field set (journal-shaped) regardless of the created class, with the class chosen in a dropdown at
the selection step. Editors requested adapted fields per source kind (songs, films, videos,
YouTube) and a way to model child sources (a book excerpt, a web page of a website, a YouTube
video of a channel) that are semantically linked to their parent. Separately, pages and semantic
entities were coupled only through the item edit form — there was no one-click "link this page to
an item" affordance on the page itself.

## Decision

1. **Class-first flow**: `Special:AddSource` opens on a class picker and routes to
   `Special:AddSource/<classKey>` (search), `…/<classKey>/manual`, `…/<classKey>/<token>` and
   `…/<classKey>/<token>/review/<i>` subpages — one search field set and one review field set per
   class. Classes without an external authority (`website`, `webpage`, `bookExcerpt`) skip the
   search step and go straight to the adapted manual form; `Special:AddSource/website` explains
   the website→webpage parent/child workflow.
2. **Child/parent classes** (`bookExcerpt→book`, `webpage→website`, `youtubeVideo→youtubeChannel`):
   child-class creation requires an existing parent-class item, picked via an entity combobox with
   an "import it yourself" link, validated server-side (exists + instance-of the parent class), and
   automatically linked with one **`part of`** statement (P361-aligned; one property covers both
   cases — no per-relation property zoo).
3. **YouTube import** (YouTube Data API v3): name searches capped at 10 results (the API bills
   100 units per `search.list` call **regardless of count** — the cap is a UX choice, not a cost
   one); URL lookups resolve **exactly** via `videos.list`/`channels.list` (1 unit) with a
   localized "no match for the provided URL" on zero hits. The API key is **server-side only,
   deploy-injected** (instance config map from the environment — never the repo) and **IP-restricted**
   to the production egress IP in Google Cloud (referrer restrictions are for browser keys and
   provide no protection for server-to-server calls). `www.googleapis.com` joined the SSRF
   allowlist. No search-result cache: at this instance's scale distinct queries dominate, the
   within-flow session token already dedupes repeats, and the free tier (~100 searches/day) is not
   the bottleneck — a config flip (`youtubeSearchCacheTtl`) enables memoization if it ever becomes
   one.
4. **Required authors**: every source record carries at least one author **entity** (multi-value
   entity combobox restricted by validation to agent classes: person / organization / group of
   humans), written as `attributed to` statements (P50-aligned, the existing property — no new
   property). This closes the gap where created source items carried no author link at all. The
   search-step free-text author *filter* is unchanged (it is a search aid, not a record fact).
5. **Duration**: input as the standardized string `(HH):MM:SS` (hours optional), stored as integer
   **seconds** in a **`quantity`** datatype property aligned to P2047 — matching Wikidata's own
   datatype for duration (first `quantity` property on the instance; both manifest readers
   whitelisted it).
6. **Sitelink tab**: `SkinTemplateNavigation::Universal` adds a **Sitelink** tab next to
   Page/Discussion on every content page (entity namespaces excluded), blue when the page is
   sitelinked (href → `Special:EntityPage/Qxx`), red when not (href → prefilled
   `Special:NewItem` as the no-JS fallback; the JS module intercepts the click and opens an OOUI
   dialog with a `wbsearchentities` label-search combobox or direct Q-id entry, writing
   `wbsetsitelink(linksite=wikibase, linktitle=<page>)`). One small extension, no Wikibase fork.

## What this does NOT do (and why)

- **No YouTube key in the repo or in the browser**: the key is server-side and IP-restricted;
  dev/CI use the mocked `FakeHttpClient`, never the real key.
- **No free-text authors**: authors are entities, not strings — the review form requires picking
  existing items (or creating them first via `Special:AddPerson`), and validation rejects
  non-agent items. Truly anonymous sources cannot be created; organizational authorship is covered
  by the agent-class filter.
- **`website`/`webpage`/`bookExcerpt` have no external-authority search** — they are
  manual-entry classes by design; a later provider can be slotted into the per-class routing
  without UI changes.
- **The legacy root-URL search contract is gone**: `Special:AddSource?wpdoi=…` (used by
  bookmarked flows and the old E2E) no longer searches directly — the class must be chosen first.
  The E2E suite was updated to the class-first flow.

## Consequences

- Vocabulary additions (data, not code): classes `youtubeChannel` (Q17558136), `youtubeVideo`
  (Q63412991), `webpage` (Q36774), `bookExcerpt` (unaligned — no clean Wikidata class), verified
  live against Wikidata; properties `part of` (P361), `duration` (P2047), `YouTube channel ID`
  (P2397), `YouTube video ID` (P1651), `URL` (P2699).
- Config map: `sourceClasses` + `sourceParents` (child key → parent key), `sourceProperties`,
  deploy-injected `youtubeApiKey` / `youtubeSearchCacheTtl`.
- Extension code stays standalone and upstreamable; all entity ids remain config-driven, never
  hardcoded. The dev-only `composer.json` gained `data-values/number` (where
  `DataValues\QuantityValue` lives — the same package production Wikibase resolves), mirroring
  the production dependency set for the quantity statements.
