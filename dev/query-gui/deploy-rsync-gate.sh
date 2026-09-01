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
#
# Before an rsync into a live dist dir, the current content is hardlink-copied
# into /var/backups/wdqs-frontends/{gui,builder}/pre-<ts>/ (best-effort: a
# snapshot failure warns on stderr but does not block the deploy). This gives
# per-deploy rollback granularity — restoring a pre-* snapshot reverts exactly
# the last deploy and keeps earlier good deploys (the nightly <YYYYMMDD>
# snapshot remains the deeper fallback). Hardlinks cost no extra space; the
# live inodes survive until rsync --delete unlinks them.
set -euo pipefail

ALLOWED=(
  /var/www/wdqs/query-gui/build
  /var/www/wdqs/query-builder/dist
  /var/backups/wdqs-frontends
)

# live dist dir -> backup subdir name (mirrors the nightly layout)
SNAPSHOT_DIRS=(
  "/var/www/wdqs/query-gui/build:gui"
  "/var/www/wdqs/query-builder/dist:builder"
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

# --- pre-deploy snapshot (best-effort, per-deploy rollback granularity) ---
for entry in "${SNAPSHOT_DIRS[@]}"; do
  src="${entry%%:*}"
  name="${entry##*:}"
  case "$DEST" in
    "$src" | "$src"/*)
      if [ -d "$src" ]; then
        ts="$(date -u +%Y%m%d-%H%M%S)"
        target="/var/backups/wdqs-frontends/$name/pre-$ts"
        i=1
        while [ -e "$target" ]; do
          target="/var/backups/wdqs-frontends/$name/pre-$ts-$i"
          i=$((i + 1))
        done
        if ! mkdir -p "$target" || ! cp -al "$src/." "$target/"; then
          echo "deploy-rsync-gate: WARNING: pre-deploy snapshot to $target failed — the nightly snapshot remains the fallback" >&2
        else
          echo "deploy-rsync-gate: pre-deploy snapshot -> $target" >&2
        fi
      fi
      ;;
  esac
done

# prune pre-* snapshots beyond the 14-day retention (mirrors the nightly cron)
find /var/backups/wdqs-frontends -maxdepth 2 -type d -name 'pre-*' -mtime +14 -exec rm -rf -- {} + 2>/dev/null || true

# rebuild the command from the validated tokens and exec it
set -f
set -- $CMD   # word-split SSH_ORIGINAL_COMMAND (rsync options contain no globs)
exec /usr/bin/rsync "${@:2}"
