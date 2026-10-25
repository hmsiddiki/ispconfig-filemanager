#!/usr/bin/env bash
#
# File Manager for ISPConfig: installer.
#
#   sudo ./install.sh                 install or update
#   sudo ./install.sh --check         only check this server, change nothing
#
# Options:  --panel-dir DIR     ISPConfig folder             (default /usr/local/ispconfig)
#           --ftp-host HOST     where Pure-FTPd listens      (default 127.0.0.1)
#           --ftp-port PORT                                  (default 21)
#           --ttl-hours N       how long one file manager session lasts (default 8)
#           --panel-port PORT   the ISPConfig panel port    (default 8080)
#           --with-menu         also add a Sites > File Manager side-menu entry (default: only the tab on each website)
#
# Safe to run again: an update replaces the program files and keeps your configuration and keys.

set -euo pipefail

PKG="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL_DIR="/usr/local/ispconfig"
FTP_HOST="127.0.0.1"
FTP_PORT="21"
TTL_HOURS="8"
PANEL_PORT="8080"
CHECK_ONLY=0
WITH_MENU=0

c_ok=$'\e[32m'; c_warn=$'\e[33m'; c_err=$'\e[31m'; c_dim=$'\e[2m'; c_off=$'\e[0m'
ok()   { echo "  ${c_ok}ok${c_off}    $*"; }
warn() { echo "  ${c_warn}warn${c_off}  $*"; }
info() { echo "  ${c_dim}...${c_off}   $*"; }
die()  { echo; echo "  ${c_err}STOPPED:${c_off} $*" >&2; echo >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --panel-dir) PANEL_DIR="${2:?}"; shift 2 ;;
        --ftp-host)  FTP_HOST="${2:?}"; shift 2 ;;
        --ftp-port)  FTP_PORT="${2:?}"; shift 2 ;;
        --ttl-hours) TTL_HOURS="${2:?}"; shift 2 ;;
        --panel-port) PANEL_PORT="${2:?}"; shift 2 ;;
        --check)     CHECK_ONLY=1; shift ;;
        --with-menu) WITH_MENU=1; shift ;;
        -h|--help)   sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "unknown option: $1 (try --help)" ;;
    esac
done

[[ "$TTL_HOURS" =~ ^[0-9]+$ ]] && [ "$TTL_HOURS" -ge 1 ] && [ "$TTL_HOURS" -le 72 ] || die "--ttl-hours must be a whole number from 1 to 72"
[[ "$FTP_PORT" =~ ^[0-9]+$ ]] || die "--ftp-port must be a number"
[[ "$PANEL_PORT" =~ ^[0-9]+$ ]] || die "--panel-port must be a number"
[ "$(id -u)" -eq 0 ] || die "run this as root (sudo ./install.sh)"

echo
echo "File Manager for ISPConfig"
echo "=========================="

# ---- 1. ISPConfig ------------------------------------------------------------------------------------------------
echo "1. Checking ISPConfig"
CONF="$PANEL_DIR/interface/lib/config.inc.php"
[ -f "$CONF" ] || die "ISPConfig not found at $PANEL_DIR (use --panel-dir if it is elsewhere)"
VERSION="$(grep -oP "define\('ISPC_APP_VERSION',\s*'\K[^']+" "$CONF" | head -1 || true)"
[ -n "$VERSION" ] || die "could not read the ISPConfig version from $CONF"
case "$VERSION" in
    3.2*|3.3*) ok "ISPConfig $VERSION" ;;
    3.*)       warn "ISPConfig $VERSION: only 3.2 and 3.3 were tested; continuing" ;;
    *)         die "ISPConfig $VERSION is not supported (needs 3.x)" ;;
esac

WEB_ROOT="$PANEL_DIR/interface/web"
[ -d "$WEB_ROOT/sites/lib" ] || die "the Sites module is missing from $WEB_ROOT"
PANEL_USER="$(stat -c %U "$WEB_ROOT/index.php")"
PANEL_GROUP="$(stat -c %G "$WEB_ROOT/index.php")"
ok "panel files belong to $PANEL_USER:$PANEL_GROUP"

