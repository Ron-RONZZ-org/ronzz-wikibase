# AGENTS.md — content-creation Agent Instructions

## Summary

Creating and editing wiki content on ronzz-wikibase (wikibase.ronzz.org)
through the `mediawiki-mcp-server`. All pages referenced below are **live wiki
pages** — edit them via the MCP tools, never by writing files in this
directory.

## Purpose and Expected Behavior

This module governs **on-wiki content**: entity terms (labels, descriptions,
aliases), classic wiki pages (`Help:` namespace), and their translation
markup. It is the enforcement point of the instance language policy
(en/fr/eo) and of the `<translate>`/`<tvar>` conventions taught by the
`Help:Contributing` family.

Deployment context: see [repo root AGENTS.md](../AGENTS.md) for the
ronzz-wikibase deployment info, then the `mediawiki-mcp-server` section below
for wiki-side identity.

### `mediawiki-mcp-server`

- The MCP server authenticates as **SeedBot** (user ID 5). Groups: bot,
  bureaucrat, sysop, `*`, user, autoconfirmed.
- **There is no `translationadmin` group** on this wiki. The Translate
  extension's marking right is `pagetranslation`, granted to **sysop**.
  SeedBot is sysop, so it can already mark pages — no group change is needed.
- Bot credentials live outside this repo at
  `~/.config/mediawiki-mcp/ronzz-wikibase.json` (bot password, username
  `SeedBot@MCP`). Never copy credentials into this repo.

### Translation marking workflow

