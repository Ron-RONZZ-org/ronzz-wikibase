# Decision: multi-author semantic field + source author inference on upload

- **Status**: Accepted (Nov 2026)
- **Scope**: `Special:Upload`, `Special:UpdateFile`, the Add\* image dialog,
  `api.php?action=imagefacts`
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

`Special:Upload`'s "Author (semantic entity)" field accepted one item id,
while `Special:AddSource` already accepted a comma-separated list. When the
author was left blank and a source was given, the image item inherited the
source's `attributed to` statements, but the UI still warned "No author
specified" before the upload.

## Decision

- **Multi-value author**: the shared author entity field is a
  `wb-entity-combobox-multi` (comma/semicolon-separated Q-ids), used by
  `Special:Upload`, `Special:UpdateFile`, the Add\* portrait/logo section and
  the Add-image dialog. Every layer splits/validates the list
  (`ImageUploadHelper`, `ImageItemCreator`, `SpecialUpdateFile`,
  `ApiImageFacts`, `UploadHooks`).
- **Source inference**: when the author list is empty and a source entity is
  given, the image item inherits the source's `attributed to` statements
  (already present in `ImageItemCreator::applyFacts`; preserved through the
  multi-value refactor). This works for create (`Special:Upload`,
  `action=imagefacts`) and update (`Special:UpdateFile`).
- **Warning rule**: the pre-submit warning only adds an author/source warning
  when BOTH are empty (new message `embeddablecontent-upload-author-source`);
  a specified source suppresses it. The license warning is unchanged.

## Consequences

- No vocabulary change; code + i18n only.
- The File page attribution (`{{#image-facts:}}`) shows the inherited authors.
- A plain-text source also suppresses the author warning (the user chose a
  source), but only a semantic source supplies authors to inherit.

## References

- `includes/Upload/ImageUploadHelper.php`
- `includes/Upload/ImageItemCreator.php`
- `includes/Upload/UploadHooks.php`
- `resources/uploadform.js`, `resources/mediaeditor.js`
