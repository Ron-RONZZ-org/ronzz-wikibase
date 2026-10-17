# ADR — section controls on all classic pages, core-EditPage append editor

- **Status**: accepted (2026-10-14). Owner: ronzz.org.
- **Supersedes** (in `section-controls-and-editor.md`): the scope decision
  (the controls were content-page-only) and **§4** (the append editor was a
  `FormAction` with a bolted-on `action=parse` preview). §1–§3 and §5 of that
  ADR still stand (bracketed controls, section-scoped append/delete, the
  auto-inferred summary).

## Context

Two follow-ups on the per-section controls shipped in
`section-controls-and-editor.md`:

1. **Scope** — the controls (`resources/sectionactions.js` +
   `wbReferenceSnippet`) loaded only on the *content-page* branch of
   `Hooks::onBeforePageDisplay`, and the classic per-kind namespaces
   (`Source:`/`Person:`/`Collective:`/`FOSS:`/`Software:`) returned early with
   their own item toolbar, never reaching that branch. The "Add section" page
   action was likewise gated to `Title::isContentPage()`. Other classic
   article namespaces (`Help:`, and the gated `RonzzIT:`/`RonzzInt:`) got
   neither.
2. **Editor UX** — the append editor was a plain `HTMLForm` with a
   `wpTextbox1` textarea plus a JavaScript **Preview** button that rendered
   through `action=parse` into a custom box. It was *not* the built-in edit
   surface: no server-side Show preview placement, no Show changes (diff),
   and a look that drifted from the ordinary editor.

## Decision

### 1. Scope: every classic article page

`Hooks::isClassicArticleTitle()` is the one predicate: any **existing** page
that is not a `Special:` page, not a Wikibase entity page (`Item:`/
`Property:`), not a `File:` page (its own copy toolbar), not a technical
namespace (`Category:`/`Template:`/`MediaWiki:`/`Module:`) and not a talk
page. This covers `Main`, `Help`, `Cheatsheets`, `HowItWorks`, `FOSS`,
`Person`, `Source`, `Collective`, `Software`, `Forum`, `User`, and the gated
`RonzzIT:`/`RonzzInt:` namespaces.

- `Hooks::wireSectionControls( $out, $title )` sets `wbReferenceSnippet` and
  loads `ext.embeddableContent.sectionactions`; it runs on the classic
  per-kind branch (alongside the item toolbar) **and** on the general
  article branch (where the page-level mention/citation toolbar stays
  content-page-only).
- `Hooks::addSectionAction()` (the "Add section" page action) now anchors on
  `isClassicArticleTitle()` instead of `isContentPage()`. The Sitelink tab
  keeps its original content-page + non-entity gate.

### 2. The append editor IS core's edit surface

`AppendSectionAction` is now a thin `EditAction` wrapper that renders
`AppendSectionEditPage extends MediaWiki\EditPage\EditPage`. The editor is
therefore byte-for-byte the ordinary edit form — the wikitext toolbar
(`ext.wikiEditor` attaches to `wpTextbox1` via its own
`EditPage::showEditForm:initial` hook), **server-rendered Show preview** and
**Show changes**, summary, watch, edit-conflict handling and the full edit
filter / AbuseFilter / page-save-hook pipeline. The single intended UX
difference is the removed **"This is a minor edit"** checkbox
(`getCheckboxesDefinition()` drops `wpMinoredit`).

Append semantics come from two focused overrides, not from forking the
editor:

- `getContentObject()` returns an **empty** content object, so the text box
  starts blank (the contributor types only the new block);
- `toEditContent( $text )` returns the **assembled** page — the current
  wikitext with the typed block appended at the page end, or at the end of
  the target section (below its heading and any nested subsections) through
  core's `Content::getSection()` / `replaceSection()`. `EditPage` reads the
  edit content through this method for the diff **and** the save (with
  `$this->section` empty, `WikiPage::replaceSectionAtRev( '' )` passes the
  assembled text straight through). **Show preview is the exception**: it
  gets only the typed block (the section being added), matching core's
  section editor — the preview must never render the whole article. Both
  entry points (page-level `?action=appendsection` and per-heading
  `?action=appendsection&wbsection=<n>`) share this contract.

The target section travels in a **`wbsection`** request parameter — not
core's `section`, which must stay empty so `EditPage` treats the flow as a
whole-page edit. `getActionURL()` posts back to `?action=appendsection`
(core's `EditPage` posts to `action=submit`) and carries `wbsection` across
the POST.

The append flow keeps its blank-Summary auto-inference
(`Spec\SectionSummary::infer`, applied in `attemptSave()`), and a target
section that vanished while the form was open (a racing edit) degrades to a
page-end append with a logged warning.

### 3. Removed

- `resources/sectionform.js` and the `ext.embeddableContent.sectionform`
  module (the bespoke `action=parse` Preview) — replaced by the editor's own
  Show preview. The obsolete `embeddablecontent-sectionform-preview*` i18n
  keys and the unused `embeddablecontent-appendsection-{legend,desc,content,
  summary,watch,submit,required,error,below-legend,below-desc,missing-section}`
  keys are dropped; `-title`, `-below-title` and `-summary-default` remain.

## Consequences

- Editors get the same controls on every classic page, and the Add-section
  flows are indistinguishable from a normal edit except for the missing
  minor-edit checkbox — true Edit parity for free, including future core
  editor improvements.
- **Show preview is section-scoped**: both the page-level "Add section" and
  the per-heading "Add section below" previews render only the typed block,
  never the assembled whole page (the regression fixed Oct 2026). Show
  changes still diffs the assembled page and Save still appends it.
- Reusing `EditPage` (rather than forking) is the sanctioned MediaWiki
  extension point; it supersedes the earlier "a `FormAction`, not an
  `EditPage` subclass" consequence. The integration surface is the documented
  overridable methods (`getContentObject`, `toEditContent`,
  `getCheckboxesDefinition`, `getActionURL`, `setHeaders`, `attemptSave`).
- **Deploy**: extension rsync + php-fpm restart + a message/objectcache purge
  (removed i18n keys). No LocalSettings, vocabulary or seed change.
- **Gotcha (the 2026-10-15 deploy)**:
  `Hooks::isClassicArticleTitle()` must not reference an
  extension-conditional `NS_*` constant unguarded. **`NS_MODULE` is defined by
  Scribunto, not core** — the CI/WBS stack loads Scribunto (so CI passed) but
  production does not, and the unguarded use fataled **every** page render
  (`Error: Undefined constant "EmbeddableContent\NS_MODULE"`). Guard such a
  constant with `defined()`: `defined( 'NS_MODULE' ) && $namespace ===
  NS_MODULE`. (Fixed in the follow-up hotfix.)

## References

- `extensions/EmbeddableContent/includes/Action/AppendSectionAction.php`,
  `includes/Action/AppendSectionEditPage.php`,
  `includes/Hooks.php` (`isClassicArticleTitle`, `wireSectionControls`,
  `addSectionAction`), `resources/sectionactions.js`,
  `includes/Spec/AppendSectionText.php`, `includes/Spec/SectionSummary.php`
- `section-controls-and-editor.md` (§1–§3, §5 stand; §4 and the scope are
  superseded), `math-note-split-and-page-actions.md` §2/§3
- `tests/e2e/run_section_actions_e2e.py`,
  `tests/e2e/run_wiki_ux_e2e.mjs`, `tests/Unit/Spec/SectionSummaryTest.php`
- MediaWiki: `EditPage` (`getContentObject`/`toEditContent`/`getActionURL`/
  `getCheckboxesDefinition`), `WikiPage::replaceSectionAtRev`, `EditAction`
