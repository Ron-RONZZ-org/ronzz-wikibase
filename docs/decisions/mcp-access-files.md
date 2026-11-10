# Decision: machine clients attach access files through `action=addsource`

- **Status**: Accepted (Nov 2026)
- **Scope**: `action=addsource` / `action=accessupload`, the MediaWiki MCP
  `embeddable-add-citation-source` tool, Open WebUI's chat-attachment bridge,
  the Veramiko Bibliographer and Cowriter prompts
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The multi-access editor (`docs/decisions/access-file-entities.md`) stores one
`access file` item per `File:` page and links it from the source with
`has access file` + `access status`/`language` qualifiers. A copyright-
restricted copy additionally needs the internal-use marker
(`docs/decisions/internal-use-files.md`).

All of that lives in the **browser forms**: `accessEntries` is a deliberate
internal replace-list field (`SourceFlowService::INTERNAL_FIELDS`) and is not
part of the published field contract. An API/MCP client could therefore not
create a source with an access file at all. The Veramiko Bibliographer's
report — "cannot upload the user's PDF as the access file when creating the
Source" — was correct at three layers: its `meta.toolFilter` omitted the
upload tools, Open WebUI never exposed the chat attachment's stored path, and
`action=addsource` had no access-file field.

Reusing `accessEntries` for a machine path is unsafe: on update,
`managedPropertyIds()` marks every access property and `applyUpdate()` deletes
all access statements before re-adding the supplied list. A tool call that
attaches ONE PDF would erase every other access entry.

## Decision

1. **`action=addsource` gains an append-only `accessFile` field** (JSON object
   string): `{ "fileTitle": "Foo.pdf", "internal": true, "license": "Q…",
   "language": "en" }`. It is normalized by the pure `Spec/AccessFileRequest`
   and handled by the MW-bound `ApiAddSource` after the item exists: resolve
   the access-file item (`AccessEntries::resolveFileItems`), apply or clear the
   internal-use marker (`applyFileMarkers`), and append one
   `has access file` statement (`addStatements`). It never touches existing
   access entries, and a file already linked is a no-op (`alreadyLinked: true`).
2. **Internal is the default shape, public needs an explicit licence.** An
   internal entry drops any supplied licence (the seeded "All rights
   reserved" is recorded by `resolveFileItems`); a public entry requires a
   `Q…` licence item.
3. **The field is universal except `law`** (a provision inherits its
   legislation's access copy), and it joins `SourceFieldMap::ALL_FIELDS` +
   the canonical contract. The MCP server regenerates its tool schema from the
   contract; `accessEntries` stays internal and replace-list.
4. **The MCP tool stays one tool.** `embeddable-add-citation-source` gains
   `accessFile { filepath, internal, license?, language? }` and runs the
   browser flow's own sequence: create the source (metadata) → `accessupload`
   (`mode=file`, the returned label names the file) → attach with a `qid`
   update. A failed attach after the upload reports `partial` with the `File:`
   title; it never claims success.
5. **Open WebUI exposes the stored path, not the bytes.** A new read-only
   `list_chat_files` builtin reuses the artifact pipeline's
   `resolve_attachment_paths` (PR #10) to return each conversation file's
   `{id, name, path, content_type}` where `path` is the OWUI-store path
   (`/app/backend/data/uploads/<id>_<name>`) — already mounted read-only in
   the writer container and already listed in its `uploadDirs`. No staging,
   no copy, no kernel.

## Consequences

- A machine client can attach a PDF/EPUB to an existing source in one tool
  call (three HTTP calls internally), with the wiki owning validation,
  statement building, the access-file item and the media gate.
- The internal-use marker is applied by the server on the attach call, not by
  the upload alone — a public/licence mix-up cannot silently publish a
  restricted copy.
- `action=addsource`'s create path attaches before the first save, so a
  rejected file leaves no source behind. `resolveFileItems` may create the
  access-file item first; that item is sitelinked and reused on retry.
- The append-only field is the ONLY machine path; the browser form keeps its
  replace-list editor. The two cannot drift because both use `AccessEntries`.
- A mixed-class item is possible when the `File:` page already has a generic
  `image` item: `AccessFileItemCreator` reuses the sitelinked item and adds
  `instance of access file`. The access tool is the intended first upload for
  access copies.
- Attribution is the writer bot identity, as with every MCP write.

## References

- `extensions/EmbeddableContent/includes/Spec/AccessFileRequest.php`
- `extensions/EmbeddableContent/includes/Api/ApiAddSource.php`
- `extensions/EmbeddableContent/includes/Flow/SourceFieldMap.php`
- `extensions/EmbeddableContent/includes/Spec/AccessEntries.php`
- `tests/Unit/AccessFileRequestTest.php`,
  `tests/e2e/run_pages_e2e.py::flow_api_access_file`
- MCP: `src/tools/extensions/embeddable-content/embeddable-add-citation-source.ts`
- OWUI fork: `backend/open_webui/tools/builtin.py` (`list_chat_files`)
- Wiki: `RonzzIT:Runbook/WikiWriterMCP`, `RonzzIT:Runbook/OpenWebUI`
