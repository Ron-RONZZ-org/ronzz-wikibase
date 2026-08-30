# Image facts attach to the file, never to the consumer entity

- **Status**: implemented (awaiting deployment), Aug 30 2026
- **Decision**: the logo/portrait `license`, `image author` and
  `additional license information` facts belong to the **file** — its own
  `image`-class item and its File description page — never to the entity
  (collective / person / software) that uses the image.
- **Background**: the Add\* portrait/logo uploads wrote the image facts as
  statements on the CONSUMER entity (`image` + `license` + `imageAuthor` +
  `imageLicenseInfo`), while the File: page showed only "Logo of X." — the
  facts lived nowhere the file was displayed. "Reuse an existing file"
  additionally demanded a fresh license even though the reused file already
  carried one. Production report (Aug 30 2026): `File:` pages lack the
  author/license information; reuse-mode shows fields that are already
  specified on the file.
- **Chosen model** (aligned with the existing Special:Upload
  item-per-upload):
  - every NEW Add\* upload (file/url mode) creates/reuses the sitelinked
    `image`-class item via `ImageItemCreator` — `instance of image`,
    `image` (File: URL), `license`, `image author`, `additional license
    information`, `source URL` — exactly the Special:Upload contract;
  - the File description page text carries the human-readable
    `== License ==` (`[[Q42|label]]`, never a `{{Q42}}` call) and
    `== Attribution ==` blocks (author / additional license information /
    source), mirroring `UploadHooks`;
  - the consumer entity's `statementSpecs` write ONLY the `image`
    statement (a reference to the file);
  - reuse-existing mode hides the license/author/license-info fields and
    needs no license (the file already has its facts); the consumer still
    gets just the `image` statement.
- **Consequences**:
  - existing consumer entities keep their old image-fact statements (no
    migration — removal is an explicit item-page edit); the deploy
    backfills the missing image items + File-page attributions for
    existing Add\* files (`tools/backfill_image_items.py`, dry-run first);
  - the AddSource *access* file is unaffected (a document, not an image —
    its license stays on the source entity where `Special:SourceFile`
    reads it).
- **References**: `extensions/AGENTS.md` (image-facts-semantics batch),
  the Special:Upload item-per-upload (`UploadHooks`/`ImageItemCreator`).
