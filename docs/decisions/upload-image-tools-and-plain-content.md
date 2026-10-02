# Decision: upload image tools + plain-wikitext `{{#content:}}` + AddMath note

- **Status**: Accepted (Oct 2 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:Upload`, the Add\*/Update\*
  portrait/logo sections, `Special:AddMath`/`Special:UpdateMath`, the
  `{{#content:}}` parser function
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

Three unrelated user reports in one batch:

1. **`{{#content:Q…}}` renders with embed chrome.** On `Electric charge`,
   `{{#content:Q1937}}` (a math item) rendered as
   `<span class="wb-embed wb-embed-math">…</span>` — the `.wb-embed`
   `border-left` showed as a broken coloured line next to the KaTeX output.
   The desired behaviour is **no additional formatting**: expand into regular
   wikitext and let the consumer page format it (e.g. wrap in
   `<blockquote>`).
2. **`Special:AddMath` form/preview.** The "Note" field rendered last (after
   the provenance block) and the live preview showed only the expression,
   never the note.
3. **`Special:Upload`.** (a) no local-file preview ("Source filename" mode),
   (b) no pre-upload resize, (c) the destination filename's extension was
   taken verbatim, so a mis-named file (a PNG saved as `.jpg`) drew a
   `filetype-mime-mismatch` error.

## Decision

### 1. `{{#content:}}` expands to regular wikitext (no embed chrome)

`ParserFunctions/ContentPayload` now returns wikitext with
`noparse => false, isHTML => false` (the default is `noparse => true`, which
would render links/`[[File:…]]` literally) and no longer loads the
`ext.embeddableContent.embed` module:

- **quotation** → its decoded wikitext (the consumer wraps it in
  `<blockquote>` if desired);
- **math** → a display `$$…$$` span (rendered by the instance's
  **SimpleMathJax**, like every other formula on the page) + the note
  wikitext after a blank line — `{{#content:Q…|noNote}}` still suppresses
  the note;
- **code** → a stock `<syntaxhighlight lang="…">…</syntaxhighlight>` block
  (escaped `<pre>` fallback when the body carries a literal closing tag).

`Content/ContentWikitext` holds the pure string assembly (unit-tested). The
**embed surfaces** (`Special:Embed`, `api.php?action=embed`,
`Special:QuotationsOf`) keep their framed `.wb-embed` rendering — only the
on-wiki parser function is unformatted.

### 2. AddMath: note below Content, preview shows both

`SpecialAddContentItem::buildFields()` inserts the `note` field directly
after the `payload` field (Add + Update share it). The live preview
(`resources/addmath.js`) renders the KaTeX expression and, below it, the
note's text with inline `$…$` typeset by the same KaTeX.

### 3. Upload: local preview + client-side resize + extension auto-correct

No maintained MediaWiki extension performs **pre-upload** image resizing
(mediawiki.org search: only server-side thumbnail handlers), so the resize
is a small **client-side Canvas** implementation — no new dependency,
consistent with the extension's "vendored assets, no Node sidecar" model.

`resources/uploadimage.js` (new shared module; Special:Upload + the Add\*/
Update\* portrait/logo sections, discovered from the file field's name):

- **Local-file preview** — thumbnail (or file-type badge) + pixel/byte size,
  reusing the `.wb-uploadmeta-preview` styling. Previously only the URL
  mode previewed.
- **Resize** — `createImageBitmap` + canvas, downscale-only to a 2000 px
  longest edge (EXIF-orientation aware); JPEG/PNG/WebP only, SVG/GIF left
  untouched; re-encodes to the blob's actual type. Checkbox default **ON**.
- **Auto-correct extension** — checkbox default **ON**: the file's extension
  follows its MIME type. Add\* renames the input file and threads a
  `preferMime` flag into `ImageUploadHelper::destName()`; Special:Upload
  corrects `wpDestFile` client-side and, as a server-side net,
  `UploadHooks::onUploadForm_BeforeProcessing` (`UploadForm:BeforeProcessing`)
  relabels the destination from the sniffed MIME before verification
  (covers JS-off).

New i18n keys (en/fr/eo): `embeddablecontent-upload-resize` /
`-autoext` / `-resizing`.

## Consequences

- **`{{#content:}}` output changes** — existing pages lose the automatic
  blockquote/KaTeX chrome and render the raw wikitext (few usages:
  `Electric charge`, `Differential equation`). This is the requested
  behaviour. Embed surfaces are unchanged.
- **No vocabulary / seed / config-map change** — a deploy is an extension
  rsync + `php-fpm` restart + a parser-cache purge (`{{#content:}}` output
  changed).
- The resize/auto-correct options apply to `Special:Upload` **and** the
  Add\*/Update\* portrait/logo sections.
- Regression coverage: `tests/Unit/ContentWikitextTest`,
  `tests/Unit/Upload/ImageUploadHelperTest` (prefer-MIME + field specs),
  `run_e2e.py rich` (no `.wb-embed` from `{{#content:}}`),
  `run_pages_e2e.py` §5c (plain-wikitext math decoder),
  `run_addmath_ux_e2e.mjs` (note position + note-in-preview),
  `run_wiki_ux_e2e.mjs` (options default ON + local preview + resize +
  auto-correct dest extension).
