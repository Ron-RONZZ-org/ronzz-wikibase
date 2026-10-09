# Decision: URL query-param prefill for the Add* / Update* forms

- **Status**: Accepted (Oct 2026)
- **Scope**: `wikibase.ronzz.org` — every `Special:Add*` / `Special:Update*`
  browser form (the GUI counterpart of the write API modules)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The Add* flows are exposed as write API modules for machine clients
(`action=addsource` / `addspecialcontent` / `addsemanticentity`, the MCP
`embeddable-*` tools), but the BESPOKE browser forms could only be filled by
hand. There was no way to hand a contributor a link that opens the form with
values already in place — the "Add more" carry was the sole exception, and it
was gated on `addmore=1` with a fixed field list. Deep-linking a prefilled
form makes the GUI reachable as a "web API": any tool that can build a URL can
hand over a prepared form.

## Decision

A pure helper `Spec/RequestPrefill::apply( $fields, $query )` sets a form
field's `default` from an identically-named request query parameter:

```
Special:AddSource/law/manual?parent=Q2048&referenceCode=Article%209
```

opens the legal-provision form with the parent and reference code prefilled.

Rules:

- **Field name = query key.** The Add* / Update* form field names (`parent`,
  `referenceCode`, `title`, `language`, …) are the query-param names.
- **Data fields only.** `hidden`, `submit`, `button`, `info`, `html` and
  `cloner` fields are never prefilled (server-owned markers, controls,
  array-valued cloner); a non-scalar value is ignored.
- **The URL wins over the builder default**, but only when the value is
  non-empty (so an absent param keeps the form's own default).
- **POST is unaffected.** HTMLForm's action URL carries no query on POST (the
  submitted fields arrive `wp`-prefixed), so a normal submit never re-applies
  the URL. The prefilled values are re-validated on submit (`beforeCreate` /
  the flow service) — prefill is display-only and opens no write surface.

Applied at every Add* / Update* form assembly point:
`SpecialAddExternalEntity` (`executeReview`, `executeContent`,
`executeContent`-manual, `executeManual`), `SpecialAddSource`
(`executeUrlEntry`, the class picker) and `SpecialAddContentItem::buildFields`;
`UpdateExternalEntityFlow::execute` applies it to the Update* forms too.

## Consequences

- Pure helper (`tests/Unit/Spec/RequestPrefillTest`), no vocabulary / config /
  seed change. Deploy = extension rsync + php-fpm restart.
- The "Add more" carry (`SpecialAddContentItem::carryOverParams`,
  `SpecialAddExternalEntity` addmore hooks) stays: it additionally resets the
  label/payload, which a generic prefill must not do.
- Search-form and `Special:Upload` prefill are out of scope (they carry no
  Add* record field); they can adopt `RequestPrefill` later.

## References

- `extensions/EmbeddableContent/includes/Spec/RequestPrefill.php`
- `extensions/EmbeddableContent/includes/Spec/SpecialAddExternalEntity.php`
  (`applyRequestPrefill`), `SpecialAddSource.php`, `SpecialAddContentItem.php`,
  `UpdateExternalEntityFlow.php`
- `tests/Unit/Spec/RequestPrefillTest.php`,
  `tests/e2e/run_pages_e2e.py` (`flow_source_law_addmore` GET-prefill check)
