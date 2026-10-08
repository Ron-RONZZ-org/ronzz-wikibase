# Decision: legal provisions are embeddable ("Copy embed code" on their Source page)

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — `EmbeddableContent` (`Content/ContentRenderer`
  + the classic-page toolbar gadget)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The `law` source class ("legal provision", ADR `addsource-law-class.md`) stores
a clause as monolingual text on the shared `content text` property (+ optional
`translation` claims). The on-wiki `{{#content:}}` parser function already
recognized the class (`ContentPayload::kindOf`) and rendered the clause through
the quotation monolingual path — so a `Source:` page for a provision shows its
text.

The **framed embed** path did not: `Content/ContentRenderer::detectKind()` only
knew the `quotation`/`math`/`code` classes, so `action=embed&entity=<law>`
returned "not embeddable". The classic-page toolbar gadget
(`resources/entityactions.js`) probes `action=embed` to decide whether to show
its **"Copy embed code"** button — the probe failed, so a provision's `Source:`
page had only "Copy internal citation" and "Copy internal mention", never the
quotation-style embed button (the report: "when source type is legislation, add
a 'Copy embed code' button similar to for Quotation").

(Note: an actual `legislation` item carries **no** clause payload, so it cannot
be embedded like a quotation. The pages whose titles end in "*(Legislation)*"
and that render clause text are the legal **provisions** created through
`Special:AddSource/law`.)

## Decision

`ContentRenderer` learns the `law` class, mirroring `ContentPayload`:

- `detectKind()` recognizes `config->lawClass()` (`lawClass()`, the same source
  of truth as the on-wiki renderer);
- `extractPayload()` reads the **quotation** payload property and unions the
  `translation` property (so `lang=fr` / `lang=eo` / `lang=all` work exactly as
  for a quotation);
- `renderKind()` renders through the quotation (rich-wikitext blockquote) path;
- `preview=1` uses the quotation preview (original + reader-language
  translation) but **omits** the `-author, ''source''` attribution line — a
  provision carries no author/source attribution.

With the probe succeeding, the toolbar gadget renders "Copy embed code"
(internal `{{#content:Q…}}` and the external `Special:Embed` iframe flavour)
on the provision's `Source:` page, and in the "Add more" success popup
(ADR `addsource-law-addmore.md`).

## Consequences

- No vocabulary/config/data change; deploy = extension rsync + php-fpm restart
  (embed renders are revision-keyed-cached, so a fresh render is picked up
  immediately; no parser-cache purge is strictly required, but one is harmless).
- The on-wiki `{{#content:}}` and the framed embed surfaces now accept the same
  classes — one less divergence.
- `legislation` (as opposed to the `law` provision class) remains non-embeddable
  (no payload).

## Tests

- E2E (`run_pages_e2e.py`, `flow_source_law_addmore`): `action=embed` on a
  provision returns HTML containing `wb-embed-quotation` with the clause and its
  translation (`lang=all`), and the provision's `Source:` page loads
  `wbEmbedItem` + the gadget module.
- XSS suite unchanged (the provision payload reuses the quotation rich-text
  sanitizer path).
