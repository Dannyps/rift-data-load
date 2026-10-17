#!/bin/bash
# Brings the CSS rework into this repository for review: fetches the preview's css-rework branch as a local branch
# `css-rework` and checks that it changes nothing but assets/site.css. It does NOT merge: look at the diff, run
# tools/shots.py, then merge by hand (git merge css-rework) and run sync-from-main.sh afterwards. Run as root on the host.
#
#   deploy/css-preview/promote.sh [--docroot DIR]
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd); APP_DIR=$(dirname "$(dirname "$HERE")")
DOCROOT=$(dirname "$APP_DIR")/rift-css
[[ ${1:-} == --docroot ]] && DOCROOT=$2
T=$(mktemp -d); chmod 755 "$T"; trap 'rm -rf "$T"' EXIT
BASE=$(git -C "$APP_DIR" rev-parse HEAD)
G=(sudo -u www-data git -C "$DOCROOT")
# everything on css-rework that this repository does not have yet
"${G[@]}" bundle create "$T/rework.bundle" css-rework --not "$BASE" >/dev/null 2>&1 || { echo "css-rework has nothing new relative to $BASE" >&2; exit 1; }
chmod 644 "$T/rework.bundle"
git -C "$APP_DIR" fetch -q "$T/rework.bundle" css-rework:refs/heads/css-rework
echo "== commits on css-rework:"; git -C "$APP_DIR" log --oneline "$BASE..css-rework" | head -40
echo "== files changed relative to the current branch's merge base:"
git -C "$APP_DIR" diff --stat "$(git -C "$APP_DIR" merge-base HEAD css-rework)" css-rework
others=$(git -C "$APP_DIR" diff --name-only "$(git -C "$APP_DIR" merge-base HEAD css-rework)" css-rework | grep -v '^assets/site\.css$' || true)
if [[ -n $others ]]; then echo "WARNING: besides assets/site.css this touches:"; echo "$others"; exit 2; fi
echo "OK: only assets/site.css changed. Next: review 'git diff HEAD...css-rework', run tools/shots.py, then git merge css-rework."
