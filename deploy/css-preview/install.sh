#!/bin/bash
# Installs (or updates) the CSS preview site: a static copy of the project on its own hostname plus the edit API that
# lets one person (or their Claude) replace the site's stylesheet on the branch css-rework. Nothing here touches the
# live site. Safe to run again; by default it only PRINTS what it would do.
#
#   sudo deploy/css-preview/install.sh                                        # dry run
#   sudo deploy/css-preview/install.sh --apply --certbot admin@example.org    # do it
#
# Options:
#   --apply            really do it (default: dry run)
#   --domain NAME      default rift-css.dannyps.net (one label under the main domain; the DNS record must be
#                      "DNS only", never proxied/cached by Cloudflare, and point at this host)
#   --docroot DIR      the preview's git clone, default <app-dir>/../rift-css
#   --api-dir DIR      where the API code is installed (root-owned), default /usr/local/lib/rift-css-preview
#   --base-branch B    branch the clone starts from (default main); the clone then gets the branch css-rework
#   --repo-url URL     what to clone (default: the origin of this repository)
#   --certbot EMAIL    get the HTTPS certificate with certbot and enable the HTTPS vhost
#   --no-system        testing: skip apt, systemctl, a2en*, certbot, git clone (use with DESTDIR=)
# Environment: DESTDIR=/some/dir  puts /etc, /var/lib and /usr/local under it (for testing the file layout).
set -euo pipefail
shopt -s lastpipe   # the last command of a pipeline (put) runs in this shell, so CHANGES/WROTE are kept

HERE=$(cd "$(dirname "$0")" && pwd)
APP_DIR=$(dirname "$(dirname "$HERE")")
APPLY=0 DOMAIN=rift-css.dannyps.net DOCROOT= API_DIR= BASE_BRANCH=main REPO_URL= CERTBOT_EMAIL= SYSTEM=1
D=${DESTDIR:-}

while (( $# )); do
	case $1 in
		--apply) APPLY=1 ;;
		--domain) DOMAIN=$2; shift ;;
		--docroot) DOCROOT=$2; shift ;;
		--api-dir) API_DIR=$2; shift ;;
		--base-branch) BASE_BRANCH=$2; shift ;;
		--repo-url) REPO_URL=$2; shift ;;
		--certbot) CERTBOT_EMAIL=$2; shift ;;
		--no-system) SYSTEM=0 ;;
		-h|--help) sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) echo "unknown option: $1 (see --help)" >&2; exit 2 ;;
	esac
	shift
done
DOCROOT=${DOCROOT:-$(dirname "$APP_DIR")/rift-css}
API_DIR=${API_DIR:-/usr/local/lib/rift-css-preview}
[[ $DOMAIN =~ ^[a-z0-9-]+\.[a-z0-9.-]+$ ]] || { echo "bad --domain" >&2; exit 2; }
[[ $BASE_BRANCH =~ ^[A-Za-z0-9._/-]+$ ]] || { echo "bad --base-branch" >&2; exit 2; }
CONF_DIR=$D/etc/rift-data-load
STATE_DIR=$D/var/lib/rift-css-preview
AVAIL=$D/etc/apache2/sites-available
STAMP=$(date +%Y%m%d-%H%M%S)
CHANGES=0 WROTE=0
NOTES=()

