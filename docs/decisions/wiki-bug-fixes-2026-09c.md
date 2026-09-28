# ADR — wiki bug batch 2026-09c (AddPerson, upload copy, display math)

Status: accepted (2026-09-28). Owner: ronzz.org.

Three unrelated user reports, fixed in one batch.

## 1. `Special:AddPerson` — OpenAlex author autofetch was blank

**Report**: picking the OpenAlex author `A5083194223` left the review form
empty.

**Cause**: `OpenAlexProvider::mapAuthors()` built a `PersonRecord` with only
`label`/`orcid`/`openalexId`/`wikidataId`. OpenAlex author objects carry no
structured given/family fields, and `A5083194223` has neither an ORCID nor a
Wikidata Q-id, so `enrichRecord()` skipped the Wikidata-hub harvest and the
review form's given/family fields stayed empty. (ORCID and dblp records were
unaffected — ORCID sets given/family, dblp usually enriches to a Q-id.)

**Fix**: split `display_name` via the existing `Spec\NameSplitter`
(last word = family) in `mapAuthors()`, so any label-only author prefills the
name fields. Unit test in `tests/Unit/Fetch/OpenAlexProviderTest.php`.

## 2. `Special:Upload` — "Copy internal embed code" copied nothing

**Report**: with the checkbox ticked, `[[File:xxx]]` was not on the clipboard
after upload.

**Cause**: the server hand-off was correct (`Hooks::onBeforePageRedirect`
appends `?wbuploadcopy=1`; `filepage.js` copies on load), but a **page-load
clipboard write is blocked without a user gesture** — verified live with
Playwright: Firefox rejects `navigator.clipboard.writeText` ("lack of user
activation") and `document.execCommand('copy')` returns false; Chromium only
auto-grants the write to a focused tab (so the "upload another" new-tab
destination is blocked too). The existing browser E2E masked this by granting
`clipboard-write` up front.

**Fix**: `filepage.js` attempts the auto-copy best-effort and, when it is
blocked, renders a persistent one-click notice with the snippet on the
destination File: page (reusing the toolbar button primitive). The browser
E2E now checks the fallback **without** clipboard permission. This is the
only browser-compliant behavior — the copy cannot be silently forced on load.

## 3. Multiline display math (`$$…\\…$$`) rendered on one line

**Report**: `Logical_proof`'s `$$ … \\ … $$` blocks collapsed to a single line.

**Cause**: not KaTeX and not a regression — classic pages use the vendored
**SimpleMathJax / MathJax 3**, and MathJax 3 renders a **top-level `\\` as a
space**, not a line break (verified against MathJax 3.2.2 source and the live
page: the `\\` becomes `<mjx-mspace>`). Multiline display math must use an
environment (`gather`/`aligned`/`array`).

**Fix**: extend the existing SimpleMathJax local patch with a new
`InternalParseBeforeLinks` pass (`SimpleMathJaxMultiline`) that wraps a
display span whose content carries a **top-level** `\\` in
`\begin{gathered}…\end{gathered}`. A `\\` inside an environment or braced
group is left alone (those already break lines). The delimiter search is
shared with the quote guard. Unit test + a server-side E2E assertion + a
browser E2E assertion (two rendered rows). See
`extensions/SimpleMathJax/VENDORED.md`.

## Consequences

- No vocabulary/seed/config-map change for any of the three.
- The math fix changes the rendered TeX for affected pages (not the stored
  wikitext); it is a documented patch to the vendored extension, proposed
  upstream with the companion quote guard.
- The upload fix adds one i18n key (en/fr/eo).
