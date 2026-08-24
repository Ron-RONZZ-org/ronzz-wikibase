#!/usr/bin/env bash
# Install the pinned PlantUML jar + a `plantuml` wrapper, idempotently.
#
# Why a pinned jar instead of the distro package: the Debian/Ubuntu `plantuml`
# packages are old — Ubuntu 24.04 (noble) ships 1.2020.2, which predates
# PlantUML's security profiles (introduced 1.2020.11). The wiki runs PlantUML
# under the SANDBOX profile (no local-file access, no URL fetching) because
# the diagram text is arbitrary editor input — an old jar would silently
# ignore the profile. Pinning the jar gives the same version + profile
# support everywhere (CI container, dev stack container, production).
#
# The wrapper also exports PLANTUML_SECURITY_PROFILE=SANDBOX (belt-and-braces
# with the MediaWiki config line `env PLANTUML_SECURITY_PROFILE=SANDBOX
# /usr/local/bin/plantuml` in dev/config/Extensions.php / LocalSettings.php).
#
# Usage:
#   tools/install-plantuml.sh [--force] [--prefix <dir>]
#
#   --prefix <dir>   install under <dir>/opt/plantuml/ and <dir>/usr/local/bin/
#                    (default: / — i.e. /opt/plantuml + /usr/local/bin).
#                    The wiki runs php-fpm in a container; run this script
#                    INSIDE that container (see dev/README.md / ci.yml).
#   --force          re-download and reinstall even if already installed.
#
# Requires `java` on PATH (the jar needs a JRE; the wiki server already runs
# Java for WDQS/Blazegraph). Prints the installed version and exits 0 on
# success; exits non-zero with a clear message on failure.
#
# License: GPL-2.0-or-later (repo tools)

set -euo pipefail

PLANTUML_VERSION="1.2026.6"
PLANTUML_URL="https://github.com/plantuml/plantuml/releases/download/v${PLANTUML_VERSION}/plantuml.jar"
PLANTUML_SHA256="89948f14c93756c7a3fb7b69078ff37e8489fd79dd430c582b931e2f65358690"
JAR_PATH="/opt/plantuml/plantuml.jar"
WRAPPER_PATH="/usr/local/bin/plantuml"

force=0
prefix=""
while [ $# -gt 0 ]; do
	case "$1" in
		--force) force=1; shift ;;
		--prefix) prefix="$2"; shift 2 ;;
		*) echo "install-plantuml.sh: unknown option: $1" >&2; exit 2 ;;
	esac
done

if ! command -v java >/dev/null 2>&1; then
	echo "install-plantuml.sh: 'java' not found on PATH — install a JRE first" >&2
	echo "  (Debian/Ubuntu: apt-get install -y default-jre-headless)" >&2
	exit 1
fi

jar="${prefix}${JAR_PATH}"
wrapper="${prefix}${WRAPPER_PATH}"

need_install=1
if [ -f "$jar" ] && [ -f "$wrapper" ]; then
	actual="$(sha256sum "$jar" 2>/dev/null | awk '{print $1}')"
	if [ "$actual" = "$PLANTUML_SHA256" ]; then
		need_install=0
	fi
fi

if [ "$need_install" = 0 ] && [ "$force" = 0 ]; then
	echo "install-plantuml.sh: PlantUML ${PLANTUML_VERSION} already installed at ${jar} (checksum ok)"
	exit 0
fi

echo "install-plantuml.sh: installing PlantUML ${PLANTUML_VERSION} (jar + wrapper)"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

if ! curl -fsSL -o "$tmp" "$PLANTUML_URL"; then
	echo "install-plantuml.sh: download failed: $PLANTUML_URL" >&2
	exit 1
fi

actual="$(sha256sum "$tmp" | awk '{print $1}')"
if [ "$actual" != "$PLANTUML_SHA256" ]; then
	echo "install-plantuml.sh: checksum mismatch — expected ${PLANTUML_SHA256}, got ${actual}" >&2
	exit 1
fi

install -d -m 0755 "$(dirname "$jar")"
install -m 0644 "$tmp" "$jar"

install -d -m 0755 "$(dirname "$wrapper")"
cat > "$wrapper" <<EOF
#!/bin/sh
# Wrapper for the pinned PlantUML jar (installed by tools/install-plantuml.sh).
# SANDBOX security profile: no local-file access, no URL fetching — the
# diagram text is arbitrary wiki editor input.
export PLANTUML_SECURITY_PROFILE=SANDBOX
exec java -jar ${jar} "\$@"
EOF
chmod 0755 "$wrapper"

# Print the installed version. The JVM exits 16 (SIGPIPE) if stdout is closed
# early by `head`, so capture the full output first, then take the first line.
version_output="$("$wrapper" -version 2>&1 || true)"
echo "install-plantuml.sh: $(printf '%s\n' "$version_output" | head -1)"
echo "install-plantuml.sh: done (jar: ${jar}, wrapper: ${wrapper})"