**Only for pages approved for marking** — i.e. pages that have reached
editorial stabilisation (team leader's call — see
[Translation policy](#translation-policy)). The MCP server has **no marking
tool**.
Use API module **`action=markfortranslation`** (`MarkForTranslationActionApi`, NOT `action=pagetranslation` as found on some older MediaWiki versions).
Parameters: `title` (or `pageid`), `revid` (the latest page revision), `token`;
optional `prioritylanguages`, `transclusion`, `nofuzzyunits`, `fuzzyunits`.

Steps after the team leader approves marking a page:

1. **Login + CSRF + mark** (password read from the config file, never echoed):

   ```bash
   CFG=~/.config/mediawiki-mcp/ronzz-wikibase.json
   SERVER=$(jq -r '.wikis["wikibase.ronzz.org"].server' "$CFG")
   SCRIPT=$(jq -r '.wikis["wikibase.ronzz.org"].scriptpath' "$CFG")
   USER=$(jq -r '.wikis["wikibase.ronzz.org"].username' "$CFG")
   API="$SERVER$SCRIPT/api.php"
   JAR=$(mktemp)
   LT=$(curl -s -c "$JAR" "$API?action=query&meta=tokens&type=login&format=json" | jq -r '.query.tokens.logintoken')
   curl -s -b "$JAR" -c "$JAR" --data-urlencode "lgname=$USER" \
     --data-urlencode "lgpassword=$(jq -r '.wikis["wikibase.ronzz.org"].password' "$CFG")" \
     --data-urlencode "lgtoken=$LT" --data-urlencode "format=json" "$API?action=login" >/dev/null
   CSRF=$(curl -s -b "$JAR" "$API?action=query&meta=tokens&type=csrf&format=json" | jq -r '.query.tokens.csrftoken')
   curl -s -b "$JAR" --data-urlencode "action=markfortranslation" \
     --data-urlencode "title=PAGE_TITLE" --data-urlencode "revid=REV_ID" \
     --data-urlencode "token=$CSRF" --data-urlencode "format=json" "$API"
   rm -f "$JAR"
   ```

   Expect `{"markfortranslation":{"result":"Success","unitcount":N}}`
   (re-mark of an existing page) or `{"result":"Success","firstmark":"",...}`
   (first mark).

2. **Purge** the page so the parser cache re-renders the language bar
   (marking does NOT bump the page revision): `action=purge` with
   `titles=...|...&forcelinkupdate=1` and the same CSRF token.

3. **Verify**: the rendered page shows the `<languages/>` bar as
   "Other languages: English" — it appears only on marked pages.

## Translation policy

- Official languages remain **en, fr, eo**, best-effort — but multilingual
  content is **sequenced, not day-one**. New pages are authored **without**
  translation markup.
- **No `<languages/>`, `<translate>` or `<!--T:n-->` markers until editorial
  stabilisation.** Whether a page is stable enough to mark is the **team
  leader's call** — not the author's, not the bot's.
- Until approved, author plain wikitext in **unit-shaped chunks** (one idea
  per paragraph, self-contained tables, standalone code blocks) so the later
  `<translate>` conversion is mechanical and yields clean units.
- Mark **page-by-page**, never a whole series at once. Re-mark only when a
  marked page's unit structure changes (see marking workflow above).
- Rationale: markers make large restructuring cumbersome (marker
  renumbering, re-marking, fuzzy units, translator churn); churn-prone
  content (e.g. cheatsheets) must not carry them until it stabilises.

## Constraints and Invariants

- **Language policy**: official languages are **en, fr, eo**, best-effort.
  Guides must not mention other languages (adding languages is deliberately
  undecided). Content should be multilingual: entity terms (labels,
  descriptions, aliases) and wiki pages — wiki pages get translation markup
  only once approved (see Translation policy).
- A page **approved for marking** must start with `<languages/>` and wrap
  each unit in `<translate>` with a `<!--T:n-->` marker; keep markers
  sequential when writing them by hand (the extension renumbers on marking).
- Examples must use real entities: P1 = "instance of" (no aliases yet),
  Q1 = "Spike test item". Do not copy Wikidata P-numbers (ontology alignment
  as data, equivalence statements instead).
- Credentials never enter this repo (see Summary).
- Content is authored **on the wiki** (live pages) — never as files in this
  directory.

## Input/Output Expectations

- **Input**: page titles + wikitext — plain for unapproved pages, or with
  `<translate>`/`<tvar>` markup once marking is approved (or entity terms
  via `wikibase-edit-entity`).
- **Marking output**: `{"markfortranslation":{"result":"Success","unitcount":N}}`
  (re-mark) or `{"result":"Success","firstmark":"",...}` (first mark).
- **Verification**: the rendered page shows the `<languages/>` bar with
  "Other languages: English" (only on marked pages).

## Documentation Reference

- `docs/contribution-guide.md` — pointer: the editor guide lives on-wiki at
  the `Help:Contributing` family (migrated 2026-08-19)
- Live wiki pages (authored via MCP, never local files):
  - `[[Help:Contributing]]` — hub for contributors
  - `[[Help:Contributing/access]]` — who can do what, logging in, accounts
  - `[[Help:Contributing/entities]]` — data model, properties, items, statements, merge/delete
  - `[[Help:Contributing/query]]` — SPARQL querying
  - `[[Help:Contributing/house-rules]]` — instance rules
  - `[[Help:Contributing/import]]` — importing from external authorities
  - `[[Help:Contributing/api]]` — API and bulk editing
  - `[[Help:Contributing/styleGuide]]` — writing rules (short sentences, no padding, tables for comparison, show don't tell)
  - `[[Help:Contributing/code]]` — code blocks and syntax highlighting
  - `[[Help:Contributing/richMediaContent]]` — media workflow: uploading and embedding files
  - `[[Help:Contributing/languages]]` — hub: entities/properties + classic wiki pages, two roles
  - `[[Help:Contributing/languages/translationAdmin]]` — marking and managing pages
  - `[[Help:Contributing/languages/translator]]` — translating units
  - `[[Cheatsheets:Main]]` — dev cheatsheets hub (issue #20; `Cheatsheets:` namespace = 2000)
  - `[[Cheatsheets:SPARQL]]` — pilot cheatsheet (same namespace)

## Domain-Specific Rules for Agents

- **Translation markup is opt-in, not default.** Do not add `<languages/>`,
  `<translate>` or `<!--T:n-->` to a page unless the team leader has
  approved marking it (see Translation policy). Unmarked pages are plain
  wikitext; the marker rules below apply only to pages being marked.
- **Per-list-item `<translate>` units inside `<ol><li>` are rejected** —
  error `pt-shake-position: Translation unit markers in unexpected position`.
  Wrap the whole `<ol>` in a single `<translate>` block.
- A unit marker must be followed by a **space or newline** — the parser only
  accepts `<!--T:n--> text` or `<!--T:n-->\n` (`TranslatablePageParser.php`:
  `$rer1 = '~^<!--T:(.*?)-->( |\n)~'`). A marker glued to content
  (`<!--T:n-->text`) fails with `pt-shake-position`.
- **Never use live `<code>` tags on wiki pages — prefer
  `<syntaxhighlight lang="text" inline>`.** `<code>` is raw HTML: it does not
  escape its content (`<` and `&` inside it are read as markup). The code
  guide (`[[Help:Contributing/code]]`) teaches this, so pages must practice
  what the guides preach. Notes: `lang` is required —
  `<syntaxhighlight inline>` without it lands the page in
  Category:Pages with syntax highlighting errors; entities are NOT decoded
  inside `<syntaxhighlight>`, so write raw characters
  (`<syntaxhighlight lang="text" inline><pre></syntaxhighlight>`, not
  `&lt;pre&gt;`).
- **Links inside translatable units — never wrap a whole link in a tvar.** A
  tvar freezes both the target and the label; the label must stay
  translatable (`Translation admin` → `Administrateur de traduction`). Write
  the label in the unit text and link with `[[Special:MyLanguage/Page|Label]]`
  so readers also reach their language version of the target. A tvar around a
  link is acceptable only when its visible text is language-independent: an
  entity ID (`[[Special:EntityPage/Q1]]`), a page name used as a bare label
  (`<tvar name="hub">[[Help:Contributing/languages]]</tvar>`), or a Special
  page name.
- A line starting with a space inside a table cell triggers `<pre>`; use
  `<br/>` to keep single-line cells.
- Re-mark after edits that change the unit structure; re-purge after marking.
