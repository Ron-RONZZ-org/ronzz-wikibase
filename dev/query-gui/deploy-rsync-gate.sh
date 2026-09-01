#!/bin/bash
# deploy-rsync-gate — restricted rsync entrypoint for the GitHub Actions
# `deploy` user on ronzz-linux-server-2 (see RonzzIT:Deployment/Wikibase §Query GUI).
#
# Installed as the forced command for the deploy SSH key:
#   restrict,command="/usr/local/sbin/deploy-rsync-gate" ssh-ed25519 AAAA...
# (operative copy: /usr/local/sbin/deploy-rsync-gate on the server; this file
# is the reviewed/versioned mirror).
#
# Allows rsync PUSH only, into the frontend directories and their backup dir.
# Everything else — interactive shells, arbitrary commands, and rsync PULLS
# (--sender) — is refused. The destination is validated against an allowlist
# and traversal (`..`) is rejected. Mirrors the semantics of rsync's rrsync.
set -euo pipefail

ALLOWED=(
  /var/www/wdqs/query-gui/build
  /var/www/wdqs/query-builder/dist
  /var/backups/wdqs-frontends
)

CMD="${SSH_ORIGINAL_COMMAND:-}"

# must be an rsync server invocation
case "$CMD" in
  rsync\ --server\ *) ;;
  *) echo "deploy-rsync-gate: only rsync pushes are allowed" >&2; exit 1 ;;
esac

# must be a PUSH (the client pushes TO the server: no --sender). Pulls would
# let CI read arbitrary files the deploy user can read.
case "$CMD" in
  *--sender*)
    echo "deploy-rsync-gate: pulls are not allowed" >&2; exit 1 ;;
esac

# destination = last token, quotes stripped, trailing slashes stripped
DEST="$(printf '%s' "$CMD" | awk '{print $NF}' | tr -d "'" | sed 's#/*$##')"

# must be absolute, no traversal
case "$DEST" in
  /*) ;;
  *) echo "deploy-rsync-gate: destination '$DEST' must be absolute" >&2; exit 1 ;;
esac
case "$DEST" in
  *'/..'*)
    echo "deploy-rsync-gate: traversal in destination '$DEST' is not allowed" >&2; exit 1 ;;
esac

ok=0
for p in "${ALLOWED[@]}"; do
  case "$DEST" in
    "$p" | "$p"/*) ok=1; break ;;
  esac
done
if [ "$ok" -ne 1 ]; then
  echo "deploy-rsync-gate: destination '$DEST' is not allowed" >&2
  exit 1
fi

# rebuild the command from the validated tokens and exec it
set -f
set -- $CMD   # word-split SSH_ORIGINAL_COMMAND (rsync options contain no globs)
exec /usr/bin/rsync "${@:2}"
