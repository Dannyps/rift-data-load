#!/bin/bash
# Installs (or updates) everything this project needs outside the repository on a Debian/Ubuntu host:
# packages, Apache vhosts and HTTPS, the dedicated Postgres container, the secrets files, the database
# schema, the systemd timers, the monitor and logrotate. Safe to run again: it only writes what differs
# and keeps a .bak.<time> copy of any file it replaces. Secrets and existing data are never overwritten.
#
# By default it only PRINTS what it would do. Add --apply to do it.
#
#   sudo deploy/install.sh                                   # dry run: what would change on this host
#   sudo deploy/install.sh --apply --domain rift.example.org --certbot admin@example.org
#
# Options:
#   --apply                 really do it (default: dry run)
#   --domain NAME           site name, default rift.dannyps.net (its DNS must point at this host)
#   --app-dir DIR           where the repository is checked out, default: the parent of this script's directory
#   --db-dir DIR            compose file, .env, pgdata and backups, default: <app-dir>/../rift-postgres
#   --auth-token-file FILE  use this device token (e.g. the one already configured on the counter)
#   --pco-ini FILE          Planning Center credentials (client_id=..., secret=...); without it the sync timer stays off
#   --certbot EMAIL         get the HTTPS certificate with certbot and enable the HTTPS vhost
#   --no-system             testing: skip apt, docker, systemctl, a2en*, certbot (use with DESTDIR=)
# Environment: DESTDIR=/some/dir  puts /etc, /var/lib and /usr/local under it (for testing the file layout).
#
# Not done here (needs a person): pointing the DNS name at the host, installing docker itself, creating the
# first admin user, and setting the URL and token in the people counter. They are printed at the end.
set -euo pipefail
shopt -s lastpipe   # the last command of a pipeline (put) runs in this shell, so CHANGES/WROTE are kept

HERE=$(cd "$(dirname "$0")" && pwd)
APPLY=0 DOMAIN=rift.dannyps.net APP_DIR=$(dirname "$HERE") DB_DIR= TOKEN_FILE= PCO_INI= CERTBOT_EMAIL= SYSTEM=1
D=${DESTDIR:-}

while (( $# )); do
	case $1 in
		--apply) APPLY=1 ;;
		--domain) DOMAIN=$2; shift ;;
		--app-dir) APP_DIR=$2; shift ;;
		--db-dir) DB_DIR=$2; shift ;;
		--auth-token-file) TOKEN_FILE=$2; shift ;;
		--pco-ini) PCO_INI=$2; shift ;;
		--certbot) CERTBOT_EMAIL=$2; shift ;;
		--no-system) SYSTEM=0 ;;
		-h|--help) sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) echo "unknown option: $1 (see --help)" >&2; exit 2 ;;
	esac
	shift
done
DB_DIR=${DB_DIR:-$(dirname "$APP_DIR")/rift-postgres}
[[ $DOMAIN =~ ^[a-z0-9.-]+$ ]] || { echo "bad --domain" >&2; exit 2; }

CONF_DIR=$D/etc/rift-data-load
DATA_DIR=$D/var/lib/rift-data-load
STAMP=$(date +%Y%m%d-%H%M%S)
CHANGES=0
WROTE=0     # set by put() when a file was (or would be) written
NOTES=()

