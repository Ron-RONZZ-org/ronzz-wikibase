# Decision: Special:Upload source memory + drag/paste

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:Upload` (`resources/uploadform.js`,
  `resources/filepage.js`, `UploadHooks`)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Two paper cuts on `Special:Upload`:

1. **"Submit and upload another image from same author"** reloads a fresh
   upload form with the license/author/license-info fields preserved
   (`wblicense`/`wbauthor`/`wblicenseinfo`), but the **source radio**
   (File | Url) was dropped — the form reset to the fresh-load default
   (Url), so a local-file uploader had to re-pick "Source filename" every
   time.
2. The "Source filename" field accepted only the OS file picker: dragging an
   image onto it or pasting one from the clipboard did nothing.

## Decision

1. **Preserve the source radio** across the "upload another" hand-off.
   `Hooks::onBeforePageRedirect` appends `wbsourcetype` (the browser blob
   fallback's ORIGINAL selection in `wbUploadmetaSourceType` wins over the
   converted-internal `wpSourceType`, lowercased to `file`/`url`);
   `UploadHooks::onUploadFormSourceDescriptors` restores the matching radio
   (after the blob-conversion branch, before the fresh-load default);
   `resources/filepage.js` carries `wbsourcetype` onto the reloaded form.
2. **Accept a dropped or pasted image** (`resources/uploadform.js`): a drop
   on the Source-filename row (or a paste of an image file anywhere in the
   form) switches the source to **File** and fills the picker via a
   `DataTransfer`, firing `change` so the shared `resources/uploadimage.js`
   preview + resize run. A hint + a drop highlight are added
   (`resources/uploadform.css`); a browser without a settable `FileList`
   degrades to a notification, never a silent no-op.

Only the source **radio** is remembered, not the previous URL text (a stale
URL is worse than a fresh empty field).

## Consequences

- A URL uploader pastes another URL immediately; a file uploader re-picks the
  File radio without a round-trip.
- No server config/vocabulary change; deploy = extension rsync + php-fpm
  restart (the JS/CSS are ResourceLoader-versioned).

## References

- `extensions/EmbeddableContent/includes/Hooks.php` (`onBeforePageRedirect`)
- `extensions/EmbeddableContent/includes/Upload/UploadHooks.php`
- `extensions/EmbeddableContent/resources/uploadform.js`, `filepage.js`,
  `uploadform.css`
- `tests/e2e/run_wiki_ux_e2e.mjs` — `wbsourcetype` restore + drop/paste
