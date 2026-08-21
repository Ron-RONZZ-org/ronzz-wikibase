<?php
// ronzz-wikibase instance extensions (issue #6, D6).
// WBS convention: this file lives in the /config volume and is loaded by the
// image-generated LocalSettings.php. The seed config map is loaded once the
// seed has emitted it (dev/config/ronzz-wikibase-config.php) — before that,
// the wiki boots with the extensions present but unconfigured.
wfLoadExtension( 'EmbeddableContent' );
wfLoadExtension( 'WikibaseCitation' );

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

if ( file_exists( __DIR__ . '/ronzz-wikibase-config.php' ) ) {
	require_once __DIR__ . '/ronzz-wikibase-config.php';
}
