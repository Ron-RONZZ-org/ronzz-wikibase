# Instructions for creating and editing wiki content on ronzz-wikibase

(wikibase.ronzz.org) through the mediawiki-mcp-server. All pages referenced
below are **live wiki pages** — edit them via the MCP tools, never by writing
files in this directory.

## Identity and rights (prevent re-discovery)

- The MCP server authenticates as **SeedBot** (user ID 5). Groups: bot,
  bureaucrat, sysop, `*`, user, autoconfirmed.
- **There is no `translationadmin` group** on this wiki. The Translate
  extension's marking right is `pagetranslation`, granted to **sysop**.
  SeedBot is sysop, so it can already mark pages — no group change is needed.
- Bot credentials live outside this repo at
  `~/.config/mediawiki-mcp/ronzz-wikibase.json` (bot password, username
  `SeedBot@MCP`). Never copy credentials into this repo.

## Translation marking workflow

The MCP server has **no marking tool**. 
You should use API module **`action=markfortranslation`** (`MarkForTranslationActionApi`, NOT `action=pagetranslation` as found on some older MediaWiki versions).
Parameters: `title` (or `pageid`), `revid` (the latest page revision), `token`;
optional `prioritylanguages`, `transclusion`, `nofuzzyunits`, `fuzzyunits`.

Steps after creating/updating a translatable page via MCP:

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

## Language policy

- Official languages: **en, fr, eo**, best-effort. Guides must not mention
  other languages (adding languages is deliberately undecided).
- Content should be multilingual:
  - entity terms (labels, descriptions, aliases)
  - wiki pages

## Help:Contributing family (live wiki pages)

- `[[Help:Contributing]]` — hub for contributors
- `[[Help:Contributing/styleGuide]]` — writing rules (short sentences, no padding, tables for comparison, show don't tell)
- `[[Help:Contributing/code]]` — code blocks and syntax highlighting
- `[[Help:Contributing/languages]]` — hub: entities/properties + classic wiki pages, two roles
- `[[Help:Contributing/languages/translationAdmin]]` — marking and managing pages
- `[[Help:Contributing/languages/translator]]` — translating units

## Pitfalls

- **Per-list-item `<translate>` units inside `<ol><li>` are rejected** —
  error `pt-shake-position: Translation unit markers in unexpected position`.
  Wrap the whole `<ol>` in a single `<translate>` block.
- A line starting with a space inside a table cell triggers `<pre>`; use
  `<br/>` to keep single-line cells.
- Every translatable page must start with `<languages/>` and wrap each unit
  in `<translate>` with a `<!--T:n-->` marker; keep markers sequential when
  writing them by hand (the extension renumbers on marking).
- Examples must use real entities: P1 = "instance of" (no aliases yet),
  Q1 = "Spike test item". Do not copy Wikidata P-numbers (ontology alignment
  as data, equivalence statements instead).
