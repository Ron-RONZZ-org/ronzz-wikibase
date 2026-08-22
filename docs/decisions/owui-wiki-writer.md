# Decision: Open WebUI as the LLM-assisted wiki writer studio (MCP endpoint + least-privilege bot)

- **Status**: Accepted (Aug 21 2026) — implementation started
- **Update (Aug 22 2026)**: writer account renamed `OpenWebuiWriter` → `RonzzWikiCowriterAI`
  (bot password `RonzzWikiCowriterAI@Writer`, same grants) via core `renameUser.php` —
  bot-password login + MCP tool calls verified under the new name
- **Scope**: `llm.ronzz.org` (Open WebUI) ↔ `wikibase.ronzz.org` integration — LLM-assisted
  wiki article writing (research → outline → draft → iterate → human review) with
  section-targeted rewrites and BYOK (bring-your-own-key) providers
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

New user need (2026-08-21): write articles for wikibase.ronzz.org with LLM assistance —

1. **flag a section** and give feedback for the LLM to rewrite that section specifically,
2. a **staged content-creation workflow** (research → outline → draft → iteration → final
   human review),
3. **provider-agnostic, BYOK**.

Existing deck (per `RonzzIT:Deployment/OpenWebUI` · `RonzzIT:Runbook/OpenWebUI`, `RonzzIT:Deployment/Wikibase`):

- Open WebUI v0.11.0 at llm.ronzz.org, OIDC via Nextcloud H2CK/oidc, **no LLM connected
  yet**; native MCP support is **Streamable HTTP only** (since v0.6.31); MCP servers are
  admin-only (Admin → Integrations).
- mediawiki-mcp-server (ProfessionalWiki) — already used by opencode via stdio with the
  `SeedBot@MCP` bot password; current release v0.17.0; supports `MCP_TRANSPORT=http`
  (Streamable HTTP at the fixed `/mcp` path). The HTTP transport **refuses static
  credentials unless `MCP_ALLOW_STATIC_FALLBACK=true`**.
- The wiki has **no Extension:OAuth** — authentication is via bot passwords.
- Content conventions codified in repo `content-creation/AGENTS.md` (+ the static
  translation ADR): style guide, preservation rules, verify-after-edit, `latestId`
  conflict discipline.
- Section semantics are native MediaWiki: `get-page section=N`, `update-page section=N`.

## Decision

1. **Open WebUI is the writing studio.** Users connect their own provider key (BYOK) or use
   an org-level connection; a "Wiki Writer" model carries the style-guide + workflow prompt.
2. **Expose the wiki as a native MCP tool server (Streamable HTTP).** Run the existing
   mediawiki-mcp-server as a compose sibling `mediawiki-mcp-writer`
   (`MCP_TRANSPORT=http`, bound to the compose network only), registered in
   Open WebUI Admin → Integrations as an MCP server at `http://mediawiki-mcp-writer:8080/mcp`.
   **No MCPO proxy** — Open WebUI speaks Streamable HTTP natively (v0.6.31+) and the MCP
   server does too; MCPO exists to bridge stdio/SSE, which we do not need.
3. **Dedicated least-privilege writer identity.** New plain wiki user `RonzzWikiCowriterAI`
   (no groups) + bot password `RonzzWikiCowriterAI@Writer` with grants
   `basic, createeditmovepage, editpage, uploadeditmovefile` — section edits, create/move
   of drafts, image uploads; **not** delete/undelete, rights, patrol, pagetranslation.
   (MW 1.46 valid grant name is `createeditmovepage` — not the legacy `createeditmovefile`.)
   The HTTP endpoint runs with static credentials + `MCP_ALLOW_STATIC_FALLBACK=true`
   (accepted shared-identity deployment — endpoint stays internal on the compose network).
4. **Workflow.** Staged pipeline with human checkpoints; the section-rewrite loop is
   `get-page section=N` → rewrite against feedback + style/preservation rules →
   `parse-wikitext` preview → human approves → `update-page section=N` with `latestId`
   (conflict-guarded). Drafts stage on `/draft` subpages; edits carry an
   "AI-assisted (Open WebUI)" summary.

## Why

- **Policy fit** (RonzzInt:Policies/software): reuse the existing deck — an extension of
  deployed software, not a new product. Solution S2 of the 2026-08-21 solution analysis.
  MCPO from the runbook note is unnecessary because both sides speak Streamable HTTP.
- **Capability containment**: even a prompt-injected writer session cannot delete, block,
  or change rights — MediaWiki enforces at the permission layer, not the prompt. SeedBot is
  sysop/bureaucrat; running an LLM-facing tool on that identity is an unacceptable blast
  radius.
- **House precedent**: SeedBot@MCP was already a restricted-grant bot password; the writer
  bot extends the pattern to a plain, non-privileged account.
- **BYOK**: Open WebUI per-user API keys make the provider the user's choice
  (OpenAI/Anthropic/Gemini/OpenRouter/… any OpenAI-compatible endpoint).

## Consequences

- Every OWUI-initiated edit is attributed to `RonzzWikiCowriterAI` (audit via page history +
  edit summary).
- **Shared identity is acceptable for ≤2–3 editors.** Revisit triggers: a second human
  editor wants their own attribution, or Extension:OAuth lands on the wiki (then the MCP
  server's hosted-OAuth path gives per-user attribution without shared credentials).
- The MCP endpoint must stay internal (compose network); do not publish it without a
  security re-review.
- The wiki remains the single source of truth; the writer MCP can never exceed the
  bot-password grant set.
- `SeedBot@MCP` stays unchanged for opencode/maintenance work.

## Alternatives considered

| Option | Assessment |
|--------|-----------|
| **MCPO → OpenAPI tool server** | Rejected for now — extra component; OWUI + our MCP server both speak Streamable HTTP natively. Revisit if the OWUI MCP integration misbehaves |
| **stdio MCP inside the OWUI container** | Rejected — node/npx + credentials inside the container; fragile and an anti-pattern |
| **Adopt LibreChat** | Rejected for now — duplicates Open WebUI's role; policy says reuse the deck first (S5 in the analysis, kept as fallback) |
| **Custom wiki extension (AI section editor)** | Deferred — wiki-native diff/approve UX if the chat diff UX proves insufficient (S3 in the analysis) |
| **SeedBot as the tool identity** | Rejected — sysop blast radius too large for an LLM-facing tool |

## References

- Deploy kit: `tools/owui-writer/` (compose fragment, config example, runbook) — this repo
- Runbook (on-wiki): `RonzzIT:Runbook/WikiWriterMCP` (ops) · `RonzzIT:Deployment/WikiWriterMCP` (facts)
- Open WebUI MCP docs (Streamable HTTP only, admin-gated) — https://docs.openwebui.com/features/extensibility/mcp/
- MCP server deployment docs (HTTP transport, static-credential fallback) —
  https://github.com/ProfessionalWiki/MediaWiki-MCP-Server/blob/master/docs/deployment.md
- ronzz.ORG software policy — https://wikibase.ronzz.org/wiki/RonzzInt:Policies/software
