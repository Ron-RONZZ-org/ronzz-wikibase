# Decision: Static LLM-maintained translations — no page translation markup

- **Status**: Accepted (Aug 19 2026) — supersedes the page-translation use of the
  Translate extension (installed Aug 18 2026)
- **Scope**: `wikibase.ronzz.org` — multilingual content model for help/cheatsheet
  pages (entity terms stay Wikibase-native, see Consequences)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

On Aug 18-19 2026 the `Help:Contributing` migration marked **10 pages** for translation with the
Translate extension (`<translate>`/`<tvar>`/`<!--T:n-->` markup, `pagetranslation` right,
`Special:Translate`). The pages were still being actively authored:

- `Help:Contributing/code` received **15 edits** Aug 1-19, 12 of them after marking; its unit
  numbers drifted out of order within days (T:44 inserted between T:11 and T:12, T:45/46 appended).
- Every paragraph/heading/table cell was wrapped in `<translate>` + `<!--T:n-->`; inline code
  tokens were wrapped in `<tvar name="…">` — one `<blockquote>` carried **15 tvars**.
- A minefield of parser rules made each edit error-prone (`pt-shake-position`, marker spacing,
  whole-`<ol>` units, never-tvar-a-link), and the MCP full-source `update-page` workflow had to
  regenerate all of it by hand.

The wiki has **two editors** (Rongzhou + SeedBot) and **no translator community**; the
extension's unit/community workflow served nobody. Only one real translation existed
(`Help:Contributing/languages/fr`, partial sample).

## Decision

**No translation markup on wiki pages, ever.** Multilingual content = static `fr`/`eo` subpages
(e.g. `Help:Contributing/code/fr`) produced and maintained by **LLM-assisted translation of the
clean wikitext**, with browser machine translation as the reader-side complement.

- Pages are authored as **plain wikitext** — no `<languages/>`, `<translate>`, `<tvar>`,
  `<!--T:n-->`, no `Special:MyLanguage` links.
- A translated copy lives at a `/lang` subpage, opens with a
  `{{Translation|lang=fr|based-on=<revid>|date=YYYY-MM-DD}}` banner, and is linked from the
  source page via a `{{Languages}}` bar.
- **Drift signaling** (the one real function the markup provided — "outdated" flags on changed
  units) is replaced by: the `based-on` revision on every copy + the convention that **an EN
  edit regenerates the fr/eo copies in the same session** (see
  `content-creation/AGENTS-translation.md`).
- The Translate extension stays **installed but inert** (zero marked pages). Re-enabling is a
  page-marking exercise, not a reinstall — the revisit trigger is "a translator community
  appears".

## Why

1. **The markup served a coordination workflow, not readers.** Unit tracking, fuzzy flags,
   progress UI and `Special:Translate` exist to orchestrate *human* translators. There is no
   translator community; translations are produced by the owner or an LLM in whole-document
   passes. The machinery's cost (markup noise, re-mark/purge cycle, `RenderTranslationPageJob`
   cron, unit renumbering) was paid by the only people who edit — with no translator to benefit.
2. **Translate's unit model is hostile to LLM translation.** tvars freeze tokens, unit boundaries
   ignore sentence boundaries, and `<!--T:n-->` noise pollutes output. An LLM wants
   whole-document clean text. The two workflows are structurally incompatible.
3. **Browser machine translation covers fr; static copies cover eo.** All browsers auto-translate
   fr, but **eo is not supported by Firefox or Safari auto-translate** (Chrome only) — static
   LLM-translated eo pages are the only reliable eo path.
4. **Editing cost was measured, not hypothetical.** 15 edits on one marked page in 19 days, unit
   numbering non-sequential after days, a full hand-strip of the markup (rev 793, "strip
   translation markup", 9672 → 5823 bytes) — the friction was real and immediate.
5. **Simpler operations.** No marking/unmarking, no `Translations:` namespace cruft, no
   render-job quirk dependency, no language-bar parser integration.

## Consequences

- **Gained**: clean wikitext everywhere; LLM-quality fr/eo copies; no markup maintenance; no
  re-mark/purge/unit churn; fewer server-side moving parts; readers get fr everywhere (browser
  MT) and eo via static copies.
- **Given up**: unit-level fuzzy/outdated flags; `Special:Translate` UI; `Special:MyLanguage`
  automation; `Special:PageTranslation` administration; future volunteer-translator onboarding
  via the extension.
- **Risk — staleness is convention-based**: mitigated by the `{{Translation}}` `based-on`
  revision, the same-session regeneration rule, and on-demand refresh when a page settles.
- **Risk — LLM translation can mangle wikilinks/templates/code**: mitigated by explicit
  preservation rules in the translation prompt (`content-creation/AGENTS-translation.md`) and
  human review of the first fr/eo copies.
- **Entity terms untouched**: labels/descriptions/aliases remain Wikibase-native multilingual
  (ULS stays active for language selection) — this decision covers *page* content only.
- **Reversal**: re-enabling Translate = re-marking pages + restoring markup; the ADR records the
  revisit trigger so the cost is a conscious choice, not an accident.

## Alternatives considered

| Option | Assessment |
|--------|-----------|
| **A.** Keep marking + edit via clean-copy tooling (re-inject markup, re-mark, re-purge) | Rejected — two sources of truth, script maintenance, and every content edit still shifts units → fuzzy-translation churn; overkill for 2 editors |
| **B.** Hybrid units (translate only stable prose, leave tables/examples untagged) | Rejected — tagged units still carry the full noise; `code`-style pages are mostly tables/examples; untagged-content behavior needs verification |
| **C.** Static LLM copies + `{{Translation}}` banner (chosen) | Accepted — zero markup, drift signaled by `based-on` + same-session regeneration |
| **D.** Do nothing; formalize "marked = frozen" | Rejected — schedules the noise, does not remove it |
| **E.** Manual per-language pages without LLM | Superseded by C — LLM translation is the point of the model |

## Cleanup record (Aug 19 2026)

- All 10 marked pages unmarked (removed from translation) and their markup stripped to plain
  wikitext; `Special:PageTranslation` empty.
- 212 `Translations:` unit pages + auto-generated `/en` subpages deleted.
- `Help:Contributing/languages/translationAdmin` folded into
  `Help:Contributing/languages/translator` (no marking role anymore).

## References

- `docs/README.md` §Translation & multilingual content (rewritten Aug 19 2026)
- `content-creation/AGENTS-translation.md` — static translation maintenance convention
- `content-creation/glossary.md` — en/fr/eo canonical terms for LLM translation
- Translate extension unmarking: `TranslatablePageMarker::unmarkPage()` (`unlink` deletes
  translation pages) — https://www.mediawiki.org/wiki/Help:Extension:Translate/Page_translation_administration