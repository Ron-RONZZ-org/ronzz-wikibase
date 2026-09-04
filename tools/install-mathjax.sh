#!/usr/bin/env bash
# Install the pinned MathJax 3 assets for the vendored SimpleMathJax
# extension, idempotently.
#
# SimpleMathJax renders math CLIENT-SIDE with MathJax 3 and, in local mode
# ($wgSmjUseCdn=false — this instance's rule: never fetch from CDNs at
# runtime), serves the library from
#   extensions/SimpleMathJax/resources/MathJax/es5/tex-chtml.js
# The upstream extension carries MathJax as a git SUBMODULE; this repo does
# not commit the ~24 MB es5/ tree (like the pinned PlantUML jar, see
# install-plantuml.sh) — the assets are installed into each environment
# (dev stack checkout, CI checkout, production /var/www/wikibase) by this
# script from a PINNED, sha256-checked npm tarball.
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
# Requires `curl` and `tar`. No Node needed (the npm tarball is plain files).
#
# License: GPL-2.0-or-later (repo tools)

set -euo pipefail

MATHJAX_VERSION="3.2.2"
MATHJAX_URL="https://registry.npmjs.org/mathjax/-/mathjax-${MATHJAX_VERSION}.tgz"
MATHJAX_TARBALL_SHA256="1b9c0a1c44df864e915690558e72adb9cc5203360daefd385084ced3b6c64c09"
TEX_CHTML_SHA256="0a6ded5abbce13331658dd239f34382abd06492c74b71b61e8caa8112ec55fa5"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dest_dir="${repo_root}/extensions/SimpleMathJax/resources/MathJax"
marker="${dest_dir}/es5/tex-chtml.js"

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

need_install=1
if [ -f "$marker" ]; then
	actual="$(sha256sum "$marker" 2>/dev/null | awk '{print $1}')"
	if [ "$actual" = "$TEX_CHTML_SHA256" ]; then
		need_install=0
	fi
fi

if [ "$need_install" = 0 ] && [ "$force" = 0 ]; then
	echo "install-mathjax.sh: MathJax ${MATHJAX_VERSION} already installed at ${marker} (checksum ok)"
	exit 0
fi

echo "install-mathjax.sh: installing MathJax ${MATHJAX_VERSION} → ${dest_dir}/es5/ (~24 MB unpacked)"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

if ! curl -fsSL -o "$tmp" "$MATHJAX_URL"; then
	echo "install-mathjax.sh: download failed: $MATHJAX_URL" >&2
	exit 1
fi

actual="$(sha256sum "$tmp" | awk '{print $1}')"
if [ "$actual" != "$MATHJAX_TARBALL_SHA256" ]; then
	echo "install-mathjax.sh: tarball checksum mismatch — expected ${MATHJAX_TARBALL_SHA256}, got ${actual}" >&2
	exit 1
fi

# Extract only package/es5 (the single-file builds + components + fonts the
# extension's loader resolves at runtime); --strip-components=1 drops the
# npm "package" wrapper, leaving es5/ under dest_dir.
install -d -m 0755 "$dest_dir"
tar -xzf "$tmp" -C "$dest_dir" --strip-components=1 package/es5

actual="$(sha256sum "$marker" | awk '{print $1}')"
if [ "$actual" != "$TEX_CHTML_SHA256" ]; then
	echo "install-mathjax.sh: extracted tex-chtml.js checksum mismatch — expected ${TEX_CHTML_SHA256}, got ${actual}" >&2
	exit 1
fi

echo "install-mathjax.sh: done (${marker})"
