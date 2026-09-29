# Decision: Subpages in every namespace except Main

- **Status**: Accepted (Sep 29 2026)
- **Scope**: `wikibase.ronzz.org` — the `/`-based page hierarchy and the
  breadcrumb link at the top of a subpage
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

MediaWiki's subpage support is **per namespace**
(`$wgNamespacesWithSubpages`): a title containing `/` is a *subpage* only in
a namespace where the flag is set. The flag also drives the **breadcrumb**
(`< Parent/Child` at the top of the page) — but core's
`Skin::subPageSubtitleInternal()` only links an ancestor that **exists**
(`$linkObj->isKnown()`), so a page such as `Cheatsheets:LinuxCLI/ssh` shows
no breadcrumb until the `Cheatsheets:LinuxCLI` page is created.

Until 2026-09-29 the flag was set **namespace by namespace** (Cheatsheets,
HowItWorks, RonzzIT/RonzzInt, FOSS, Person, Source, Collective, Software,
Forum) while Main, File, Category, Item, Property, TimedText and most talk
namespaces stayed flat. This is easy to forget when a namespace is added.

## Decision

1. **Enable subpages in every namespace except Main by setting
   `$wgNamespacesWithSubpages` directly in `LocalSettings.php`** (mirrored in
   the dev/CI config `dev/config/Extensions.php`): `array_fill_keys( range( 0,
   2017 ), true )`, then `NS_MAIN = false`.
   ⚠️ **A late hook cannot do this.** `NamespaceInfo` snapshots the config
   when its service is constructed — `ServiceOptions` copies the option
   values into a private array at construction — so a `CanonicalNamespaces`
   or `SetupAfterCache` hook that mutates `$wgNamespacesWithSubpages` is
   invisible to `hasSubpages()` (verified live 2026-09-29: the hook set the
   global, `hasSubpages(6)` still returned false). The values must exist
   before the service is built. The numeric range covers this instance's
   namespaces (core 0-15, Forum 110, Item/Property 120-123, TimedText
   710-711, Translations 1198-1199, Cheatsheets…Software 2000-2017) and is
   extended when a namespace is registered.
2. **The hierarchy is derived from the title, not stored** — enabling the
   flag does not move pages. The "backfill" for an existing
   `Ns:Parent/Child` page is therefore **creating the missing parent page**
   (and any category hub), not a data migration.
3. **`Cheatsheets:LinuxCLI`** is the first application: a real landing page
   for the three existing `Cheatsheets:LinuxCLI/{FileOps,SysOps,ssh}`
   subpages, plus a `Category:LinuxCLI` (subcategory of `Category:Cheatsheets`).

## Consequences

- Breadcrumbs appear on the existing subpages once the parent page exists;
  no page titles change.
- New namespaces automatically get subpages (no per-namespace line to add).
- `Category:` / `File:` / `Item:` subpages are enabled too — harmless
  (`Item:` titles are `Q…`; the flag only matters for `/` titles).

## References

- `dev/config/Extensions.php` — the `$wgNamespacesWithSubpages` assignment
- core `includes/Config/ServiceOptions.php` — the config snapshot that
  defeats a late hook
- gated wiki `RonzzIT:Deployment/Wikibase` — the production LocalSettings block
- core `includes/Skin/Skin.php` → `subPageSubtitleInternal()`
