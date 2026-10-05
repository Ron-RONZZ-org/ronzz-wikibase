# EmbeddableContent field contract

`field-contract.json` is the canonical, machine-readable field contract of the
entity-mode Add\* flows (quotation/math/code-snippet, citation sources,
semantic entities). It is a **projection** of the single authoring source — the
`Flow/SpecialContentFieldMap`, `Flow/SourceFieldMap` and
`Flow/SemanticEntityFieldMap` classes — assembled by `Flow/FieldContract` and
written by `maintenance/emitFieldContract.php` (a pure PHP script; the maps
carry no MediaWiki runtime dependency).

Downstream clients consume this artifact instead of re-encoding the field
lists: the MediaWiki MCP server generates its embeddable add tools' input
schemas from a pinned copy. That is what stops the drift where a field is
advertised by an `action=*-fields` endpoint (or a `Special:Add*` form) but the
write module or the client silently drops it.

## Regenerate

Run after ANY change to a `Flow/*FieldMap` class, and commit the result:

```sh
php extensions/EmbeddableContent/maintenance/emitFieldContract.php
```

`tests/Unit/FieldContractTest` fails if the committed file is not the exact
projection of the maps.

## Who reads it

- The API modules derive their accepted params from the maps
  (`ApiAdd*::fieldParams()`), never a hardcoded copy:
  `ApiAddSource` and `ApiAddSpecialContent` return `*FieldMap::ALL_FIELDS`;
  `ApiAddSemanticEntity` returns `SemanticEntityFieldMap::apiParamFields()`
  (`ALL_FIELDS` minus the typed `pageKind`).
- The `action=*-fields` discovery endpoints report the same maps.
- The MediaWiki MCP server (`Ron-RONZZ-org/mediawiki-mcp-server`) pins a
  verbatim copy at
  `src/tools/extensions/embeddable-content/contract/field-contract.json` and
  generates its tool schemas with `npm run gen:field-contract`.
