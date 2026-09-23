#!/usr/bin/env bash
# Install the pinned GeoGebra Math Apps Bundle for the GeoGebra extension,
# idempotently.
#
# The GeoGebra extension renders [[File:x.ggb]] CLIENT-SIDE by loading the
# self-hosted GeoGebra app inside a sandboxed iframe served from a cookie-less
# origin. The app is NOT committed (like the MathJax assets / the PlantUML jar)
# — it is installed into each environment from a PINNED, sha256-checked
# official bundle:
#
#   <dest>/GeoGebra/deployggb.js
#   <dest>/GeoGebra/HTML5/5.0/web3d/…      (the graphing/geometry/3d/classic app)
#
# `dest` defaults to the repo's player directory
# (extensions/GeoGebra/resources/player), so a local dev/CI run — and the
# nginx static root in CI — serve the app next to player.html. On production
# pass --dest /var/www/ggb (the ggb.ronzz.org webroot).
#
# Usage:
#   tools/install-geogebra.sh [--force] [--dest DIR]
#
#   --force     re-download and re-extract even if already installed.
#   --dest DIR  install directory (default: extensions/GeoGebra/resources/player).
#
# Requires `curl` and `python3` (stdlib zipfile). No Node needed.
#
# License note: the GeoGebra Math Apps are licensed for NON-COMMERCIAL use
# (https://www.geogebra.org/license). They are fetched from geogebra.org, never
# redistributed in this repo — see extensions/GeoGebra/ASSETS.md.
#
# License: GPL-2.0-or-later (repo tools)

set -euo pipefail

GEOGEBRA_VERSION="5.4.930.2"
BUNDLE_URL="https://download.geogebra.org/installers/5.4/geogebra-math-apps-bundle-5-4-930-2.zip"
BUNDLE_SHA256="7e0b7b1dc51cebe1675de15fd296d8ebe7f49e407567656351af42e29f55c871"
DEPLOYGGB_SHA256="7894ce225cfccf81940c8b35a6b6c4960db5169cb842c20cf99d5877997a335a"
WEB3D_NOCACHE_SHA256="bdb5e15da87efbc5c39f40a16eec708fa0f085b0211ee601668bfc6625b00d4b"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dest="${repo_root}/extensions/GeoGebra/resources/player"
force=0
while [ $# -gt 0 ]; do
	case "$1" in
		--force) force=1; shift ;;
		--dest)
			[ $# -ge 2 ] || { echo "install-geogebra.sh: --dest needs a directory" >&2; exit 2; }
			dest="$2"; shift 2 ;;
		*) echo "install-geogebra.sh: unknown option: $1" >&2; exit 2 ;;
	esac
done

marker="${dest}/GeoGebra/HTML5/5.0/web3d/web3d.nocache.js"

if [ -f "$marker" ] && [ "$force" = 0 ]; then
	actual="$(sha256sum "$marker" 2>/dev/null | awk '{print $1}')"
	if [ "$actual" = "$WEB3D_NOCACHE_SHA256" ]; then
		echo "install-geogebra.sh: GeoGebra ${GEOGEBRA_VERSION} already installed at ${dest}/GeoGebra (checksum ok)"
		exit 0
	fi
fi

echo "install-geogebra.sh: installing GeoGebra ${GEOGEBRA_VERSION} → ${dest}/GeoGebra/ (~48 MB unpacked)"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

if ! curl -fsSL -o "$tmp" "$BUNDLE_URL"; then
	echo "install-geogebra.sh: download failed: $BUNDLE_URL" >&2
	exit 1
fi

actual="$(sha256sum "$tmp" | awk '{print $1}')"
if [ "$actual" != "$BUNDLE_SHA256" ]; then
	echo "install-geogebra.sh: bundle checksum mismatch — expected ${BUNDLE_SHA256}, got ${actual}" >&2
	exit 1
fi

# Extract only the runtime files we serve: deployggb.js + the web3d codebase.
install -d -m 0755 "$dest"
python3 - "$tmp" "$dest" <<'PY'
import sys
import zipfile

archive, dest = sys.argv[1], sys.argv[2]
web3d_prefix = "GeoGebra/HTML5/5.0/web3d/"
with zipfile.ZipFile(archive) as zf:
    for info in zf.infolist():
        name = info.filename
        if name == "GeoGebra/deployggb.js" or name.startswith(web3d_prefix):
            zf.extract(info, dest)
PY

for pair in "GeoGebra/deployggb.js:${DEPLOYGGB_SHA256}" "GeoGebra/HTML5/5.0/web3d/web3d.nocache.js:${WEB3D_NOCACHE_SHA256}"; do
	rel="${pair%%:*}"
	want="${pair##*:}"
	got="$(sha256sum "${dest}/${rel}" | awk '{print $1}')"
	if [ "$got" != "$want" ]; then
		echo "install-geogebra.sh: extracted ${rel} checksum mismatch — expected ${want}, got ${got}" >&2
		exit 1
	fi
done

echo "install-geogebra.sh: done (${dest}/GeoGebra)"
