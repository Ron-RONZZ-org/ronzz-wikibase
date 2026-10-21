#!/usr/bin/env bash
# Install the pinned MathJax 4 assets and the NewCM font for the vendored
# SimpleMathJax extension, idempotently.
#
# SimpleMathJax renders math CLIENT-SIDE with MathJax 4 and, in local mode
# ($wgSmjUseCdn=false — this instance's rule: never fetch from CDNs at
# runtime), serves the library from
#   extensions/SimpleMathJax/resources/MathJax/tex-chtml.js
# with the font data from
#   extensions/SimpleMathJax/resources/MathJax/fonts/chtml/{woff2,dynamic}/
# (MathJax 4 moved the fonts to the separate @mathjax/mathjax-newcm-font
# package; resources/ext.SimpleMathJax.js sets output.font/fontPath).
# The upstream extension carries MathJax as a git SUBMODULE; this repo does
# not commit the ~22 MB tree (like the pinned PlantUML jar, see
# install-plantuml.sh) — the assets are installed into each environment
# (dev stack checkout, CI checkout, production /var/www/wikibase) by this
# script from PINNED, sha256-checked npm tarballs.
#
# MathJax 4.0 fixes the CHTML stretched-extender bug that made \overline /
# \underline disappear at some browser zoom levels and when printing
# (upstream mathjax/MathJax#2701, #2795, #3357 — fixed in 4.0 only).
#
# Usage:
#   tools/install-mathjax.sh [--force]
#
#   --force   re-download and re-extract even if already installed.
#
# Resolves the repo root from the script's own location (works from any CWD,
# and in CI on the runner HOST before `docker compose up` — the extension
# dir is a read-only bind mount, so the assets must exist in the checkout).
# Prints the installed file and exits 0 on success; exits non-zero with a
# clear message on failure.
#
# Requires `curl` and `tar`. No Node needed (the npm tarballs are plain files).
#
# License: GPL-2.0-or-later (repo tools)

set -euo pipefail

MATHJAX_VERSION="4.1.3"
MATHJAX_URL="https://registry.npmjs.org/mathjax/-/mathjax-${MATHJAX_VERSION}.tgz"
MATHJAX_TARBALL_SHA256="b7109f516f3d842de62b95d653e35fa0976dc223706a2a77440e7f7723f0c54d"
TEX_CHTML_SHA256="6ecd14970a73fca8bb115f8d6967aef818135dee0091339061c41ada230072b6"

FONT_VERSION="4.1.3"
FONT_URL="https://registry.npmjs.org/@mathjax/mathjax-newcm-font/-/mathjax-newcm-font-${FONT_VERSION}.tgz"
FONT_TARBALL_SHA256="87d7b869c6a2a6169d9a53acc4eab6c846a9cbe11752738226461bb5070c8b88"
# A font file is the sentinel: it proves the CHTML font tree extracted.
FONT_SENTINEL_SHA256="6dcaf2ebcd1d1490fb6e85c3647ae0ed0d0a47ac43efe136c5328519f1da117b"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dest_dir="${repo_root}/extensions/SimpleMathJax/resources/MathJax"
marker="${dest_dir}/tex-chtml.js"
font_sentinel="${dest_dir}/fonts/chtml/woff2/mjx-ncm-n.woff2"

force=0
while [ $# -gt 0 ]; do
	case "$1" in
		--force) force=1; shift ;;
		*) echo "install-mathjax.sh: unknown option: $1" >&2; exit 2 ;;
	esac
done

if [ ! -d "${repo_root}/extensions/SimpleMathJax" ]; then
	echo "install-mathjax.sh: ${repo_root}/extensions/SimpleMathJax not found — run from the ronzz-wikibase repo" >&2
	exit 1
fi

# Guard the destructive replace below: only the generated asset directory is
# ever removed (resources/MathJax is gitignored and rebuilt from the tarballs).
case "$dest_dir" in
	*/extensions/SimpleMathJax/resources/MathJax) ;;
	*)
		echo "install-mathjax.sh: refusing to operate on unexpected destination: $dest_dir" >&2
		exit 1
		;;
