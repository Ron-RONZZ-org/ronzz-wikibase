# Decision: mention capitalisation, automatic token retry, Add* relevance, proprietary license

- **Status**: Accepted (2026-11-05)
- **Scope**: `wikibase.ronzz.org` — `resources/{mentioncontext,uploadform,editsessionretry,softwarelicense}.js`,
  `includes/{Hooks,Fetch/RecordRanker,Fetch/ProviderClient,Flow/SoftwarePageKind,Spec/{MentionSnippet,SameOrigin,SpecialAddSoftware}}.php`,
  `manifests/preseed.csv`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Four independent reports shared one theme — a silent default decided an
outcome the contributor could not see or correct:

1. `Copy internal mention` lowercases the Main-namespace first letter
   (`[[classical mechanics]]`), so using it at the start of a sentence needs
   a manual capitalisation.
2. A stale CSRF token makes `Special:Upload` refresh the token and stop at
   “please try again”; a stale edit token makes core force a preview
   (`session_fail_preview`) and the edit is not saved.
3. Add\* merged the provider results in a fixed provider order and only then
   applied the 10-result cap. For “On the psychology of prediction”, Open
   Library returned four irrelevant books ahead of OpenAlex’s exact title.
4. `SoftwarePageKind::defaultFor()` treated a missing/unresolved license as
   FOSS. `Special:AddSoftware` created Q2721 as a `FOSS:` page with no
   license; there was also no “Proprietary” license item to choose.

## Decision

### 1. Right-click “Capitalise first letter”

`Spec/MentionSnippet` gains `capitalizePrefixedTitle()` and
`capitalizedFromPrefixedTitle()`: the page name’s first letter is
uppercased (after the namespace prefix in a non-Main namespace), leaving
the namespace and the rest of the title untouched. `Hooks` emits
`wbReferenceSnippetCapitalized` / `wbMentionLinkCapitalized`.

`resources/mentioncontext.js` attaches a `contextmenu` handler to every
**Copy internal mention** button (page-level content toolbar, per-section
control, classic/Item-page button). A normal click copies the existing
snippet; a right-click opens a small menu with **Copy internal mention** and
**Capitalise first letter**. The caller supplies the copy function so each
surface keeps its existing clipboard fallback/notification. The menu uses
the instance theme tokens and is appended to `body` (a fixed-position menu
cannot be clipped by the heading).

### 2. Upload auto-retry

`resources/uploadform.js` already refreshed a stale upload token. It now
retries the **same** same-origin fetch automatically once after the
refresh. On a second token failure it refreshes once more and shows the
existing “file is still selected” message, so a genuine session loss does
not loop. A fresh user-initiated submit resets the one-retry guard.

### 3. Edit auto-retry (same-origin guarded)

Core’s forced preview is a CSRF defense: an off-site form POST must not
become a save. The automatic retry is therefore limited to a **same-origin**
POST:

- `Spec/SameOrigin` compares the Origin (or Referer) host with the wiki’s
  request host. Missing, `null` or foreign origins never match.
- `Hooks::wireEditSessionRetry()` marks the forced-preview page
  (`wb-edit-token-refreshed`) and loads
  `ext.embeddableContent.editsessionretry` only when the POST carried
  `wpTextbox1`, was not a preview/diff, the user is logged in, the token was
  stale, the origin matched, and `wbTokenRetry` is absent.
- `resources/editsessionretry.js` adds `wbTokenRetry=1` and activates
  **Save changes** once. The server sees `wbTokenRetry` on the retry and
  never re-arms the marker, so a second failure cannot loop.

Cross-site POSTs, logged-out users and JS-off keep core’s preview. This also
covers the `action=appendsection` editor (an `EditPage` subclass).

### 4. Add\* relevance ranking

`Fetch/RecordRanker` ranks the merged records before the 10-result cap:
exact title → prefix/subtitle → substring → all query tokens in order →
all tokens → partial (ordered by token count). Equal scores preserve the
original provider order (stable sort), so Wikidata still leads when it is
equally relevant. Titles are normalised (entity decode, markup strip,
lowercase, punctuation/whitespace collapse). Author-only searches keep the
provider order; a title-scoped author search ranks by the title.

### 5. Proprietary license + non-FOSS default

- `manifests/preseed.csv` gains a `software license` row **Proprietary**
  (`foss=false`). The seed creates the item and adds it to the `licenses`
  config map, so the license comboboxes offer it.
- `Special:AddSoftware`’s license field is now **required on create** and
  pre-populated with the known licenses (the shared
  `EntityCombobox::licenseSpec`, extended for multi-value). Directly below
  it, an **Unknown license** checkbox is the explicit opt-out; the server
  clears the license when it is ticked (the checkbox wins over a stale
  value) and the JS disables/clears the combobox. Update keeps the
  no-clobber contract: the field is optional and the checkbox is not shown.
- `SoftwarePageKind::defaultFor()` returns **SOFTWARE** for an empty or
  unresolved license (and when no FOSS-license class is configured).
  A missing license must never imply free/open-source. Explicit
  `pageKind=foss` / the form’s radio still win.
- **Q2721 data repair**: add `P34` → the new Proprietary item; fix
  `Software:Cloudflare One (Formerly WARP)` to transclude `{{Software}}`
  (it still carried `{{FOSS}}` from its original creation). The item’s
  class (Q1432 `software`) and sitelink (`Software:…`) were already correct.

## Consequences

- New ResourceLoader modules and i18n keys in en/fr/eo; deploy needs a
  message-blob purge.
- A stale edit token now saves automatically **only** for same-origin
  requests; the cross-site preview defense is unchanged.
- The seed re-emission creates the Proprietary item and updates the
  `licenses` config map; no new property/class.
- Add\* result order can differ from pure provider order when a later
  provider has a closer title match — the intended behaviour.
- Q2721 keeps a `FOSS:` redirect (harmless); the item and its `Software:`
  page are the live pair.
- Tests: `RecordRankerTest`, `ProviderClientTest` (reported query),
  `MentionSnippetTest`, `SameOriginTest`, `SoftwarePageKindTest`, the seed
  preseed tests, `run_pages_e2e.py` (source checks + unknown-license
  regression) and `run_wiki_ux_e2e.mjs` (upload/edit retry, right-click
  menu).

## References

- `extensions/EmbeddableContent/resources/mentioncontext.js`,
  `uploadform.js`, `editsessionretry.js`, `softwarelicense.js`
- `extensions/EmbeddableContent/includes/Spec/{MentionSnippet,SameOrigin,SpecialAddSoftware}.php`,
  `includes/Fetch/{RecordRanker,ProviderClient}.php`,
  `includes/Flow/SoftwarePageKind.php`
- `extensions/EmbeddableContent/manifests/preseed.csv`
- Related ADRs: `content-page-reference-toolbar.md`,
  `section-auto-anchors-and-proper-name-category.md`,
  `upload-another-fetch.md`, `foss-software-page-split.md`
