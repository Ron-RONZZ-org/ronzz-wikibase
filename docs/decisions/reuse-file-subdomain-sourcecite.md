# Decision: Reuse-file CONTAINS search, subdomain parent inference, Source-page internal citation

- **Status**: Accepted (Sep 2 2026)
- **Scope**: `wikibase.ronzz.org` — the Add\* portrait/logo "reuse an existing
  file" comboboxes, `Special:AddSource/webpage` parent inference, and the
  Source: classic pages
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Three user-experience requests on the Add*/Source surfaces:

1. **Reuse-file combobox misses most files.** The mode=existing "Reuse an
   existing file on this wiki" combobox (`resources/fileselect.js`) autocompleted
   through the wiki's search index (`generator=search`), which matches **whole
   word tokens** only: "european space" finds `European Space Agency-logo.png`,
   but a fragment like "astro" finds *nothing* although
   `Astronomy and Astrophysics-logo.svg` exists (verified live), and a
   mid-name fragment (e.g. part of a timestamp) never matches. Users type what
   they remember of a file name — the combobox could not surface the file.
2. **Webpage parent inference ignores subdomains.** The webpage→website parent
   auto-assign (`SiteRootMatcher::findByHost`) matched the entered URL's host
   by **exact equality only** (after `www.` collapse). A page on
   `scifa.univ-lorraine.fr` never matched the recorded website
   `univ-lorraine.fr` — the parent had to be picked by hand or via the flaky
   site-name fallback, although the relation is deterministic.
3. **Source: pages have no cite affordance.** Editors who want to cite a source
   on a wiki page need the internal snippet `<ref>{{#cite:Q42}}</ref>`; the
   entity-page toolbar offers formatted citations (APA/…) and embed snippets,
   but the human-facing `Source:` page — where an editor lands after creating
   or finding a source — offers nothing to copy.

## Decision

### 1. `action=filesearch` — CONTAINS file-title search for the reuse combobox

New read-only API module `ApiFileSearch` (`action=filesearch&search=…&limit=…`),
the page-table analogue of `action=entitysearch` (which already replaced
`wbsearchentities` for the entity comboboxes for the same reason): a CONTAINS
match (`LIKE %term%`) over `page_title` in the File namespace, redirects
excluded, prefix matches ranked first. Whitespace, `_` and `-` in the typed
search split into tokens that may match any text in between, so a human
fragment finds the stored DB key regardless of the file's separator style
("european space" finds both `European_Space_Agency-logo.png` and
`European-Space-Agency-logo.png`). No case variants are needed (the
`page_title` column collation is case-insensitive — unlike the VARBINARY
`wbt_text` the entity search must vary). `resources/fileselect.js` queries the
module and batch-fetches the 64 px thumbnails with one `imageinfo` call
(latest-wins sequence guard; in-flight requests aborted). Applies to every
Add\*/Update\* portrait/logo combobox (the module is shared).

### 2. Ancestor-domain (subdomain) parent inference

`SiteRootMatcher::findByHost` matches a recorded website whose host is the
page host **or one of its parent domains** (the match goes UP the page host's
own suffix chain: "univ-lorraine.fr" is a parent of "scifa.univ-lorraine.fr");
among matching rows the **longest (most specific) host wins** — the page's own
host when a record exists, else the closest recorded ancestor, covering deep
subdomains and `www2`-style hosts. The reverse direction never matches (a
recorded subdomain is not the parent of its apex page). Everything else is
unchanged: one WDQS query, silent auto-assign (combobox still editable),
exception-safe degradation to the site-name inference.

### 3. Source: pages — "Copy internal citation" toolbar button

`Hooks::onBeforePageDisplay` gains an `NS_SOURCE` branch: a Source-namespace
content page that is sitelinked to an item gets `wbInternalCiteItem` (the
Q-id, resolved server-side through the site-link store — the same store the
parser functions and the Sitelink tab use; a `/fr` translation subpage has no
sitelink and gets nothing) and loads the new `ext.embeddableContent.sourcecite`
module. The module renders ONE button into the shared `.wb-embed-toolbar` row
under the page title (the gadget/update-button row + styles, so the Source:
page action surface matches the Item: page one); clicking copies
`<ref>{{#cite:Q42}}</ref>` to the clipboard with the existing "Copied to
clipboard." notification. New i18n messages in en/fr/eo.

## Consequences

- The reuse combobox finds any file by an arbitrary fragment of its name —
  the failure mode that made "reuse an existing file" unusable is gone. The
  direct `page`-table SQL is the same documented deviation as ApiEntitySearch
  (read-only, in-process; CirrusSearch remains the upgrade path).
- Adding a webpage under a recorded site's subdomain auto-assigns the parent
  exactly like the exact-host case — fewer hand-picked parents, no wrong
  parent assignment to unrelated domains.
- Editors can copy a source's internal citation from its own page; the button
  is server-wired per page (covers pre-existing pages too, no template edits).
- Docs: extensions/AGENTS.md and this ADR; on-wiki deploy notes + the
  `Help:Contributing` source-creation/citation pages.
