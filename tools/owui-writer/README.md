# owui-writer — Open WebUI wiki writer (MCP endpoint + least-privilege bot)

Deploy kit for the **LLM-assisted wiki writing** integration: Open WebUI (llm.ronzz.org)
gains the wiki (wikibase.ronzz.org) as a **native MCP tool server** so a "Wiki Writer"
model can research, outline, draft and rewrite wiki sections — with human approval at every
write. Decision record: [`docs/decisions/owui-wiki-writer.md`](../../docs/decisions/owui-wiki-writer.md).

```
Editor → llm.ronzz.org (Open WebUI, OIDC) ──tools──▶ mediawiki-mcp-writer (compose sibling,
        "Wiki Writer" model, BYOK key        MCP Streamable HTTP http://mediawiki-mcp-writer:8080/mcp)
                                                          │
                                                          ▼
                                              wikibase.ronzz.org/api.php
                                              (identity: RonzzWikiCowriterAI@Writer bot password)
```

No MCPO, no OpenAPI tool server: Open WebUI speaks MCP **Streamable HTTP** natively (v0.6.31+)
and the ProfessionalWiki mediawiki-mcp-server does too (fixed `/mcp` path, `MCP_TRANSPORT=http`).

## Files

| File | Purpose |
|------|---------|
| `docker-compose.mcp-writer.yml` | compose fragment — merge into `/opt/openwebui/docker-compose.yml` |
| `ronzz-wikibase-writer.json.example` | MCP config template — copy to the server, fill the bot password (never commit secrets) |

## Manual steps (server, ronzz-linux-server-2)

### 1. Create the wiki account (registration is closed)

As admin (Rongzhou): `Special:CreateAccount` on wikibase.ronzz.org → user `RonzzWikiCowriterAI`
(plain user, no groups). Alternative, server-side:

```bash
sudo -u ronzz php /var/www/wikibase/maintenance/run.php createAndPromote --username=RonzzWikiCowriterAI --force
```

### 2. Create the bot password (least privilege)

`Special:BotPasswords` as `RonzzWikiCowriterAI` → "Writer" with grants:

```
basic, createeditmovepage, editpage, uploadeditmovefile
```

(`basic` is automatic. This allows: edit existing pages, create/move pages, upload/replace
files. It does **not** allow: delete/undelete, rights management, patrol, pagetranslation —
exactly the MCP tools the writer must not run. MediaWiki enforces this server-side.)

> **Grant-name gotcha (MW 1.46)**: the valid name is `createeditmovepage`, NOT
> `createeditmovefile` — the old name fails with "These grants are invalid". Verify with
> `createBotPassword --showgrants`. Server-side alternative:
> `sudo -u ronzz php /var/www/wikibase/maintenance/run.php createBotPassword --appid Writer --grants basic,createeditmovepage,editpage,uploadeditmovefile RonzzWikiCowriterAI`

Note the generated `<username>@<botname>` (`RonzzWikiCowriterAI@Writer`) + password.

### 3. Write the MCP config on the server

```bash
sudo mkdir -p /opt/openwebui/config
sudo install -o ubuntu -m 600 ronzz-wikibase-writer.json.example /opt/openwebui/config/mediawiki-mcp-writer.json
# then edit /opt/openwebui/config/mediawiki-mcp-writer.json → set "password" (bot password)
```

**Permission gotcha**: the container runs as `nodejs` (uid 100, gid 101). A plain `0600`
file owned by ubuntu (uid 1001) makes the container crash with `EACCES: permission denied,
open 'config.json'`. Make it group-readable by the container's gid instead:

```bash
sudo chgrp 101 /opt/openwebui/config/mediawiki-mcp-writer.json && sudo chmod 640 /opt/openwebui/config/mediawiki-mcp-writer.json
```

Credentials live on the server (owner ubuntu, group 101, mode 640 — on this host gid 101
maps to the `lxd` group, whose only member is ubuntu) — **never on the wiki, never in this repo**.

### 4. Merge the compose fragment

Append the `mediawiki-mcp-writer` service from `docker-compose.mcp-writer.yml` to
`/opt/openwebui/docker-compose.yml`, then:

```bash
cd /opt/openwebui && docker compose up -d && docker compose ps
```

No host ports are published — the endpoint is reachable only from the compose network
(`http://mediawiki-mcp-writer:8080/mcp`).

### 5. Register in Open WebUI (admin)

1. **Provider (BYOK)**: users add their own key (user settings → API keys), or admin adds an
   org connection in Admin → Settings → Connections. Provider-agnostic — any OpenAI-compatible endpoint.
