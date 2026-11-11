# Decision: instance licence — CC BY-NC-SA 4.0

- **Status**: Accepted (Nov 2026)
- **Scope**: `wikibase.ronzz.org` — the MediaWiki site licence, the Wikibase
  structured-data rights, the footer badge, the upload licence picker and the
  licence carried by page downloads
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Decision

1. **Site licence** — `$wgRightsUrl`, `$wgRightsText` and `$wgRightsIcon` in
   `LocalSettings.php` become CC BY-NC-SA 4.0
   (`https://creativecommons.org/licenses/by-nc-sa/4.0/`). The footer icon is
   **MediaWiki core's bundled** `resources/assets/licenses/cc-by-nc-sa.png`
   (88×31) — no CDN, no upload, no extra asset to maintain. The footer text
   links to the deed; `$wgRightsPage` stays empty (there is no separate local
   copyright page).
2. **Structured-data rights** — the seed emits
   `$wgWBRepoSettings['dataRightsUrl']` / `rdfDataRightsUrl` as CC BY-NC-SA
   4.0 (previously CC BY-SA 4.0; before that CC0), so RDF/entity exports
   carry the same licence as the page content.
3. **Uploads** — `MediaWiki:Licenses` gains
   `* cc-by-nc-sa-4.0|CC BY-NC-SA 4.0` and `Template:Cc-by-nc-sa-4.0` is
   created; the other per-file licence choices are unchanged.
4. **Downloaded content** — every Download output carries the licence:
   wikitext gets the notice sentence prepended, HTML gets a badge + one-line
   notice footer at the end, and the PDF gets a licence page (the deed glyphs
   + summary) after the cover. See `docs/decisions/printable-version.md`.

## Rationale

The instance's editorial content is non-commercial and share-alike;
CC BY-NC-SA 4.0 states that in the footer and in every export without a
second licensing scheme to maintain. The badge is already shipped by
MediaWiki core, so the footer follows the standard installer layout.

## Consequences

- The footer reads "Content is available under CC BY-NC-SA 4.0 unless
  otherwise noted", with the official badge.
- Third-party content under another licence must be indicated at the source
  (the download notice says so).
- The data-rights change requires a seed re-emission (full vocabulary set) on
  deployment; `MediaWiki:Licenses` and `Template:Cc-by-nc-sa-4.0` are tracked
  in `wiki_pages/` and reach production through the wiki-page sync.

## References

- `seed/config_builder.py` (`dataRightsUrl` / `rdfDataRightsUrl`)
- `dev/config/Extensions.php` (CI/dev mirror of the licence block)
- `wiki_pages/MediaWiki/Licenses.wiki`, `wiki_pages/Template/Cc-by-nc-sa-4.0.wiki`
- `extensions/EmbeddableContent/resources/license.js`, `print.js`
