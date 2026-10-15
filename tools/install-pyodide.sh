#!/usr/bin/env bash
# Install the pinned Pyodide distribution for the Pyodide4MediaWiki extension,
# idempotently.
#
# Pyodide is CPython compiled to WebAssembly; the extension executes
# `class="pyodide"` code blocks client-side with it. This instance never
# fetches runtime assets from a CDN (repo rule), so the distribution —
# `pyodide.js`, `pyodide.asm.wasm`, `python_stdlib.zip`, `pyodide-lock.json`
# and a curated set of pre-built Python packages — is served from the wiki's
# own origin and installed here per environment.
#
# The Pyodide **core** tarball is a pinned, sha256-checked GitHub release
# asset (~7 MB). The packages come from the same pinned release, served by the
# jsDelivr Pyodide mirror (`.../v<version>/full/<file>`); every wheel's sha256
# is taken from the distribution's own `pyodide-lock.json`, so a tampered
# download is rejected. The transitive dependency closure of the requested
# packages is resolved from that lock file — no package is hard-coded.
#
# Usage:
#   tools/install-pyodide.sh [--force] [--dest DIR] [--packages "numpy scipy"]
#
#   --force        re-download even if already installed.
#   --dest DIR     install into DIR instead of the checkout's extension dir
#                  (used on the server after the extension rsync, e.g.
#                  --dest /var/www/wikibase/extensions/Pyodide4MediaWiki/resources/pyodide).
#   --packages P   space-separated Pyodide package names (default:
#                  "numpy matplotlib pandas scipy sympy"). The dependency
#                  closure is added automatically; an unknown name aborts.
#
# Resolves the repo root from the script's own location (works from any CWD,
# and in CI on the runner HOST before `docker compose up` — the extension dir
# is a read-only bind mount, so the assets must exist in the checkout before
# the stack boots). Requires `curl`, `tar`, `bzip2` and `python3` (stdlib
# only). Exits non-zero with a clear message on any failure.
#
# License: GPL-2.0-or-later (repo tools)

set -euo pipefail

PYODIDE_VERSION="314.0.7"
PYODIDE_CORE_URL="https://github.com/pyodide/pyodide/releases/download/${PYODIDE_VERSION}/pyodide-core-${PYODIDE_VERSION}.tar.bz2"
PYODIDE_CORE_SHA256="2abdcc2e35208af406e07724cffa85bc582ced97e9028383ecf5462541393f95"
PYODIDE_CDN="https://cdn.jsdelivr.net/pyodide/v${PYODIDE_VERSION}/full"
DEFAULT_PACKAGES="numpy matplotlib pandas scipy sympy"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dest_dir="${repo_root}/extensions/Pyodide4MediaWiki/resources/pyodide"
packages="$DEFAULT_PACKAGES"
force=0

while [ $# -gt 0 ]; do
	case "$1" in
		--force) force=1; shift ;;
		--dest) dest_dir="$2"; shift 2 ;;
		--dest=*) dest_dir="${1#*=}"; shift ;;
		--packages) packages="$2"; shift 2 ;;
		--packages=*) packages="${1#*=}"; shift ;;
		*) echo "install-pyodide.sh: unknown option: $1" >&2; exit 2 ;;
	esac
done

if [ "$force" = 0 ] && [ ! -d "${repo_root}/extensions/Pyodide4MediaWiki" ] \
	&& [ "$dest_dir" = "${repo_root}/extensions/Pyodide4MediaWiki/resources/pyodide" ]; then
	echo "install-pyodide.sh: ${repo_root}/extensions/Pyodide4MediaWiki not found — run from the ronzz-wikibase repo or pass --dest" >&2
	exit 1
fi

version_file="${dest_dir}/.installed"
desired="${PYODIDE_VERSION} ${packages}"

if [ "$force" = 0 ] && [ -f "$version_file" ] && [ -f "${dest_dir}/pyodide.asm.wasm" ] \
	&& [ "$(cat "$version_file")" = "$desired" ]; then
	echo "install-pyodide.sh: Pyodide ${PYODIDE_VERSION} (${packages}) already installed at ${dest_dir}"
	exit 0
fi

echo "install-pyodide.sh: installing Pyodide ${PYODIDE_VERSION} core → ${dest_dir}"

tmp="$(mktemp)"
wheels_file="$(mktemp)"
trap 'rm -f "$tmp" "$wheels_file"' EXIT

if ! curl -fsSL -o "$tmp" "$PYODIDE_CORE_URL"; then
	echo "install-pyodide.sh: download failed: $PYODIDE_CORE_URL" >&2
	exit 1
fi

actual="$(sha256sum "$tmp" | awk '{print $1}')"
if [ "$actual" != "$PYODIDE_CORE_SHA256" ]; then
	echo "install-pyodide.sh: core tarball checksum mismatch — expected ${PYODIDE_CORE_SHA256}, got ${actual}" >&2
	exit 1
fi

install -d -m 0755 "$dest_dir"
# The tarball's single top-level directory is `pyodide/`; strip it so the
# distribution lands directly in dest_dir (indexURL).
tar -xjf "$tmp" -C "$dest_dir" --strip-components=1

lock="${dest_dir}/pyodide-lock.json"
if [ ! -f "$lock" ]; then
	echo "install-pyodide.sh: ${lock} missing after extraction" >&2
	exit 1
fi

# Resolve the transitive dependency closure of the requested packages from the
# pinned lock file and emit "<file_name> <sha256>" per wheel (one per line).
PYODIDE_LOCK="$lock" PYODIDE_PACKAGES="$packages" python3 - <<'PY' > "$wheels_file"
import json
import os
import sys

lock = json.load( open( os.environ[ "PYODIDE_LOCK" ] ) )
available = lock[ "packages" ]
requested = os.environ[ "PYODIDE_PACKAGES" ].split()

seen = []


def add( name ):
	if name in seen:
		return
	if name not in available:
		sys.stderr.write(
			"install-pyodide.sh: package not in the Pyodide distribution: %s\n" % name
		)
		sys.exit( 1 )
	seen.append( name )
	for dep in available[ name ].get( "depends", [] ):
		add( dep )


for package in requested:
	add( package )

for name in seen:
	entry = available[ name ]
	print( entry[ "file_name" ], entry[ "sha256" ] )
PY

if [ ! -s "$wheels_file" ]; then
	echo "install-pyodide.sh: dependency resolution returned no packages" >&2
	exit 1
fi

while read -r file sha; do
	[ -n "$file" ] || continue
	url="${PYODIDE_CDN}/${file}"
	target="${dest_dir}/${file}"

	if [ -f "$target" ] && [ "$(sha256sum "$target" | awk '{print $1}')" = "$sha" ]; then
		continue
	fi

	if ! curl -fsSL -o "${target}.part" "$url"; then
		rm -f "${target}.part"
		echo "install-pyodide.sh: download failed: $url" >&2
		exit 1
	fi

	actual="$(sha256sum "${target}.part" | awk '{print $1}')"
	if [ "$actual" != "$sha" ]; then
		rm -f "${target}.part"
		echo "install-pyodide.sh: checksum mismatch for ${file} — expected ${sha}, got ${actual}" >&2
		exit 1
	fi

	mv "${target}.part" "$target"
	echo "  + ${file}"
done < "$wheels_file"

printf '%s' "$desired" > "$version_file"

echo "install-pyodide.sh: done (${dest_dir})"
