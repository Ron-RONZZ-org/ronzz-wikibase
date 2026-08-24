<?php
// ronzz-wikibase instance extensions (issue #6, D6).
// WBS convention: this file lives in the /config volume and is loaded by the
// image-generated LocalSettings.php. The seed config map is loaded once the
// seed has emitted it (dev/config/ronzz-wikibase-config.php) — before that,
// the wiki boots with the extensions present but unconfigured.
wfLoadExtension( 'EmbeddableContent' );
wfLoadExtension( 'WikibaseCitation' );

// Discussion forum (DPLforum, vendored at extensions/DPLforum). Registers
// the Forum: (110) / Forum_talk: (111) namespaces via its extension.json.
wfLoadExtension( 'DPLforum' );

// Issue #24 (cite-by-QID): `{{#cite:Q42}}` inside `<ref>` needs the stock
// Cite extension. The WBS image does not bundle Cite — the CI / local stack
// installs it into /var/www/html/extensions/Cite (see .github/workflows/ci.yml
// and dev/README.md). The guard keeps the wiki bootable without it.
if ( is_dir( '/var/www/html/extensions/Cite' ) ) {
	wfLoadExtension( 'Cite' );
}

// Dev-only: surface exception details instead of a bare 500.
$wgShowExceptionDetails = true;

// ---- FOSS namespace (issue #26, FOSS software documentation on-wiki) ----
// Mirrors the production block in LocalSettings.php (see
// RonzzIT:Deployment/Wikibase on the instance).
define( 'NS_FOSS', 2008 ); // free constant id
define( 'NS_FOSS_TALK', 2009 );
$wgExtraNamespaces[NS_FOSS] = 'FOSS';
$wgExtraNamespaces[NS_FOSS_TALK] = 'FOSS_talk';
$wgNamespacesWithSubpages[NS_FOSS] = true; // FOSS:Name/fr static translations
$wgContentNamespaces[] = NS_FOSS;
$wgNamespacesToBeSearchedDefault[NS_FOSS] = true; // custom NS are NOT searched by default

// ---- Person / Source / Collective namespaces (classic pages for items
// created via Special:AddPerson / AddSource / AddCollective) — same pattern
// as FOSS. Mirror the production blocks in LocalSettings.php (see
// RonzzIT:Deployment/Wikibase on the instance).
define( 'NS_PERSON', 2010 ); // free constant id
define( 'NS_PERSON_TALK', 2011 );
$wgExtraNamespaces[NS_PERSON] = 'Person';
$wgExtraNamespaces[NS_PERSON_TALK] = 'Person_talk';
$wgNamespacesWithSubpages[NS_PERSON] = true; // Person:Name/fr static translations
$wgContentNamespaces[] = NS_PERSON;
$wgNamespacesToBeSearchedDefault[NS_PERSON] = true;

define( 'NS_SOURCE', 2012 ); // free constant id
define( 'NS_SOURCE_TALK', 2013 );
$wgExtraNamespaces[NS_SOURCE] = 'Source';
$wgExtraNamespaces[NS_SOURCE_TALK] = 'Source_talk';
$wgNamespacesWithSubpages[NS_SOURCE] = true; // Source:Name/fr static translations
$wgContentNamespaces[] = NS_SOURCE;
$wgNamespacesToBeSearchedDefault[NS_SOURCE] = true;

define( 'NS_COLLECTIVE', 2014 ); // free constant id
define( 'NS_COLLECTIVE_TALK', 2015 );
$wgExtraNamespaces[NS_COLLECTIVE] = 'Collective';
$wgExtraNamespaces[NS_COLLECTIVE_TALK] = 'Collective_talk';
$wgNamespacesWithSubpages[NS_COLLECTIVE] = true; // Collective:Name/fr static translations
$wgContentNamespaces[] = NS_COLLECTIVE;
$wgNamespacesToBeSearchedDefault[NS_COLLECTIVE] = true;

// ---- Forum namespace (DPLforum, discussion forum) ----
// Mirrors the production block in LocalSettings.php (see
// RonzzIT:Deployment/Wikibase on the instance). DPLforum's extension.json
// declares Forum (110) / Forum_talk (111), but namespace constants from
// extension.json are only defined during Setup.php — AFTER LocalSettings.php
// runs — so they must be defined here explicitly (same values; the guard
// matches DPLforum.namespaces.php's own pattern and keeps re-definition
// silent). Custom namespaces are NOT searched by default, so make Forum
// searchable here. No $wgExtraNamespaces entries — the extension registers
// the namespaces itself.
if ( !defined( 'NS_FORUM' ) ) {
	define( 'NS_FORUM', 110 );
	define( 'NS_FORUM_TALK', 111 );
}
$wgContentNamespaces[] = NS_FORUM;
$wgNamespacesToBeSearchedDefault[NS_FORUM] = true;

// ---- Wikibase client (same-wiki) — mirrors production LocalSettings ----
// Without this the client hooks never run: {{#statements:}} renders nothing
// and the wikibase_item page property is never set (issue #30 discovered
// this in CI: Special:AddSoftware's page↔item mapping silently no-op'd).
$wgEnableWikibaseClient = true;
$wgWBRepoSettings['siteLinkGroups'] = [ 'ronzz' ];
$wgWBClientSettings['siteGlobalID'] = 'wikibase';
$wgWBClientSettings['siteLinkGroups'] = [ 'ronzz' ];
$wgWBClientSettings['repoDatabase'] = false;
$wgWBClientSettings['changesDatabase'] = false;

// ---- Entity descriptions up to 2000 chars (issue follow-up) ----
// Mirrors the production LocalSettings.php addition. The shared `multilang`
// string limit covers labels/descriptions/aliases at the STORAGE level; the
// Add* forms keep the label field at 250 (page titles) and raise only the
// description field. Requires the wbt_text.wbx_text column to be widened to
// VARBINARY(2000) (see the CI ALTER step + the runbook deploy sequence).
$wgWBRepoSettings['string-limits']['multilang']['length'] = 2000;

// ---- Issue #35: source files (books etc.) on the upload allow-list ----
// Mirrors the production LocalSettings.php addition (see
// RonzzIT:Deployment/Wikibase on the instance). Needed in dev/CI so the
// page-flow E2E's access-field upload (a PDF fixture) can land.
$wgFileExtensions[] = 'pdf';
$wgFileExtensions[] = 'epub';
$wgFileExtensions[] = 'djvu';

// ---- Issue #35 download-mode access uploads (CI parity with production) ----
// The access field's `download` mode uploads via UploadFromUrl, which needs
// copy-uploads enabled + the upload_by_url right for logged-in users (the
// page-flow E2E's download-mode regression test exercises it). The
// production IsUploadAllowedFromUrl SSRF closure stays production-only — the
// dev sandbox has no external abuse surface.
$wgAllowCopyUploads = true;
$wgCopyUploadsFromSpecialUpload = true;
$wgGroupPermissions['user']['upload_by_url'] = true;
$wgCopyUploadTimeout = 60;

if ( file_exists( __DIR__ . '/ronzz-wikibase-config.php' ) ) {
	require_once __DIR__ . '/ronzz-wikibase-config.php';
}