say()  { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
note() { NOTES+=("$*"); }
run()  { if (( APPLY )); then say "   \$ $*"; "$@"; else say "   would run: $*"; CHANGES=$((CHANGES + 1)); fi; }
sys()  { if (( SYSTEM )); then run "$@"; else say "   (skipped, --no-system): $*"; fi; }
render() { sed -e "s#@DOMAIN@#$DOMAIN#g" -e "s#@DOCROOT@#$DOCROOT#g" -e "s#@API_DIR@#$API_DIR#g" -e "s#@CONF_DIR@#/etc/rift-data-load#g" -e "s#@STATE_DIR@#/var/lib/rift-css-preview#g" "$1"; }

put() { # DEST MODE OWNER, content from stdin; only writes when different, keeps a .bak
	local dest=$1 mode=$2 owner=$3 tmp
	tmp=$(mktemp); cat > "$tmp"
	if [[ -e $dest ]] && cmp -s "$tmp" "$dest"; then
		say "   unchanged  $dest"
		if (( APPLY )); then chmod "$mode" "$dest"; chown "$owner" "$dest" 2>/dev/null || true; fi
	elif (( APPLY )); then
		WROTE=1; mkdir -p "$(dirname "$dest")"
		if [[ -e $dest ]]; then cp -p "$dest" "$dest.bak.$STAMP"; fi
		install -m "$mode" "$tmp" "$dest"; chown "$owner" "$dest" 2>/dev/null || true
		say "   wrote      $dest"
	else
		say "   would write $dest$(if [[ -e $dest ]]; then echo ' (differs from the installed file)'; fi)"; CHANGES=$((CHANGES + 1)); WROTE=1
	fi
	rm -f "$tmp"
}
put_rendered() { render "$1" | put "$2" "$3" "$4"; }
ensure_dir() { # DIR MODE OWNER: create if missing; existing directories are left alone
	if [[ -d $1 ]]; then say "   exists     $1"
	elif (( APPLY )); then install -d -m "$2" "$1"; chown "$3" "$1" 2>/dev/null || true; say "   created    $1"
	else say "   would create $1"; CHANGES=$((CHANGES + 1)); fi
	return 0
}
enable_site() { for site in "$@"; do
	if [[ -e $D/etc/apache2/sites-enabled/$site ]]; then say "   site enabled $site"; else WROTE=1; sys a2ensite "$site"; fi
done; }

if (( APPLY )) && (( SYSTEM )) && [[ $EUID -ne 0 ]]; then echo "run as root (sudo) to --apply" >&2; exit 1; fi
say "CSS preview for https://$DOMAIN   docroot (git clone): $DOCROOT   API: $API_DIR   $( (( APPLY )) && echo APPLYING || echo '(dry run, nothing is changed)')"

# ---------------------------------------------------------------- 1. Apache modules
step "Apache modules"
if (( SYSTEM )); then
	for m in rewrite ssl headers alias; do
		if apache2ctl -M 2>/dev/null | grep -q "${m}_module"; then say "   apache module $m enabled"; else run a2enmod "$m"; fi
	done
else say "   (skipped, --no-system)"; fi

# ---------------------------------------------------------------- 2. the API code (root-owned, read-only for www-data) and its state
step "Edit API"
put_rendered "$HERE/api/index.php" "$API_DIR/public/index.php" 644 root:root
put "$API_DIR/briefing.md" 644 root:root < "$HERE/briefing.md"
ensure_dir "$STATE_DIR" 750 www-data:www-data
if [[ -s $CONF_DIR/css-token ]]; then say "   exists     $CONF_DIR/css-token (kept)"
elif (( APPLY )); then
	install -d -m 750 "$CONF_DIR"; ( umask 077; openssl rand -hex 32 > "$CONF_DIR/css-token" ); chown root:www-data "$CONF_DIR/css-token" 2>/dev/null || true; chmod 640 "$CONF_DIR/css-token"
	say "   created    $CONF_DIR/css-token (new random token)"; note "The edit token is in $CONF_DIR/css-token (root only): give it to the person doing the rework, never commit it."
else say "   would create $CONF_DIR/css-token (random token, root:www-data 640)"; CHANGES=$((CHANGES + 1)); fi

# ---------------------------------------------------------------- 3. the preview checkout
step "Preview checkout (own git clone, owned by www-data, branch css-rework)"
if [[ -d $DOCROOT/.git ]]; then
	say "   exists     $DOCROOT ($(sudo -u www-data git -C "$DOCROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?'))"
else
	url=$REPO_URL; [[ -n $url ]] || url=$(git -C "$APP_DIR" config remote.origin.url 2>/dev/null || true)
	[[ -n $url ]] || { echo "no --repo-url and this repository has no origin" >&2; exit 1; }
	sys git clone --branch "$BASE_BRANCH" "$url" "$DOCROOT"
	sys git -C "$DOCROOT" checkout -b css-rework
	sys chown -R www-data:www-data "$DOCROOT"
	if (( APPLY && SYSTEM )) && [[ ! -s $DOCROOT/assets/site.css ]]; then echo "the clone has no assets/site.css: is --base-branch $BASE_BRANCH the one with the single stylesheet?" >&2; exit 1; fi
fi

# ---------------------------------------------------------------- 4. Apache vhost and HTTPS
step "Apache vhost and HTTPS"
WROTE=0
if [[ -n $CERTBOT_EMAIL ]] || [[ -s $D/etc/letsencrypt/live/$DOMAIN/fullchain.pem ]]; then
	if [[ -n $CERTBOT_EMAIL && ! -s $D/etc/letsencrypt/live/$DOMAIN/fullchain.pem ]]; then
		render "$HERE/apache/css-preview-80.conf.in" | sed '/^RewriteEngine on/,/^RewriteRule/d' | put "$AVAIL/$DOMAIN.conf" 644 root:root
		enable_site "$DOMAIN.conf"; sys systemctl reload apache2
		sys certbot certonly --apache -d "$DOMAIN" -m "$CERTBOT_EMAIL" --agree-tos --non-interactive
	fi
	put_rendered "$HERE/apache/css-preview-80.conf.in" "$AVAIL/$DOMAIN.conf" 644 root:root
	put_rendered "$HERE/apache/css-preview-443.conf.in" "$AVAIL/$DOMAIN-le-ssl.conf" 644 root:root
	enable_site "$DOMAIN.conf" "$DOMAIN-le-ssl.conf"
else
	render "$HERE/apache/css-preview-80.conf.in" | sed '/^RewriteEngine on/,/^RewriteRule/d' | put "$AVAIL/$DOMAIN.conf" 644 root:root
	enable_site "$DOMAIN.conf"
	note "HTTPS is not set up: create the DNS record ($DOMAIN, DNS only, not proxied) and re-run with  --certbot you@example.org"
fi
if (( SYSTEM )); then
	if (( ! WROTE )); then say "   Apache configuration unchanged, no reload needed"
	elif (( APPLY )); then apache2ctl configtest && systemctl reload apache2
	else say "   would check the Apache configuration and reload it"; fi
fi

step "Summary"
if (( APPLY )); then say "Done."; else say "Dry run: $CHANGES change(s) would be made. Re-run with --apply to do them."; fi
note "Cloudflare: the record for $DOMAIN must be DNS only (grey cloud). The vhost also sends Cache-Control: no-store."
note "Bring the preview up to date with main:  $HERE/sync-from-main.sh   (merges origin/main into css-rework)"
note "Review and merge the rework:  $HERE/promote.sh   (fetches css-rework into this repository; see its header)"
for n in "${NOTES[@]}"; do say " - $n"; done
