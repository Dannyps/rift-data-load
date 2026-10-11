#!/bin/bash
# Nightly backup of the rift database: a custom-format dump (rift-YYYYMMDD-HHMM.dump) into ./backups,
# kept only if pg_restore can list it. Prunes to the newest 14 plus every Sunday one for 8 weeks.
# The dumps sit on the same disk as the database: they protect against mistakes, not a lost machine.
set -euo pipefail

COMPOSE_DIR=${COMPOSE_DIR:-$(cd "$(dirname "$0")/.." && pwd)}
DEST=${BACKUP_DIR:-$COMPOSE_DIR/backups}
KEEP_DAILY=${KEEP_DAILY:-14}
KEEP_WEEKLY_DAYS=${KEEP_WEEKLY_DAYS:-56}
DB=rift

compose() { docker compose --project-directory "$COMPOSE_DIR" "$@"; }

umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"
stamp=$(date +%Y%m%d-%H%M)
out="$DEST/$DB-$stamp.dump"

if compose exec -T db pg_dump -Fc -U rift "$DB" > "$out.part" \
	&& compose exec -T db pg_restore --list < "$out.part" > /dev/null; then
	mv "$out.part" "$out"
	echo "$DB ok: $out ($(du -h "$out" | cut -f1))"
else
	rm -f "$out.part"
	echo "$DB backup FAILED" >&2
	exit 1
fi

# Newest first by the date in the name (not mtime, which a restore or copy can change).
mapfile -t files < <(ls -1 "$DEST"/$DB-[0-9]*-[0-9]*.dump | sort -r)
removed=0
for i in "${!files[@]}"; do
	(( i < KEEP_DAILY )) && continue
	f=${files[$i]}
	d=$(basename "$f" | sed -E 's/.*-([0-9]{8})-[0-9]{4}\..*/\1/')
	if [[ $(date -d "$d" +%u) == 7 ]] && (( ( $(date +%s) - $(date -d "$d" +%s) ) / 86400 < KEEP_WEEKLY_DAYS )); then
		continue
	fi
	rm -f "$f"
	removed=$((removed + 1))
done
echo "  pruned $removed old, kept $(ls -1 "$DEST"/$DB-[0-9]*-[0-9]*.dump | wc -l)"
