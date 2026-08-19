# AGENTS-translation.md — Translation Instructions (content-creation)

Translation companion to [`AGENTS.md`](AGENTS.md), loaded **only when the
team leader has approved marking a page** for translation. Marking is a rare,
approval-gated event (editorial stabilisation); until it happens, none of the
rules in this file apply — author plain wikitext per `AGENTS.md`.

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
  marked page's unit structure changes (see marking workflow below).
- Rationale: markers make large restructuring cumbersome (marker
  renumbering, re-marking, fuzzy units, translator churn); churn-prone
  content (e.g. cheatsheets) must not carry them until it stabilises.

## Marking rights

- **There is no `translationadmin` group** on this wiki. The Translate
  extension's marking right is `pagetranslation`, granted to **sysop**.
  SeedBot is sysop, so it can already mark pages — no group change is needed.
- The MCP server has **no marking tool**; use the MediaWiki API directly
  (workflow below).

## Translation marking workflow

**Only for pages approved for marking** — i.e. pages that have reached
editorial stabilisation (team leader's call — see
[Translation policy](#translation-policy)).
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

## Markup rules for approved pages

- **Translation markup is opt-in, not default.** Do not add `<languages/>`,
  `<translate>` or `<!--T:n-->` to a page unless the team leader has
  approved marking it (see Translation policy). Unmarked pages are plain
  wikitext.
- An approved page must start with `<languages/>` and wrap each unit in
  `<translate>` with a `<!--T:n-->` marker; keep markers sequential when
  writing them by hand (the extension renumbers on marking).
- **Per-list-item `<translate>` units inside `<ol><li>` are rejected** —
  error `pt-shake-position: Translation unit markers in unexpected position`.
  Wrap the whole `<ol>` in a single `<translate>` block.
- A unit marker must be followed by a **space or newline** — the parser only
  accepts `<!--T:n--> text` or `<!--T:n-->\n` (`TranslatablePageParser.php`:
  `$rer1 = '~^<!--T:(.*?)-->( |\n)~'`). A marker glued to content
  (`<!--T:n-->text`) fails with `pt-shake-position`.
- **Links inside translatable units — never wrap a whole link in a tvar.** A
  tvar freezes both the target and the label; the label must stay
  translatable (`Translation admin` → `Administrateur de traduction`). Write
  the label in the unit text and link with `[[Special:MyLanguage/Page|Label]]`
  so readers also reach their language version of the target. A tvar around a
  link is acceptable only when its visible text is language-independent: an
  entity ID (`[[Special:EntityPage/Q1]]`), a page name used as a bare label
  (`<tvar name="hub">[[Help:Contributing/languages]]</tvar>`), or a Special
  page name.
- Re-mark after edits that change the unit structure; re-purge after marking.

## Input/Output Expectations

- **Input**: page titles + wikitext with `<translate>`/`<tvar>` markup once
  marking is approved (plus the latest `revid` for the marking call).
- **Marking output**: `{"markfortranslation":{"result":"Success","unitcount":N}}`
  (re-mark) or `{"result":"Success","firstmark":"",...}` (first mark).
- **Verification**: the rendered page shows the `<languages/>` bar with
  "Other languages: English" (only on marked pages).

## Documentation Reference

- `[[Help:Contributing/languages]]` — hub: entities/properties + classic wiki
  pages, two roles
- `[[Help:Contributing/languages/translationAdmin]]` — marking and managing
  pages
- `[[Help:Contributing/languages/translator]]` — translating units
