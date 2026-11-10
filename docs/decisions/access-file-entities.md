# Decision: access files are semantic entities; per-access language

- **Status**: Accepted (Nov 2026)
- **Scope**: `Special:AddSource` / `Special:UpdateSource`, `{{#source-access:}}`,
  `Special:SourceFile`, the seed vocabulary
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The multi-access editor stored an uploaded file as a `file` URL statement on
the Source item, with the license as a qualifier. That made the file's
license invisible to the file's own `File:` page and to other works using the
same file. Access entries also had no language, so a multi-format source
could not render `PDF: en|fr`.

## Decision

1. **One item per access file.** A new class `access file` + property
   `has access file` (item-valued). The file's item is sitelinked to its
   `File:` page and carries `instance of access file`, `file` (File: URL) and
   `license`; the source links to it through `has access file`.
2. **The source statement keeps only the relationship facts**: `access status`
   (public/login) and `language` qualifiers. The `file` URL statement remains
   readable for legacy items; `AccessEntries::fromItem` deduplicates the two
   shapes by file title.
3. **Language per access entry**: a BCP-47 code (ISO 639 catalog), defaulting
   to the form's primary source language. `{{#source-access:}}` groups file
   entries by extension and renders `PDF: en|fr` (each code links to its
   `Special:SourceFile`); URL entries render `url1: en`.
4. **License combobox**: the access-dialog license is the classic semantic
   entity combobox (known license list + entity search), not a dropdown.
5. **File page facts**: new access-file pages append `{{#image-facts:}}`, so
   the license stored on the file item renders on the `File:` page.
6. **Backfill**: `tools/backfill_access_files.py` creates the file item +
   `has access file` link for legacy `file` statements (dry-run by default).

## Consequences

- Re-seed required (new property + class + `hasAccessFile`/`accessFileClasses`
  config keys). Code deployed before the seed falls back to the legacy `file`
  URL statement.
- `Special:SourceFile` reads the license through `AccessEntries::fromItem`
  (the file item).
- Legacy `file` statements are left in place by the backfill (the read path
  dedupes); removing them is an explicit item edit.
- The editor labels the login-gated status **"internal"** on the added-entry
  card (storage stays `access status = login`), matching the internal-use file
  marker. The access dialog's help icons sit after their field labels so the
  dialog scrollbar never covers them.

## References

- `includes/Upload/AccessFileItemCreator.php`
- `includes/Spec/AccessEntries.php`
- `includes/Content/SourceAccessRenderer.php`
- `tools/backfill_access_files.py`