# ---- 2. PHP ------------------------------------------------------------------------------------------------------
echo "2. Checking PHP"
PHP_BIN=""
for pool in /etc/php/*/fpm/pool.d/ispconfig.conf; do
    [ -f "$pool" ] || continue
    ver="$(echo "$pool" | sed -E 's#/etc/php/([0-9.]+)/.*#\1#')"
    [ -x "/usr/bin/php$ver" ] && PHP_BIN="/usr/bin/php$ver"
done
[ -n "$PHP_BIN" ] || PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "no PHP command-line binary found"
PHP_V="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);' || die "PHP $PHP_V is too old (needs 8.1 or newer)"
ok "using $PHP_BIN (PHP $PHP_V)"
MISSING=""
PHP_MODULES="$("$PHP_BIN" -m)"   # read once: "php -m | grep -q" can die of SIGPIPE under pipefail and report a false "missing"
for ext in ftp curl openssl mbstring zip json session fileinfo mysqli posix; do
    grep -qix "$ext" <<<"$PHP_MODULES" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || die "missing PHP extension(s):$MISSING. Install them (Debian/Ubuntu: apt install php-ftp php-curl php-mbstring php-zip php-mysql) and run again."
ok "PHP extensions present"

# ---- 3. FTP + database -------------------------------------------------------------------------------------------
echo "3. Checking FTP and the ISPConfig database"
if systemctl is-active --quiet pure-ftpd-mysql 2>/dev/null || systemctl is-active --quiet pure-ftpd 2>/dev/null; then
    ok "Pure-FTPd is running"
else
    die "Pure-FTPd is not running. This file manager needs ISPConfig's default FTP server (Pure-FTPd with MySQL)."
fi
if [ -f /etc/pure-ftpd/db/mysql.conf ] && grep -q "expires" /etc/pure-ftpd/db/mysql.conf; then
    ok "Pure-FTPd checks account expiry itself"
else
    warn "Pure-FTPd's login query does not check 'expires': expired temporary accounts would stay valid until the clean-up job removes them"
fi
(exec 3<>"/dev/tcp/$FTP_HOST/$FTP_PORT") 2>/dev/null || die "cannot reach FTP at $FTP_HOST:$FTP_PORT"
ok "FTP answers on $FTP_HOST:$FTP_PORT"

DBCHECK="$("$PHP_BIN" -r '
require $argv[1];
$d = @new mysqli($conf["db_host"], $conf["db_user"], $conf["db_password"], $conf["db_database"], (int)($conf["db_port"] ?? 3306));
if ($d->connect_errno) { echo "no-db"; exit; }
$cols = [];
foreach ($d->query("SHOW COLUMNS FROM ftp_user") as $r) $cols[] = $r["Field"];
foreach (["expires","user_type","user_config","parent_domain_id","uid","gid","dir"] as $need) if (!in_array($need, $cols)) { echo "missing-$need"; exit; }
echo "fine";' "$CONF" 2>/dev/null || echo "error")"
[ "$DBCHECK" = "fine" ] || die "the ftp_user table is not as expected ($DBCHECK)"
ok "database table ftp_user looks right"

if [ "$CHECK_ONLY" -eq 1 ]; then
    echo; echo "Check finished: this server can run the file manager. Nothing was changed."; echo; exit 0
fi

# ---- 4. Install --------------------------------------------------------------------------------------------------
echo "4. Installing"
[ -d "$PKG/payload/backend" ] && [ -d "$PKG/payload/vendor" ] && [ -d "$PKG/payload/web" ] || die "this package is incomplete (payload/ is missing)"

FM_HOME="$PANEL_DIR/filemanager"                 # program, libraries, keys: only ever read by the web server
FM_DATA="$PANEL_DIR/interface/temp/filemanager"  # logs, sessions, scratch space: written by the web server
WEB_DIR="$WEB_ROOT/filemanager"
APP_URL="/filemanager/app/"
UPDATING=0; [ -f "$FM_HOME/configuration.php" ] && UPDATING=1

# The panel's PHP runs sandboxed on current Debian/Ubuntu (systemd ProtectSystem): everything under /usr is read-only to it
# except the one folder ISPConfig itself whitelists, interface/temp. So what the app writes must live there.
[ -d "$PANEL_DIR/interface/temp" ] || die "$PANEL_DIR/interface/temp does not exist: this does not look like a normal ISPConfig install"

install -d -m 750 "$FM_HOME" "$FM_HOME/bin" "$WEB_DIR" "$WEB_DIR/app"
install -d -m 700 "$FM_HOME/private" "$FM_HOME/private/keys"
install -d -m 700 "$FM_DATA" "$FM_DATA/logs" "$FM_DATA/sessions" "$FM_DATA/tmp" "$FM_DATA/sso-used"

# program files are replaced on update; configuration and keys never are
rm -rf "$FM_HOME/backend" "$FM_HOME/vendor"
cp -a "$PKG/payload/backend" "$FM_HOME/backend"
cp -a "$PKG/payload/vendor" "$FM_HOME/vendor"
find "$WEB_DIR/app" -mindepth 1 -delete
cp -a "$PKG/payload/web/." "$WEB_DIR/app/"
ok "program files copied"

for key in remote.key sso.key; do
    if [ ! -s "$FM_HOME/private/keys/$key" ]; then
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$FM_HOME/private/keys/$key"
        info "created new key $key"
    fi
done

if [ ! -f "$FM_HOME/configuration.php" ]; then
    CSRF="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    sed -e "s|@CSRF_KEY@|$CSRF|g" -e "s|@FTP_HOST@|$FTP_HOST|g" -e "s|@FTP_PORT@|$FTP_PORT|g" \
        "$PKG/app/configuration.php.tpl" > "$FM_HOME/configuration.php"
    ok "configuration written ($FM_HOME/configuration.php)"
else
    ok "existing configuration kept"
fi

sed -e "s|@FM_HOME@|$FM_HOME|g" -e "s|@FM_DATA@|$FM_DATA|g" "$PKG/app/index.php.tpl" > "$WEB_DIR/app/index.php"
sed -e "s|@FM_HOME@|$FM_HOME|g" -e "s|@FM_APP_URL@|$APP_URL|g" -e "s|@FM_ACCOUNT_TTL@|$((TTL_HOURS * 3600))|g" \
    "$PKG/panel/index.php.tpl" > "$WEB_DIR/index.php"
sed -e "s|@PANEL_DIR@|$PANEL_DIR|g" "$PKG/bin/cleanup.php.tpl" > "$FM_HOME/bin/cleanup.php"
ok "panel page and clean-up script installed"

# ownership: the panel's own user and group, group-readable like the rest of interface/web (the web server reads through the group)
chown -R "$PANEL_USER:$PANEL_GROUP" "$FM_HOME" "$FM_DATA" "$WEB_DIR"
find "$WEB_DIR" -type d -exec chmod 750 {} +
find "$WEB_DIR" -type f -exec chmod 640 {} +
chmod 700 "$FM_HOME/private" "$FM_HOME/private/keys" "$FM_DATA" "$FM_DATA/"*
find "$FM_HOME/private/keys" -type f -exec chmod 600 {} +
chmod 640 "$FM_HOME/configuration.php"
ok "ownership and permissions set"

# ---- 5. Self-test ------------------------------------------------------------------------------------------------
echo "5. Testing"
if "$PHP_BIN" "$PKG/tools/selftest.php" "$FM_HOME" "$CONF" "$FTP_HOST" "$FTP_PORT" "$PKG/payload"; then
    :
else
    die "the self-test failed (see above). The menu entry has NOT been added to the panel. Fix the problem and run ./install.sh again, or run ./uninstall.sh to remove everything."
fi

# The test above runs as root on the command line. The panel's PHP runs as another user in a sandbox, so prove it over the
# real web path too: the page must be the real app (not an error text), and its API must answer from a working session.
PANEL_URL="https://127.0.0.1:$PANEL_PORT"
PAGE="$(curl -ks --max-time 15 -w '\n%{http_code}' "$PANEL_URL$APP_URL" || true)"
PAGE_CODE="${PAGE##*$'\n'}"
case "$PAGE" in
    *'id=app'*) [ "$PAGE_CODE" = "200" ] && ok "the panel serves the file manager page ($PANEL_URL$APP_URL)" || die "unexpected HTTP $PAGE_CODE from $PANEL_URL$APP_URL" ;;
    *) die "the panel did not serve the file manager (HTTP $PAGE_CODE): ${PAGE:0:200}" ;;
esac
API="$(curl -ks --max-time 15 -w '\n%{http_code}' "$PANEL_URL$APP_URL?r=/getuser" || true)"
case "$API" in
    *'"role":"guest"'*) ok "the file manager answers from a working session (sessions and logs are writable)" ;;
    *) die "the file manager's API did not answer correctly: ${API:0:300}. Check $FM_DATA is writable by $PANEL_USER and the panel's PHP service." ;;
esac

# only now, with everything proven to work, the menu entry and the clean-up job go in
if [ "$WITH_MENU" -eq 1 ]; then
    install -m 640 -o "$PANEL_USER" -g "$PANEL_GROUP" "$PKG/menu/filemanager.menu.php" "$WEB_ROOT/sites/lib/menu.d/filemanager.menu.php"
else
    rm -f "$WEB_ROOT/sites/lib/menu.d/filemanager.menu.php"   # the tab is enough; this also removes the entry an earlier version added
fi
# the "File Manager" tab on each website's page: ISPConfig loads every *.js file in js/js.d/
install -d -m 750 -o "$PANEL_USER" -g "$PANEL_GROUP" "$WEB_ROOT/js/js.d"
install -m 640 -o "$PANEL_USER" -g "$PANEL_GROUP" "$PKG/panel/filemanager-tab.js" "$WEB_ROOT/js/js.d/filemanager-tab.js"
sed -e "s|@PANEL_USER@|$PANEL_USER|g" -e "s|@PHP_BIN@|$PHP_BIN|g" -e "s|@FM_HOME@|$FM_HOME|g" \
    "$PKG/cron/ispconfig-filemanager.tpl" > /etc/cron.d/ispconfig-filemanager
chmod 644 /etc/cron.d/ispconfig-filemanager
ok "switched on: a File Manager tab on each website$([ "$WITH_MENU" -eq 1 ] && echo ", a Sites > File Manager menu entry,") and the clean-up job"

# A permanent uninstaller, because this package folder may be temporary (get.sh deletes it). It lives in /usr/local/sbin, owned
# by root and outside every folder the panel's PHP user can write to: a root-run script in a panel-writable folder could be swapped.
install -m 755 -o root -g root "$PKG/uninstall.sh" /usr/local/sbin/ispconfig-filemanager-uninstall
VERSION_NOW="$(cat "$PKG/VERSION" 2>/dev/null || echo unknown)"
ok "uninstaller installed as: ispconfig-filemanager-uninstall"

echo
if [ "$UPDATING" -eq 1 ]; then echo "Updated to version $VERSION_NOW."; else echo "Installed version $VERSION_NOW."; fi
echo
echo "  Open the panel: open a website (Sites > Website) and click its  File Manager  tab."
if [ "$WITH_MENU" -eq 1 ]; then echo "  There is also  Sites > File Manager  in the side menu (log out and in once if it is not there yet)."; fi
echo "  Files:      $FM_HOME   and   $WEB_DIR"
echo "  Settings:   $FM_HOME/configuration.php"
echo "  Remove:     sudo ispconfig-filemanager-uninstall"
echo