2. **MCP server**: Admin → Integrations → add MCP server, Type **MCP (Streamable HTTP)**,
   URL `http://mediawiki-mcp-writer:8080/mcp`. Verify the wiki tools (get-page, update-page,
   parse-wikitext, wikibase-query, …) appear.
3. **Model**: Admin → Settings → Models → "Wiki Writer" (base = user's provider), system
   prompt below.

### 6. "Wiki Writer" system prompt

```
You are "Wiki Writer", an editor assistant for wikibase.ronzz.org. You create and edit wiki
pages through the MediaWiki tools (get-page, update-page, create-page, parse-wikitext,
search-page-by-prefix, wikibase-query, ...).

Rules:
1. Follow the on-wiki style guide:
   https://wikibase.ronzz.org/wiki/Help:Contributing/styleGuide
   Reference sheets (Cheatsheets:) and dissections (HowItWorks:) follow their own namespace
   conventions (see https://wikibase.ronzz.org/wiki/Category:HowItWorks).
2. Preservation: keep [[links]], entity IDs (Q/P), URLs, <syntaxhighlight>/<pre>/<code>
   blocks, table structure and template calls verbatim; translate visible prose only.
3. Verify before claiming: run queries live; after every edit confirm rendering with
   parse-wikitext.
4. Never clobber: fetch the current page and its latestId immediately before any
   update-page; use section=N for section-scoped edits; on edit conflict, rebase onto the
   current source, never overwrite.
5. Workflow: research → outline → user approval → draft (or a /draft subpage) → user review
   → iterate per-section on feedback → final preview → apply. Get explicit user approval
   before every write; show proposed wikitext / previews in chat first.
6. Edit summaries: "AI-assisted (Open WebUI)".
```

### 7. Verify

- Read drill: ask the model to `get-page` a known page and summarize it.
- Section drill (on a scratch page, e.g. `User:Rongzhou/scratch`): flag a section, request a
  rewrite, confirm the model shows a preview, approve, then check the page history shows the
  edit under `RonzzWikiCowriterAI` with the AI summary.
- Conflict drill: edit the page in another tab between preview and approval — the applied
  write must fail/rebase, not clobber.

## Rollback

1. Open WebUI: remove the MCP server (Admin → Integrations).
2. Server: remove the `mediawiki-mcp-writer` service from `/opt/openwebui/docker-compose.yml` → `docker compose up -d`.
3. Wiki: revoke the bot password (`Special:BotPasswords`); delete the account if unused.

## Day-2 notes

- Pin the MCP image to a released tag (check `gh api repos/ProfessionalWiki/MediaWiki-MCP-Server/releases/latest`); cosign-verify per the server docs before bumping.
- Deployment log entries: `logs/openwebui.md` (Nextcloud docs/IT).
- If a second human editor needs their own attribution, the revisit triggers are in the ADR
  (per-human bot passwords, or Extension:OAuth + the MCP server's hosted-OAuth path).

### 6b. "Wiki Writer" system prompt — semantic-content delta (2026-08-30, fork deployed)

The 2026-08-21 prompt (§6) is page-editing-only. With the fork (embeddable-* /
citation-* / wikibase-setsitelink) the model should also create and use semantic
entities. Add the following block (merge into the existing prompt; the opening
"responsibility" line should also mention creating semantic entities):

```
## Semantic content (Wikibase entities)

The wiki is entity-driven. Beyond pages, create semantic entities with the
embeddable-add-* tools: quotations, math, code snippets, citable sources
(book/article/website/...), persons, collectives. Then make pages use them:

- Before creating any entity, search first (wikibase-search-entities) — entities
  are shared, never duplicate.
- Call embeddable-describe-entity-type for the kind you need: field tables,
  resolved property IDs, and a ready-to-submit example.
- Multi-line content (code, math, quotation text) is stored backslash-escaped and
  decoded at render time. On pages, {{#content:Qxx}} shows the decoded payload;
  {{#statements:Px|from=Qxx}} shows the raw stored form.
- Cite sources inline with {{#cite:Qxx}} inside <ref> and collect with
  <references/>; {{#citations:}} auto-collects cited/embedded sources.
- Embed content at Special:Embed/Qxx (the iframe snippet is for third-party sites;
  on-wiki use the bare URL or {{#content:}}).
- Link a page to its item with wikibase-setsitelink so the short forms
  ({{#statements:P1}}, {{#item-image:}}) resolve.
- Model reference: Help:Contributing/{entities,citations,semanticDynamicContent,
  math,code}.
```

Task-prompt template for one-off chats (no system change needed — discovery is
self-service):

```
On wikibase.ronzz.org, create a <kind> entity for <X> (search for duplicates
first), then on <page> cite it inline, embed its content, and sitelink the page
to its item. Verify the created entities and the rendered page.
```