say()  { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
note() { NOTES+=("$*"); }
run()  {
	if (( APPLY )); then say "   \$ $*"; "$@"; else say "   would run: $*"; CHANGES=$((CHANGES + 1)); fi
}
sys() { # a command that only makes sense on the real system
	if (( SYSTEM )); then run "$@"; else say "   (skipped, --no-system): $*"; fi
}

# render FILE: substitute the placeholders of the templates in deploy/
render() { sed -e "s#@APP_DIR@#$APP_DIR#g" -e "s#@DB_DIR@#$DB_DIR#g" -e "s#@DOMAIN@#$DOMAIN#g" "$1"; }

# put CONTENT-FROM-STDIN at DEST with MODE and OWNER:GROUP, only if it differs (keeps a backup)
put() {
	local dest=$1 mode=$2 owner=$3 tmp
	tmp=$(mktemp); cat > "$tmp"
	if [[ -e $dest ]] && cmp -s "$tmp" "$dest"; then
		say "   unchanged  $dest"
		if (( APPLY )); then chmod "$mode" "$dest"; chown "$owner" "$dest" 2>/dev/null || true; fi
	elif (( APPLY )); then
		WROTE=1
		mkdir -p "$(dirname "$dest")"
		if [[ -e $dest ]]; then cp -p "$dest" "$dest.bak.$STAMP"; fi
		install -m "$mode" "$tmp" "$dest"; chown "$owner" "$dest" 2>/dev/null || true
		say "   wrote      $dest"
	else
		say "   would write $dest$(if [[ -e $dest ]]; then echo ' (differs from the installed file)'; fi)"
		CHANGES=$((CHANGES + 1)); WROTE=1
	fi
	rm -f "$tmp"
}
put_rendered() { render "$1" | put "$2" "$3" "$4"; }
# ensure_dir DIR MODE OWNER [enforce]: creates DIR with that mode/owner. An existing directory is only
# touched when "enforce" is given (the secrets and data directories of this project). Never enforce on
# pgdata: the container owns it (uid 70), changing its owner stops Postgres.
ensure_dir() {
	if [[ -d $1 ]]; then
		say "   exists     $1"
		if (( APPLY )) && [[ ${4:-} == enforce ]]; then chmod "$2" "$1"; chown "$3" "$1" 2>/dev/null || true; fi
	elif (( APPLY )); then
		install -d -m "$2" "$1"; chown "$3" "$1" 2>/dev/null || true; say "   created    $1"
	else
		say "   would create $1"; CHANGES=$((CHANGES + 1))
	fi
	return 0
}

if (( APPLY )) && (( SYSTEM )) && [[ $EUID -ne 0 ]]; then echo "run as root (sudo) to --apply" >&2; exit 1; fi
say "Installing for https://$DOMAIN   app: $APP_DIR   database: $DB_DIR   $( (( APPLY )) && echo APPLYING || echo '(dry run, nothing is changed)')"

# ---------------------------------------------------------------- 1. packages
step "Packages and Apache modules"
if (( SYSTEM )); then
	command -v docker >/dev/null || { echo "docker is not installed: install it first (https://docs.docker.com/engine/install/)" >&2; exit 1; }
	docker compose version >/dev/null 2>&1 || { echo "the docker compose plugin is missing" >&2; exit 1; }
	# check what is needed, not package names (PHP may come from versioned or third-party packages)
	missing=()
	need_cmd() { command -v "$1" >/dev/null 2>&1 || missing+=("$2"); }
	need_cmd apache2ctl apache2; need_cmd openssl openssl; need_cmd logrotate logrotate; need_cmd certbot certbot; need_cmd php php-cli
	if command -v php >/dev/null 2>&1; then
		for ext in pgsql curl mbstring intl; do php -m | grep -qix "$ext" || missing+=("php-$ext"); done
	fi
	if [[ ! -f /usr/lib/apache2/modules/libphp.so ]] && ! ls /usr/lib/apache2/modules/libphp*.so >/dev/null 2>&1; then missing+=(libapache2-mod-php); fi
	dpkg -s python3-certbot-apache >/dev/null 2>&1 || missing+=(python3-certbot-apache)
	if ((${#missing[@]})); then run apt-get install -y "${missing[@]}"; else say "   everything needed is installed"; fi
	for m in rewrite ssl headers; do
		if apache2ctl -M 2>/dev/null | grep -q "${m}_module"; then say "   apache module $m enabled"; else run a2enmod "$m"; fi
	done
else
	say "   (skipped, --no-system)"
fi

# ---------------------------------------------------------------- 2. directories and secrets
step "Directories and secrets"
ensure_dir "$CONF_DIR" 750 root:www-data enforce
ensure_dir "$DATA_DIR" 750 www-data:www-data enforce
# PHP sessions (kept 30 days: Debian's own cleanup of the default directory would end them after 24 minutes)
ensure_dir "$DATA_DIR/sessions" 700 www-data:www-data
printf 'd /var/lib/rift-data-load/sessions 0700 www-data www-data 31d\n' | put "$D/etc/tmpfiles.d/rift-data-load.conf" 644 root:root
ensure_dir "$DB_DIR" 755 root:root
ensure_dir "$DB_DIR/pgdata" 700 root:root      # owned by the container afterwards: never changed once it exists
ensure_dir "$DB_DIR/backup/systemd" 755 root:root

ENV_FILE=$DB_DIR/.env
if [[ -s $ENV_FILE ]]; then
	say "   exists     $ENV_FILE (database password kept)"
	DBPASS=$(sed -n 's/^POSTGRES_PASSWORD=//p' "$ENV_FILE")
elif (( APPLY )); then
	DBPASS=$(openssl rand -hex 24)
	( umask 077; printf 'POSTGRES_PASSWORD=%s\n' "$DBPASS" > "$ENV_FILE" )
	say "   created    $ENV_FILE (new random database password)"
else
	DBPASS=PLACEHOLDER; say "   would create $ENV_FILE with a random database password"; CHANGES=$((CHANGES + 1))
fi
printf '.env\npgdata/\nbackups/\n' | put "$DB_DIR/.gitignore" 644 root:root

if [[ -s $CONF_DIR/db.ini ]]; then
	say "   exists     $CONF_DIR/db.ini (kept)"
else
	printf 'host=127.0.0.1\nport=5433\ndbname=rift\nuser=rift\npassword=%s\n' "$DBPASS" | put "$CONF_DIR/db.ini" 640 root:www-data
fi

if [[ -s $CONF_DIR/auth-token ]]; then
	say "   exists     $CONF_DIR/auth-token (kept)"
elif [[ -n $TOKEN_FILE ]]; then
	tr -d '\n' < "$TOKEN_FILE" | put "$CONF_DIR/auth-token" 640 root:www-data
elif (( APPLY )); then
	openssl rand -base64 120 | tr -dc 'A-Za-z0-9' | cut -c1-128 | tr -d '\n' | put "$CONF_DIR/auth-token" 640 root:www-data
	note "A NEW device token was generated: read it with  sudo cat $CONF_DIR/auth-token  and set it as the Authorization header in the people counter."
else
	say "   would generate a random device token in $CONF_DIR/auth-token"; CHANGES=$((CHANGES + 1))
fi

HAVE_PCO=0
if [[ -s $CONF_DIR/pco.ini ]]; then say "   exists     $CONF_DIR/pco.ini (kept)"; HAVE_PCO=1
elif [[ -n $PCO_INI ]]; then put "$CONF_DIR/pco.ini" 640 root:www-data < "$PCO_INI"; HAVE_PCO=1
else note "No Planning Center credentials: create $CONF_DIR/pco.ini (client_id=..., secret=..., root:www-data 640) and enable rift-pco-sync.timer."; fi

# ---------------------------------------------------------------- 3. database container
step "Postgres container"
put_rendered "$HERE/postgres-compose.yml" "$DB_DIR/docker-compose.yml" 644 root:root
put_rendered "$HERE/pg-backup.sh" "$DB_DIR/backup/pg-backup.sh" 755 root:root
if (( SYSTEM )); then
	if docker compose --project-directory "$DB_DIR" ps --status running --format '{{.Name}}' 2>/dev/null | grep -q .; then
		say "   container already running"
	else
		run docker compose --project-directory "$DB_DIR" up -d
	fi
	if (( APPLY )); then
		for _ in $(seq 1 30); do
			docker compose --project-directory "$DB_DIR" exec -T db pg_isready -U rift -d rift >/dev/null 2>&1 && break; sleep 2
		done
		docker compose --project-directory "$DB_DIR" exec -T db pg_isready -U rift -d rift >/dev/null || { echo "the database did not become ready" >&2; exit 1; }
		say "   database ready"
	fi
fi

# ---------------------------------------------------------------- 4. schema
step "Database schema (created and migrated by the application)"
if (( SYSTEM )); then
	if (( APPLY )); then
		su -s /bin/sh www-data -c "php -r 'require \"$APP_DIR/sql/utils.php\"; db_connect(); echo \"   schema ok\n\";'"
	else
		say "   would run the schema check/migration (php sql/utils.php db_connect) as www-data"
	fi
else
	say "   (skipped, --no-system)"
fi

# ---------------------------------------------------------------- 5. timers, monitor, logrotate
step "systemd timers, monitor, logrotate"
put_rendered "$HERE/rift-monitor.py" "$D/usr/local/bin/rift-monitor.py" 755 root:root
WROTE=0
for f in "$HERE"/systemd/*; do put_rendered "$f" "$D/etc/systemd/system/$(basename "$f")" 644 root:root; done
put_rendered "$HERE/logrotate" "$D/etc/logrotate.d/rift-data-load" 644 root:root
if (( SYSTEM )); then
	if (( WROTE )); then run systemctl daemon-reload; else say "   units unchanged, no daemon-reload needed"; fi
	timers=(rift-monitor rift-snapshot rift-postgres-backup)
	(( HAVE_PCO )) && timers+=(rift-pco-sync)
	for t in "${timers[@]}"; do
		if systemctl is-enabled --quiet "$t.timer" 2>/dev/null && systemctl is-active --quiet "$t.timer"; then say "   timer active  $t.timer"
		else run systemctl enable --now "$t.timer"; fi
	done
fi

# ---------------------------------------------------------------- 6. Apache
step "Apache vhosts and HTTPS"
AVAIL=$D/etc/apache2/sites-available
WROTE=0
enable_site() { # FILE...: a2ensite only the ones not enabled yet
	for site in "$@"; do
		if [[ -e $D/etc/apache2/sites-enabled/$site ]]; then say "   site enabled $site"; else WROTE=1; sys a2ensite "$site"; fi
	done
}
if [[ -n $CERTBOT_EMAIL ]] || [[ -s $D/etc/letsencrypt/live/$DOMAIN/fullchain.pem ]]; then
	# the certificate exists (or is about to): final layout = port 80 redirects, port 443 serves the site
	if [[ -n $CERTBOT_EMAIL && ! -s $D/etc/letsencrypt/live/$DOMAIN/fullchain.pem ]]; then
		# first a plain port-80 vhost so certbot can validate the name
		render "$HERE/apache/vhost-80.conf.in" | sed '/^RewriteEngine on/,/^RewriteRule/d' | put "$AVAIL/$DOMAIN.conf" 644 root:root
		enable_site "$DOMAIN.conf"; sys systemctl reload apache2
		sys certbot certonly --apache -d "$DOMAIN" -m "$CERTBOT_EMAIL" --agree-tos --non-interactive
	fi
	put_rendered "$HERE/apache/vhost-80.conf.in" "$AVAIL/$DOMAIN.conf" 644 root:root
	put_rendered "$HERE/apache/vhost-443.conf.in" "$AVAIL/$DOMAIN-le-ssl.conf" 644 root:root
	enable_site "$DOMAIN.conf" "$DOMAIN-le-ssl.conf"
else
	render "$HERE/apache/vhost-80.conf.in" | sed '/^RewriteEngine on/,/^RewriteRule/d' | put "$AVAIL/$DOMAIN.conf" 644 root:root
	enable_site "$DOMAIN.conf"
	note "HTTPS is not set up: point $DOMAIN at this host and re-run with  --certbot you@example.org  (the counter should post over HTTPS)."
fi
if (( SYSTEM )); then
	if (( ! WROTE )); then say "   Apache configuration unchanged, no reload needed"
	elif (( APPLY )); then apache2ctl configtest && systemctl reload apache2
	else say "   would check the Apache configuration and reload it"; fi
fi

# ---------------------------------------------------------------- summary
step "Summary"
if (( APPLY )); then say "Done."; else say "Dry run: $CHANGES change(s) would be made. Re-run with --apply to do them."; fi
note "First admin user:  sudo -u www-data php $APP_DIR/admin_user.php create NAME --manage   (or import-htpasswd; see the README)"
note "Counter: post to https://$DOMAIN/load_from_request.php with the Authorization header from $CONF_DIR/auth-token"
note "Weekly meetings are seeded (Sundays 09:30, 11:30, 17:30); change them in /admin. Timezone/reset hour are constants in src/balance.php."
for n in "${NOTES[@]}"; do say " - $n"; done
