# Decision: `Special:Upload` — destination tab + same-origin fetch POST

- **Status**: Accepted (Oct 2026; revised after the iframe probe)
- **Scope**: `wikibase.ronzz.org` — `Special:Upload`, the **Submit and upload
  another image from same author** flow (`resources/uploadform.js`,
  `resources/uploadmeta.js`, `resources/filepage.js`,
  `Hooks::onBeforePageDisplay`)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The “Submit and upload another image from same author” button used to open a
blank destination tab and set the form `target` to it, so the POST ran in a
fresh top-level window. On some sessions that window lost the MediaWiki
session: the server re-rendered `Special:Upload` with the
`session_fail_preview` message (“loss of session data”), and the selected file
was gone.

The first fix attempt submitted the form into a **hidden same-origin iframe**
in the original tab. That is blocked in production: MediaWiki sends
`X-Frame-Options: DENY` on `Special:Upload` responses, so the iframe cannot
load either the error form or the File: page; the fetch/iframe response is a
cross-origin error document the parent cannot read. (The normal **Upload**
button was affected too.)

The desired workflow remains: **the File: page opens in a new tab, and the
original tab resets for the next file of the same author.**

## Decision

The destination tab is only a destination; the upload POST is a **same-origin
`fetch` POST** in the original tab:

1. The submit button click opens the destination tab synchronously
   (`window.open`, so it is not popup-blocked) and writes a small
   “Uploading…” placeholder into it.
2. `resources/uploadform.js` prevents the native form navigation and POSTs a
   `FormData` built from the form to the form action with
   `credentials: 'same-origin'`, `redirect: 'follow'`. The clicked submit
   button's `wpUpload`/`wpUploadAnother` name/value is appended (FormData omits
   submit buttons; core requires `wpUpload`). `uploadmeta.js`'s Wikimedia URL
   → browser-blob conversion calls the shared
   `mw.embeddableContent.upload.submitConverted()` hook instead of natively
   submitting, so the converted file also rides the fetch POST. Add\* forms
   (no `submitConverted` hook) keep the native submit.
3. **Success** (`response.url` is the File: page): navigate the destination
   tab to the final URL. The File: page's existing `filepage.js` opener
   hand-off sends the original tab to a fresh
   `Special:Upload?wpLicense=…&wpUploadAuthor=…` form, ready for the next file.
4. **Failure** (`response.url` stays on `Special:Upload`): parse the response
   HTML with `DOMParser`; close the destination tab; show the server's error
   **inline** above the form; keep every field and the selected file. If the
   parsed body carries the server's `wb-upload-token-failed` class, refresh
   the CSRF token from `action=query&meta=tokens&type=csrf` and tell the user
   to retry. A logged-out response shows a login message instead.
5. **Recoverable warning** (duplicate/exists): show a “Review” action that
   re-submits normally so the stock warning page (and its stash key) loads.
6. **Destination tab blocked or closed**: fall back to navigating the original
   tab on success; failures are always inline.

`Hooks::onBeforePageDisplay` adds the `wb-upload-token-failed` body class when
a posted upload's token does not match; it is the stable signal the parsed
response reader needs (the core message is localized).

## Why not an iframe / new tab

- A fresh top-level tab can lose the session (the original report).
- A hidden iframe is blocked by `X-Frame-Options: DENY` on `Special:Upload`
  responses; neither the error form nor the File: page can be read.
- `fetch` is not subject to `X-Frame-Options`, carries the same-origin
  cookies, and lets the page keep the file input/document alive on failure.

## Consequences

- A failed upload never navigates the original tab, so the file input and all
  other fields survive. Browsers cannot repopulate a file input after a real
  navigation; the fetch is what makes preservation possible.
- The successful two-tab workflow is unchanged: File: in the new tab, original
  tab reset for the next image.
- The destination tab is briefly visible during the upload and closes on
  failure. Opening it only on success is not reliable because popup blockers
  require a user gesture.
- No upload progress bar (fetch has no upload-progress event); the status line
  and destination placeholder cover the feedback. The server's 10-minute
  limit still applies.
- No new configuration, seed, or database change.

## Testing

- `tests/e2e/run_wiki_ux_e2e.mjs` forces a stale CSRF token, clicks **upload
  another**, and asserts: the original tab stays on `Special:Upload`, the
  error is inline, the file is still selected, the token was refreshed, and
  the destination tab closed.
- `tests/e2e/run_pages_e2e.py` source-checks the `FormData`/`fetch`/
  `submitConverted` submission, the token-failure refresh, the inline error
  container, and the upload-another source.
- The normal **Upload** button uses the same fetch path, so its failures also
  keep the file.

## References

- `extensions/EmbeddableContent/resources/uploadform.js`
- `extensions/EmbeddableContent/resources/uploadmeta.js`
- `extensions/EmbeddableContent/resources/filepage.js`
- `extensions/EmbeddableContent/includes/Hooks.php`
- Related decisions: `upload-ux-fixes.md`, `upload-source-memory-dragpaste.md`,
  `ux-batch-2026-09b.md`, `wiki-bug-fixes-2026-09c.md`
