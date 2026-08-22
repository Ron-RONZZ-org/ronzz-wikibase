# AGENTS.md — tools Agent Instructions

## Summary

Developer tooling for ronzz-wikibase: the language-manifest generator
(Pygments-derived language items), the live fetch-layer smoke test, and the
`owui-writer/` deploy kit (Open WebUI wiki writer — MCP endpoint + least-privilege
bot). Small, stdlib-only helpers that feed the D1 manifests, prove the
integration layer end-to-end, or wire the wiki into the LLM writing studio.

## Purpose and Expected Behavior

- **`tools/generate_language_manifest.py`** — dumps the installed Pygments
  lexers (`pygmentize -L lexers`), keeps a curated default subset of
  well-known languages, and writes the human-review manifest
  `extensions/EmbeddableContent/manifests/languages.csv`, which the seed (D2)
  later imports as language *items* (each `instance of` the `programming
  language` class). The renderer's canonical contract is the Pygments lexer
  name; unknown languages fall back to the `text` lexer at render time.
  - `--dry-run` prints the draft; plain run writes the CSV; `--all` dumps
    every installed lexer (583 on Pygments 2.17); `--include` / `--exclude`
    fine-tune the default set.
- **`tools/fetch-smoke.php`** — live smoke test for the fetch layer
  (issue #9): exercises the real `CurlHttpClient` + `ProviderClient` against
  the public endpoints (Wikidata hub + dblp, OpenAlex, Crossref, Open
  Library, ORCID). The unit suite stays mocked; this is the end-to-end proof.
  Run from the repo root in the test image:
  `docker run --rm -v "$PWD":/app -w /app ronzz-wikibase-test php tools/fetch-smoke.php`.
  Makes a handful of polite requests (one run, then stop).
- **`tools/owui-writer/`** — deploy kit for the Open WebUI wiki writer: runs the
  ProfessionalWiki mediawiki-mcp-server as a compose sibling (`mediawiki-mcp-writer`,
  MCP Streamable HTTP at `/mcp`) so Open WebUI can edit wiki pages through a
  least-privilege bot password (`RonzzWikiCowriterAI@Writer`). See
  [`owui-writer/README.md`](owui-writer/README.md) + ADR
  `docs/decisions/owui-wiki-writer.md`. Contains templates only — **no credentials**.

## Constraints and Invariants

- **Python stdlib only** for the generator (argparse, csv, re, subprocess,
  sys) — no pip dependencies.
- The generated CSV is a **human-reviewed artifact**: after generation, edit
  `label.fr` / `label.eo` (labels default to the English human name in every
  language), adjust descriptions where "programming language" is not
  accurate, fill `wikidata_qid` from Wikidata (e.g. Python = Q9296), drop
  unwanted languages — then commit. The committed CSV is the source of truth
  for the seed.
- The smoke test is **live-network** — one polite run only; never point it at
  an endpoint that cannot take it (SSRF allowlist still applies to the
  provider layer).

## Input/Output Expectations

- **Input**: installed Pygments lexers (`pygmentize -L lexers`), the public
  provider endpoints (smoke test).
- **Output**: `languages.csv` (review draft) or per-provider smoke results on
  stdout.
- **Errors**: generator failures surface as non-zero exit with a message;
  smoke failures report which provider/endpoint failed (raise, never
  swallow).

## Documentation Reference

- `extensions/EmbeddableContent/manifests/languages.csv` — the produced
  artifact (committed)
- `seed/README.md` / `seed/AGENTS.md` — how the manifest feeds D2
- `extensions/EmbeddableContent/includes/Fetch/` — the layer the smoke test
  proves
- `docs/decisions/` — ontology alignment rules that constrain manifest
  content

## Domain-Specific Rules for Agents

- Never commit an *unreviewed* generated CSV — the human-review step is part
  of the workflow, not optional.
- Regenerating `languages.csv` must not silently drop languages already on
  the instance (the seed's exact-label skip depends on manifest continuity);
  prefer `--include`/`--exclude` over full rewrites.
- Keep the generator and the smoke test dependency-free — they run in
  minimal environments (test image, plain Python 3).
