# Decision: Upload UX fixes — validate dedupe, infobox images, reuse-existing-file, no-clobber updates

- **Status**: Accepted (Aug 26 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:Upload`, the Add\* portrait/logo
  sections, the Update\* pages, and the classic-page infobox templates
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

User-testing feedback on the upload/Add/Update flows (todo.md batch):

1. **`Special:Upload` validate double-click**: clicking "Validate" repeatedly
   stacked multiple "logo license" confirmation dialogs and kept stale
   auto-filled values (the first fetch's license info was never overwritten).
2. **Infobox images**: `Special:AddSoftware` passes its logo to the FOSS
   infobox, but `Special:AddCollective` (logo) and `Special:AddPerson`
   (portrait) rendered their templates without the image — the uploaded
   file was invisible on the sitelinked classic page.
3. **Reuse an existing file**: two collectives often share one logo (e.g.
   National Geographic the website vs the TV channel). Users wanted to point
   the image field at an already-uploaded `File:` page instead of re-uploading.
4. **UX strings**: the manual-form legend "Create the item manually" is wrong
   when the action is verifying fetched content (the website/webpage URL-first
   flow); the Update\* include toggles did not say the existing image is
   replaced.
5. **Update no-clobber**: the Update\* pages replaced every managed statement
   unconditionally — a field the user left blank REMOVED the existing
   statement.
6. **Default upload source**: user testing showed URL uploads far more common
   than local files, yet the mode radios defaulted to file.

## Decision

### 1. Validate: latest-wins + single confirmation

`resources/uploadmeta.js` gains a per-URL-field **generation counter**
(`validateSeq`): each Validate click bumps it; only the LATEST fetch's
metadata and its async license match may apply (a stale response is
discarded). The confirmation banner is keyed by `data-field` and any earlier
banner for the same license field is removed before the new one renders.
The `licenseInfo` autofill guard ("only when empty") is dropped — the latest
fetch overwrites the auto-filled fields.

### 2. Infobox images for Collective and Person

`SpecialAddCollective::pageSkeleton` passes `|logo=[[File:…]]` and
`SpecialAddPerson::pageSkeleton` passes `|portrait=[[File:…]]` to their
templates (the AddSoftware/FOSS pattern). On-wiki, `Template:Collective` and
`Template:Person` gain the infobox image cell (a `colspan=2` row, matching
`Template:FOSS/Infobox`). These template edits are manual wiki changes and
must be re-applied if the instance is ever rebuilt.

### 3. New image source: reuse an existing file on this wiki

The Add\* portrait/logo **mode radio becomes file | url | existing** with
**no default** — the user picks the source themselves; the file input, URL
field and the new File: search box stay hidden until a mode is selected
(hide-if switched from `=== Mode, X` to `!== Mode, X`).

The `existing` mode reveals a **File: search combobox** (`wb-file-combobox`,
new `resources/fileselect.js` module): autocomplete over the instance's own
File: namespace (`action=query&generator=search&gsrnamespace=6`) with inline
thumbnails (`prop=imageinfo&iiurlwidth=64`) and a 220 px preview on
selection. The submitted value is a `File:<name>` title; the server
(`ImageUploadHelper::reuseExistingFile`) validates the file exists and
records its title — the image/license statements and the infobox parameter
then work exactly as for an upload. The license stays mandatory.

### 4. UX strings

- **Manual legend**: context-aware — the manual form shows "Item details"
  when it is prefilled from fetched URL metadata (the website/webpage
  URL-first flow), "Create the item manually" otherwise.
- **Update\* include toggles**: new per-kind keys —
  "I will upload a new portrait/logo image … (replacing existing)" — used
  only on the Update pages (a message-key hook on the Add\* classes:
  `portraitIncludeMsgKey()` / `logoIncludeMsgKey()`).

### 5. Update no-clobber

`UpdateExternalEntityFlow::applyUpdate` replaces statements **only for
properties with a new non-empty spec**; a blank managed field keeps the
existing statement. A blank description keeps the existing one. Statement
removal is an explicit item-page edit. `baseManagedPropertyIds()` (the old
unconditional removal set) is deleted.

### 6. Upload source default

`Special:Upload`'s core source radio defaults to **Url** on a fresh load
(`UploadHooks::onUploadFormSourceDescriptors` flips the checked flag when no
`wpSourceType` is posted). The Add\* sections deliberately have NO default
(decision 3).

## Consequences

- Repeated Validate clicks are idempotent-looking: one banner, latest values.
- Every sitelinked classic page with an image shows it in the infobox.
- Sharing a logo across items is a two-click pick, not a re-upload.
- Update pages no longer erase data the user did not touch.
- The on-wiki templates (Collective/Person) and Help pages must ship with
  the code; the deploy checklist records the template edits.
