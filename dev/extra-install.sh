#!/bin/bash
# Runs inside the wikibase/wikibase image on FIRST boot, after update.php
# (documented image hook: /extra-install.sh). Loads the ronzz-wikibase
# extensions and, once the seed has emitted it, the seed-generated config.
#
# The config require is guarded: before seeding, seed/generated/ is empty and
# the wiki must still boot with the extensions present but unconfigured.
cat >> /var/www/html/LocalSettings.php <<'EOF'

## ronzz-wikibase (dev/CI)
wfLoadExtension( 'EmbeddableContent' );
wfLoadExtension( 'WikibaseCitation' );
if ( file_exists( '/var/www/html/LocalSettings.d/ronzz-wikibase.php' ) ) {
	require_once '/var/www/html/LocalSettings.d/ronzz-wikibase.php';
}
EOF
