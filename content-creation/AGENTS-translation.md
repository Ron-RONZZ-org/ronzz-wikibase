# AGENTS-translation.md — Static translation maintenance (content-creation)

Translation companion to [`AGENTS.md`](AGENTS.md), loaded when a translation task is in play.
Since the ADR [`docs/decisions/static-llm-translation.md`](../docs/decisions/static-llm-translation.md)
(Aug 19 2026) there is **no Translate-extension markup** on this wiki — translations are static
`/lang` subpages maintained by LLM-assisted translation of clean wikitext.

## Translation policy

- **Never add translation markup** — no `<languages/>`, `<translate>`, `<tvar>`,
  `<!--T:n-->`, no `Special:MyLanguage` links. Pages are plain wikitext.
- **fr/eo copies are static subpages** (`Help:Contributing/code/fr`), linked from the source
  page with a `{{Languages}}` bar, each opening with
  `{{Translation|lang=fr|based-on=<revid>|date=YYYY-MM-DD}}` (the drift signal: which EN
  revision it mirrors).
- **Translate on demand, not en masse.** Only pages that have settled editorially, or pages
  the user explicitly asks for. Do not create translations of pages still being restructured
  (the 2026-08-18 mistake: 10 pages marked mid-migration, `code` churned 15 edits in 19 days).
- **Same-session regeneration**: when an EN page with fr/eo copies is edited, regenerate those
  copies in the same session — the new EN revision becomes the `based-on`. If EN pages are
  edited outside a session (e.g. by hand), flag the stale copies to the user rather than
  silently leaving them.
- **Browser machine translation complements, never replaces, the static copies** — eo is not
  auto-translatable in Firefox/Safari (Chrome only); fr is covered everywhere.

## Translation workflow

1. Fetch the current EN wikitext (clean — no markup) and its latest revision ID.
2. Translate with the LLM using the glossary (`glossary.md`) and the preservation rules below.
3. Save to `Page/<lang>` (e.g. `Help:Contributing/code/fr`) with the
   `{{Translation|lang=…|based-on=<revid>|date=…}}` banner as the first line.
4. If the language is new, add the copy to the EN page's `{{Languages}}` list (and remove it
   if a copy is deleted).
5. Verify: page size via `prop=revisions` (a size collapse means truncation) + rendered HTML
   via `action=parse`.

## Preservation rules (prompt instructions for the LLM)

- Keep `[[…]]` wikilinks, entity IDs (`Q\d+`/`P\d+`), URLs, and `<syntaxhighlight>`/`<pre>`/
  `<code>` blocks **verbatim**.
- Translate heading text but keep the `== … ==` structure and section order.
- Keep table structure; translate header cells and prose only.
- Keep template calls; translate only visible prose parameters (never `name=`, `id=`, numeric
  arguments that are IDs).
- Never translate proper nouns (see `glossary.md`).
- Flag uncertain terms to the user instead of guessing — extend `glossary.md` when a term
  recurs.

## What is archived (do not revive)

- The Translate-extension marking workflow (`action=markfortranslation`, `pagetranslation`,
  `Special:Translate`, `Translations:` namespace) is **archived** — see the ADR's cleanup
  record. The extension remains installed but inert; re-enabling is a deliberate, user-approved
  decision (revisit trigger: a translator community appears), not something to improvise.
- `Help:Contributing/languages/translationAdmin` was folded into
  `Help:Contributing/languages/translator` (no marking role exists anymore).

## Documentation Reference

- `[[Help:Contributing/languages]]` — hub: entity terms + page translation (the static-copy model)
- `[[Help:Contributing/languages/translator]]` — how translations are produced and maintained
- `glossary.md` (this directory) — en/fr/eo canonical terms
- `docs/decisions/static-llm-translation.md` — the decision + rationale + cleanup record