esac

need_install=1
if [ -f "$marker" ] && [ -f "$font_sentinel" ]; then
	actual="$(sha256sum "$marker" 2>/dev/null | awk '{print $1}')"
	font_actual="$(sha256sum "$font_sentinel" 2>/dev/null | awk '{print $1}')"
	if [ "$actual" = "$TEX_CHTML_SHA256" ] && [ "$font_actual" = "$FONT_SENTINEL_SHA256" ]; then
		need_install=0
	fi
fi

if [ "$need_install" = 0 ] && [ "$force" = 0 ]; then
	echo "install-mathjax.sh: MathJax ${MATHJAX_VERSION} + NewCM font already installed at ${dest_dir} (checksums ok)"
	exit 0
fi

echo "install-mathjax.sh: installing MathJax ${MATHJAX_VERSION} + NewCM font → ${dest_dir} (~22 MB unpacked)"
main_tmp="$(mktemp)"
font_tmp="$(mktemp)"
staging="$(mktemp -d)"
trap 'rm -rf -- "$staging"; rm -f -- "$main_tmp" "$font_tmp"' EXIT

if ! curl -fsSL -o "$main_tmp" "$MATHJAX_URL"; then
	echo "install-mathjax.sh: download failed: $MATHJAX_URL" >&2
	exit 1
fi

actual="$(sha256sum "$main_tmp" | awk '{print $1}')"
if [ "$actual" != "$MATHJAX_TARBALL_SHA256" ]; then
	echo "install-mathjax.sh: MathJax tarball checksum mismatch — expected ${MATHJAX_TARBALL_SHA256}, got ${actual}" >&2
	exit 1
fi

if ! curl -fsSL -o "$font_tmp" "$FONT_URL"; then
	echo "install-mathjax.sh: download failed: $FONT_URL" >&2
	exit 1
fi

actual="$(sha256sum "$font_tmp" | awk '{print $1}')"
if [ "$actual" != "$FONT_TARBALL_SHA256" ]; then
	echo "install-mathjax.sh: font tarball checksum mismatch — expected ${FONT_TARBALL_SHA256}, got ${actual}" >&2
	exit 1
fi

# Extract the combined components + the loader-resolved pieces into a staging
# tree first, so a failed download/checksum never leaves a half-installed
# MathJax behind. --strip-components=1 drops the npm "package" wrapper.
install -d -m 0755 "${staging}/main"
tar -xzf "$main_tmp" -C "${staging}/main" --strip-components=1 package/
install -d -m 0755 "${staging}/main/fonts"
tar -xzf "$font_tmp" -C "${staging}/main/fonts" --strip-components=1 package/chtml

actual="$(sha256sum "${staging}/main/tex-chtml.js" | awk '{print $1}')"
if [ "$actual" != "$TEX_CHTML_SHA256" ]; then
	echo "install-mathjax.sh: extracted tex-chtml.js checksum mismatch — expected ${TEX_CHTML_SHA256}, got ${actual}" >&2
	exit 1
fi

actual="$(sha256sum "${staging}/main/fonts/chtml/woff2/mjx-ncm-n.woff2" | awk '{print $1}')"
if [ "$actual" != "$FONT_SENTINEL_SHA256" ]; then
	echo "install-mathjax.sh: extracted font checksum mismatch — expected ${FONT_SENTINEL_SHA256}, got ${actual}" >&2
	exit 1
fi

# Replace the previous install (v3 used an es5/ tree; v4 has a different
# layout, so a plain overlay would leave stale files behind). staging/main is
# inside a mktemp dir, so this rename is atomic on the same filesystem only if
# it succeeds — a cross-device move falls back to the copy below.
rm -rf -- "$dest_dir"
if ! mv "${staging}/main" "$dest_dir" 2>/dev/null; then
	install -d -m 0755 "$dest_dir"
	cp -a "${staging}/main/." "$dest_dir/"
fi

echo "install-mathjax.sh: done (${marker}, ${font_sentinel})"
