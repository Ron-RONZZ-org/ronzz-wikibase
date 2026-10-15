# ADR — bracketed section controls, section-scoped append/delete, Edit-parity editor

- **Status**: accepted (2026-10-14). Owner: ronzz.org.
- **Supersedes**: the per-section control rendering and the append-form
  editor decisions of `math-note-split-and-page-actions.md` §2–§3.
- **Partly superseded (2026-10-14)** by
  `section-controls-editpage-editor.md`: the controls now load on **every
  classic article page** (not just content pages) and **§4** is replaced —
  the append editor is core's own `EditPage` (`AppendSectionEditPage`), so it
  carries the built-in Show preview / Show changes instead of the bespoke
  `action=parse` Preview, with the "minor edit" checkbox removed. §1–§3 and
  §5 stand (bracketed controls, section-scoped append/delete, the
  auto-inferred summary).

## Context

Following the append "Add section" action
(`math-note-split-and-page-actions.md` §3), three follow-up UX requirements
landed on ordinary content pages:

1. the per-section "Copy internal mention" / "Copy URL" controls must render
   **inside their own `[ ]` bracket pair**, matching the core `[edit]` link —
   they were appended after the closing bracket (`[edit] mention url`);
2. each section needs **"Add section below"** and **"Delete section"** actions;
3. the append editor's Content box should be a **wikitext editor like the
   ordinary Edit page** (WikiEditor toolbar) with a **Preview** button;
4. a blank **Summary** should default to the section-edit convention built
   from the topmost heading of the Content (`== Vandermonde ==` →
   `/* Vandermonde */`).

## Decision

### 1. Bracketed per-section controls

`resources/sectionactions.js` renders, inside each `.mw-editsection`,
`[Copy internal mention] [Copy URL] [Add section below] [Delete section]` —
each control wrapped in `.mw-editsection-bracket` spans (the core `[edit]`
markup), the copy controls staying buttons and the two new actions being
`<a>` links. The section index is read from the existing `[edit]` link's
`section=<n>` parameter (no new server data), so the two new links carry the
number into `?action=appendsection` / `?action=deletesection`.

### 2. Section-scoped append

`AppendSectionAction` gains an optional `section` parameter. Without it, the
save appends to the page end (unchanged). With it, the save appends the
submitted wikitext to the end of that section — below its heading and any
nested subsections — through core's `Content::getSection()` +
`Content::replaceSection()` (the same primitives `EditPage` uses for section
edits), then `WikiPage::doUserEditContent`. The section number rides a hidden
`section` form field because `FormAction`'s form action URL drops the query
string on POST.

### 3. "Delete section"

A new `DeleteSectionAction` (`?action=deletesection&section=<n>`) renders a
**confirmation form** showing the section's wikitext (escaped, in a `<pre>`)
and, on save, replaces the whole section (heading + body) with empty text via
`Content::replaceSection`, then saves through `doUserEditContent`. Both
actions keep the core `edit` right (`getRestriction()`), so edit filters,
AbuseFilter and the page-save hooks all apply.

### 4. Edit-parity wikitext editor + Preview

> **Superseded (2026-10-14)** by
> `section-controls-editpage-editor.md`: the editor is now core's `EditPage`
> (built-in Show preview / Show changes), not a `FormAction` with a bespoke
> JavaScript `action=parse` Preview. The paragraphs below describe the earlier
> implementation.

`AppendSectionAction` switches to a **plain (non-OOUI) HTMLForm** so the
Content textarea keeps the stable id `wpTextbox1` — the exact selector
WikiEditor's own bootstrap (`ext.wikiEditor.js`) attaches the toolbar to. The
server loads `ext.wikiEditor` + `ext.wikiEditor.styles` when the user has the
`usebetatoolbar` preference (and the extension is installed), so the append
editor gets the same wikitext toolbar as the ordinary edit page, with no core
`EditPage` subclassing. A new `ext.embeddableContent.sectionform` module
injects a **Preview** button and box into the form; the preview renders the
boxed wikitext through `action=parse` (the server is the renderer — links,
media and templates behave as on the page), the same primitive used by
`addmath.js` / `addsource-labelpreview.js`.

`dev/config/Extensions.php` loads WikiEditor (guarded by `is_dir`) so the
dev/CI stack matches production (which already loads it — see siteinfo).

### 5. Auto-inferred Summary

New pure `Spec/SectionSummary::infer()` scans the submitted wikitext with
core's heading grammar (`^(={1,6})(.+?)(\1)\s*$`) and returns the
section-edit comment built from the **topmost** heading (fewest `=`). A blank
Summary uses it, falling back to the existing "Added a section" message when
the content has no heading. The scan deliberately mirrors core's own parse
(including its backtracking quirk that `== A =` is an H1 whose text is
`= A`) so the comment matches how the page will actually render.

## Consequences

- Content-page editors get a consistent `[edit] [mention] [url] [add below]
  [delete]` row, an Edit-parity editor with a Preview button on the append
  flow, and section-edit-style summaries for free.
- The append action keeps its clean, upstreamable shape (a `FormAction`, not
  an `EditPage` subclass); the Editor integration relies on WikiEditor's
  documented bootstrap selector + the user preference. **(Superseded
  2026-10-14: the append editor is now core's `EditPage` — see
  `section-controls-editpage-editor.md`.)**
- **Deploy**: extension rsync + php-fpm restart + a message/objectcache purge
  (new i18n keys + the new `deletesection` action). No vocabulary/seed change.

## References

- `extensions/EmbeddableContent/resources/sectionactions.js`,
  `resources/sectionform.js`, `includes/Action/AppendSectionAction.php`,
  `includes/Action/DeleteSectionAction.php`, `includes/Spec/SectionSummary.php`,
  `includes/Spec/AppendSectionText.php`
- `math-note-split-and-page-actions.md` — the original append action
- `tests/e2e/run_section_actions_e2e.py`, `tests/e2e/run_wiki_ux_e2e.mjs`,
  `tests/Unit/Spec/SectionSummaryTest.php`
- MediaWiki: `Content::getSection`/`replaceSection`, `FormAction`,
  WikiEditor (`ext.wikiEditor.js` attaches to `textarea#wpTextbox1`)
