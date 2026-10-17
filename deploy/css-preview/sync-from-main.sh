#!/bin/bash
# Brings the preview checkout up to date with this repository's main branch (merges main into css-rework), so the
# preview keeps showing the current pages and fixtures while a CSS rework is open. Run as root on the host.
#
#   deploy/css-preview/sync-from-main.sh [--docroot DIR] [BRANCH]       (BRANCH defaults to main)
#
# Why a bundle: the preview clone belongs to www-data (it commits the API's saves) and this repository to another user,
# and git refuses to read a repository owned by somebody else, so the commits travel as a bundle file.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd); APP_DIR=$(dirname "$(dirname "$HERE")")
DOCROOT=$(dirname "$APP_DIR")/rift-css
[[ ${1:-} == --docroot ]] && { DOCROOT=$2; shift 2; }
SRC=${1:-main}
T=$(mktemp -d); chmod 755 "$T"; trap 'rm -rf "$T"' EXIT
git -C "$APP_DIR" bundle create "$T/src.bundle" "$SRC" >/dev/null 2>&1
chmod 644 "$T/src.bundle"
G=(sudo -u www-data git -C "$DOCROOT" -c user.name="CSS editor" -c user.email=css@rift-css.dannyps.net)
"${G[@]}" fetch -q "$T/src.bundle" "$SRC:refs/remotes/origin/$SRC"
if "${G[@]}" merge --no-edit -m "Merge $SRC into css-rework" "origin/$SRC"; then
	"${G[@]}" log --oneline -3
else
	"${G[@]}" merge --abort || true
	echo "Merge conflict (almost certainly in assets/site.css): nothing was changed. Resolve it by hand in $DOCROOT." >&2
	exit 1
fi
