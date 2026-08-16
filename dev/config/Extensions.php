<?php
// ronzz-wikibase instance extensions (issue #6, D6).
// WBS convention: this file lives in the /config volume and is loaded by the
// image-generated LocalSettings.php. The seed config map is loaded once the
// seed has emitted it (dev/config/ronzz-wikibase-config.php) — before that,
// the wiki boots with the extensions present but unconfigured.
wfLoadExtension( 'EmbeddableContent' );
wfLoadExtension( 'WikibaseCitation' );

if ( file_exists( __DIR__ . '/ronzz-wikibase-config.php' ) ) {
	require_once __DIR__ . '/ronzz-wikibase-config.php';
}
