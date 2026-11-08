# Decision: Automatic page-text archive (`Source:<label>/archivedText`)

- **Status**: Accepted (Nov 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSource` (webpage class), the
  `Source:` namespace, the `Template:Webpage` infobox
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A `Source:` page records citation metadata, but a webpage can change or
disappear. Contributors asked to keep the page text they cited, so the
citation stays verifiable. Storing a new Wikibase statement for the archive
would ripple through `SourceFieldMap`, the field contract and the pinned MCP
schema for a purely presentational feature.

## Decision

1. **A wikitext subpage is the archive.** On `webpage` creation (the
   "Fetch and archive the page text" checkbox, default on), the page is
   fetched and converted to wikitext, then saved as
   `Source:<label>/archivedText` (`SourceArchive::SUBPAGE` — fixed English,
   not localized; the `Source:` namespace already enables subpages). No new
   property, statement, config key or field-contract entry.
2. **Fetch at creation, not at URL entry.** `SpecialAddSource::afterCreate()`
   runs after the parent `Source:` page exists; a failed fetch, conversion or
   save logs a warning and never blocks the item (`SourceArchive::create()`).
3. **Conversion is Pandoc.** `HtmlPreparer` strips chrome, replaces `<img>`
   with placeholders and protects `<ref>…</ref>` (Pandoc's HTML reader drops
   the tag); `ArchivedTextConverter` shells out to
   `pandoc -f html -t mediawiki --wrap=none` through MediaWiki's Shell
   framework; `WikitextScrubber` then removes `{{…}}` (innermost-first, so
   nesting unwinds), unwraps `[[…]]` to its label, turns media links into
   `([<url> Media content: view on original URL]) Caption`, removes
   behaviour switches, and **keeps `<ref>`**.
4. **No dedicated `Special:` view.** The archived text IS the wiki page, so
   the `Source:` page gains a conditional `{{#source-archive:}}` row
   (`Template:Webpage`) that links it only when the subpage exists — the
   `{{#quotations-of:}}` pattern. Saving/deleting the subpage invalidates the
   parent's parser cache (`PageSaveComplete` / `PageDeleteComplete`).
5. **A prominent, transient banner.** `Hooks::onBeforePageDisplay` prepends
   the disclaimer ("automatic archive … at <url> as it appeared on <time> …
   no warranty …") on the `/archivedText` page. It is OutputPage-level, not
   parser output (not cached, reads the committed first revision), and
   **prints with the page** (Save-as-PDF / Ctrl-P). Dismissal is
   **transient**: the dismiss link removes the DOM node; a refresh shows it
   again. The control is `<br><a>Dismiss</a>` (Nov 2026; it was a
   `<button>`); screen colours use Codex theme tokens so Vector 2022 night
   mode stays readable, while print forces black on white and hides the
   control.
6. **URL and timestamp for the banner.** The timestamp is the subpage's first
   revision; the URL is the parent item's `url`, else `accessUrl` (a fallback
   to the parent page title when neither exists). No archive-specific
   statement is written.
7. **Print cover (Nov 2026).** An archive page's print flow does not credit
   wiki contributors: `print.js` renders a cover with
   `<h1>{source title} ({class})</h1>` and "Text archived on {date} from
   {url}" (`SourceArchiveBanner::coverData()`). The cover option is
   default-checked; the Save-as-PDF filename uses the source title, not
   `…_archivedText`.

## Sources consulted

- MediaWiki: `Manual:$wgNamespacesWithSubpages`, `Manual:Special_pages`,
  `Manual:WikiPage.php` (`doUserEditContent`), `Manual:Page content models`,
  `Manual:Parser functions`, `Manual:Parser cache`,
  `Manual:Hooks/BeforePageDisplay`, `Manual:Hooks/ParserAfterTidy`,
  `Manual:Hooks/PageSaveComplete`, `Manual:Hooks/PageDeleteComplete`,
  `Manual:Shell framework`, `Manual:Sanitizer.php`, `Manual:'$wgNoFollowLinks`.
- MediaWiki class refs: `Title`, `WikiPage`, `RevisionStore`, `OutputPage`,
  `Language`, `Html`.
- Pandoc manual (`-f html -t mediawiki`, `--wrap=none`).
- OWASP SSRF Prevention Cheat Sheet (the fetch reuses `SsrfGuard` +
  `HttpRequestFactory::create( ..., ['rejectLocalUrls' => true] )`).
- MDN: `role="note"`, `<time datetime>`.

(Explicitly **not** used: `Title::newSubpageTitle` — it does not exist in
current core; build the title with `Title::newFromText( "$base/archivedText" )`.
Readability/article extraction was dropped: Pandoc + chrome stripping is
enough and adds no dependency.)

## Implementation

| Piece | File |
|---|---|
| Page creation | `includes/SourceArchive/SourceArchive.php` |
| Fetch | `includes/Fetch/PageMetadataFetcher.php` (`fetchHtml()`) |
| HTML prep | `includes/SourceArchive/HtmlPreparer.php` |
| HTML → wikitext | `includes/SourceArchive/ArchivedTextConverter.php` |
| Wikitext rules | `includes/SourceArchive/WikitextScrubber.php` |
| Banner | `includes/SourceArchive/SourceArchiveBanner.php` |
| Row function | `includes/ParserFunctions/SourceArchiveRow.php` |
| Checkbox + hook | `includes/Spec/SpecialAddSource.php` |
| Wiring | `includes/Hooks.php` |
| Client | `resources/sourcearchive.js`, `resources/sourcearchive.css` |
| Messages | `i18n/{en,fr,eo}.json` (8 keys) |
| Template | `wiki_pages/Template/Webpage.wiki` |

## Deploy

- **Pandoc 3.x must be on the wiki host's PATH** (the dev stack and the
  server). `ArchivedTextException` is logged at WARNING and the item is
  created without the archive when it is missing.
- Deploy the extension (parser function + banner) **before** pushing the
  updated `Template:Webpage` (an unknown parser function is a wikitext
  error — the `tools/rewrite_infobox_templates.py` rule), then purge the
  message + parser caches.
- No re-seed, no LocalSettings change, no field-contract regeneration.

## Consequences

- Every opted-in webpage creation pays one SSRF-guarded fetch + one Pandoc
  process (8 s timeout, 2 MB HTML cap, soft failure).
- The extracted text is best-effort (no article extractor): the banner
  states there is no warranty on completeness or accuracy.
- A page that requires JavaScript to render will archive its static HTML
  only; this is accepted (a headless renderer is out of scope).
