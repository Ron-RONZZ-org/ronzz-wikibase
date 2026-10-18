# Decision: Userspace subpage titles

- **Status**: Accepted (Oct 18 2026)
- **Scope**: `wikibase.ronzz.org` — the H1 of `User:<name>/<subpage>` pages
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

A Userspace subpage such as `User:Rongzhou/Pretty good Linux` rendered its
full prefixed title in the H1 (`User:Rongzhou/Pretty good Linux`). The
author is already encoded in the title's root segment, so the namespace +
parent prefix adds noise without adding information.

## Decision

1. **Only `User:` subpages change.** The root user page (`User:Rongzhou`) and
   every other namespace keep their normal title. The breadcrumb
   (`< User:Rongzhou`) stays.
2. **The server sets the text** — `Hooks::applyUserPageTitle` calls
   `OutputPage::setPageTitle()` with `<remainder> <by> @<user>`. `by` is the
   `embeddablecontent-userpagetitle-by` message in the **page language**
   (`by` / `par` / `de`); the browser `<title>` follows. Without JavaScript
   the H1 shows the plain `@Rongzhou` text.
3. **The author is a link** — `resources/userpagetitle.js` rebuilds the H1
   with a real `<a>` to `User:<user>` (`@Rongzhou`). OutputPage's page-title
   sanitizer allows formatting tags but not `<a>`, so this is the only way to
   get a clickable author while keeping a correct server-rendered title.
4. **Nested subpages keep the full remainder** — `User:Rongzhou/A/B` renders
   `A/B by @Rongzhou` (not just `B`), so the hierarchy stays visible.
5. **Article views only** — edit/history/diff/raw keep the real page title
   (`OutputPage::isArticle()` gate).

The styling is the existing title hierarchy: the title keeps the bold H1,
`by @user` is muted and normal weight, and the link uses the skin's
progressive colour. Codex tokens (`--color-subtle`, `--color-progressive`)
cover Vector 2022 day/night; plain fallbacks cover legacy Vector.

## Consequences

- The page's real title, links, history, sitelinks and sitelink search are
  unchanged — only the displayed H1 and `<title>` change.
- `[[User:Rongzhou/Pretty good Linux]]` still links to the page; the page's
  "Copy internal mention" still copies the real title.
- A missing `User:<user>` page renders a red author link (the `new` class),
  matching MediaWiki's link convention.
- No new extension: the logic lives in EmbeddableContent (already loaded on
  every page) with a pure, unit-tested `Spec\UserPageTitle`.

## Testing

- `tests/Unit/Spec/UserPageTitleTest.php` — split/nested/root/empty cases.
- `tests/e2e/run_wiki_ux_e2e.mjs` — the H1 text, the author link target and
  label on a scratch `User:<bot>/…` page.
- `tests/e2e/run_pages_e2e.py` (`flow_print_module`) — the server-side
  `wbUserPageTitle` config, module load and H1 text.
- Live check: `User:Rongzhou/Pretty good Linux` H1 =
  `Pretty good Linux by @Rongzhou`, author link → `/wiki/User:Rongzhou`.

## References

- `extensions/EmbeddableContent/includes/Spec/UserPageTitle.php`
- `extensions/EmbeddableContent/includes/Hooks.php` → `applyUserPageTitle()`
- `extensions/EmbeddableContent/resources/userpagetitle.js` / `.css`
- `extensions/EmbeddableContent/i18n/{en,fr,eo}.json`
- on-wiki `Help:Contributing` (page management) and `Help:Contributing/links`
