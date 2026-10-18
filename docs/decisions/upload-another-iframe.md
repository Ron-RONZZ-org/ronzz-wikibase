# Decision: `Special:Upload` — destination tab + hidden-iframe POST

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:Upload`, the **Submit and upload
  another image from same author** flow (`resources/uploadform.js`,
  `resources/filepage.js`, `resources/uploadmeta.js`, `UploadHooks`,
  `Hooks::onBeforePageDisplay`)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The “Submit and upload another image from same author” button used to open a
blank destination tab and set the form `target` to it, so the POST ran in a
fresh top-level window. On some sessions that window lost the MediaWiki
session: the server re-rendered `Special:Upload` with the
`session_fail_preview` message (“loss of session data”), and the selected file
was gone. The original tab still held the form, but the user was left with a
broken destination tab.

The desired workflow is still: **the File: page opens in a new tab, and the
original tab resets for the next file of the same author.**

## Decision

The destination tab is now **only a destination**. The upload POST always runs
in a **hidden same-origin iframe in the original tab**:

1. The submit button click opens the destination tab synchronously
   (`window.open`, so it is not popup-blocked) and writes a small
   “Uploading…” placeholder into it.
2. `resources/uploadform.js` installs a hidden iframe
   (`#wbuploadframe`, `sandbox="allow-same-origin"`) in the original tab and
   points the form at it. The POST therefore carries the original tab's session
   cookie; `uploadmeta.js`'s native blob-fallback resubmit uses the same
   target.
3. **Success** (the iframe lands on a `File:` page): navigate the destination
   tab to the iframe's final URL. The File: page's existing
   `filepage.js` opener hand-off sends the original tab to a fresh
   `Special:Upload?wpLicense=…&wpUploadAuthor=…` form, ready for the next file.
4. **Failure** (the iframe stays on `Special:Upload`): close the destination
   tab; show the server's error **inline** above the form; keep every field
   and the selected file. If the response carries the server's
   `wb-upload-token-failed` marker, refresh the CSRF token from
   `action=query&meta=tokens&type=csrf` and tell the user to retry. A logged-out
   response shows a login message instead.
5. **Recoverable warning** (duplicate/exists): show a “Review” action that
   re-submits normally so the stock warning page (and its stash key) loads.
6. **Destination tab blocked or closed**: fall back to navigating the original
   tab on success; failures are always inline.

`Hooks::onBeforePageDisplay` adds the `wb-upload-token-failed` body class when
a posted upload's token does not match, which is the stable signal the iframe
reader needs (the core message is localized).

## Consequences

- A failed upload never navigates the original tab, so the file input and all
  other fields survive. Browsers cannot repopulate a file input after a real
  navigation; the iframe is what makes preservation possible.
- The successful two-tab workflow is unchanged: File: in the new tab, original
  tab reset for the next image.
- The destination tab is briefly visible during the upload and closes on
  failure. Opening it only on success is not reliable because popup blockers
  require a user gesture.
- No new configuration, seed, or database change.

## Testing

- `tests/e2e/run_wiki_ux_e2e.mjs` forces a stale CSRF token, clicks **upload
  another**, and asserts: the original tab stays on `Special:Upload`, the
  error is inline, the file is still selected, the token was refreshed, and
  the destination tab closed.
- `tests/e2e/run_pages_e2e.py` source-checks the hidden iframe, the
  token-failure refresh, the inline error container, and the upload-another
  source.

## References

- `extensions/EmbeddableContent/resources/uploadform.js`
- `extensions/EmbeddableContent/resources/filepage.js`
- `extensions/EmbeddableContent/resources/uploadmeta.js`
- `extensions/EmbeddableContent/includes/Hooks.php`
- `extensions/EmbeddableContent/includes/Upload/UploadHooks.php`
- Related decisions: `upload-ux-fixes.md`, `upload-source-memory-dragpaste.md`,
  `ux-batch-2026-09b.md`, `wiki-bug-fixes-2026-09c.md`
