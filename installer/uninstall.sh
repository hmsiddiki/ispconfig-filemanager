#!/usr/bin/env bash
#
# Removes the File Manager from ISPConfig. Leaves ISPConfig itself, your websites and their files untouched.
#
#   sudo ./uninstall.sh [--panel-dir DIR] [--yes]
#
set -euo pipefail

PANEL_DIR="/usr/local/ispconfig"
ASSUME_YES=0
while [ $# -gt 0 ]; do
    case "$1" in
        --panel-dir) PANEL_DIR="${2:?}"; shift 2 ;;
        --yes) ASSUME_YES=1; shift ;;
        *) echo "unknown option: $1" >&2; exit 1 ;;
    esac
done
[ "$(id -u)" -eq 0 ] || { echo "run this as root" >&2; exit 1; }

FM_HOME="$PANEL_DIR/filemanager"
FM_DATA="$PANEL_DIR/interface/temp/filemanager"
WEB_DIR="$PANEL_DIR/interface/web/filemanager"
MENU="$PANEL_DIR/interface/web/sites/lib/menu.d/filemanager.menu.php"
TABJS="$PANEL_DIR/interface/web/js/js.d/filemanager-tab.js"
CONF="$PANEL_DIR/interface/lib/config.inc.php"

echo "This removes:"
echo "  $FM_HOME   (program, configuration, keys)"
echo "  $FM_DATA   (logs, sessions, scratch files)"
echo "  $WEB_DIR"
echo "  $MENU"
echo "  $TABJS"
echo "  /etc/cron.d/ispconfig-filemanager"
echo "  /usr/local/sbin/ispconfig-filemanager-uninstall   (this command)"
echo "  all temporary FTP accounts the file manager created"
if [ "$ASSUME_YES" -ne 1 ]; then
    read -r -p "Continue? [y/N] " answer
    [ "$answer" = "y" ] || [ "$answer" = "Y" ] || { echo "Cancelled."; exit 0; }
fi

PHP_BIN="$(command -v php || true)"
for pool in /etc/php/*/fpm/pool.d/ispconfig.conf; do
    [ -f "$pool" ] || continue
    ver="$(echo "$pool" | sed -E 's#/etc/php/([0-9.]+)/.*#\1#')"
    [ -x "/usr/bin/php$ver" ] && PHP_BIN="/usr/bin/php$ver"
done

if [ -f "$CONF" ] && [ -n "$PHP_BIN" ]; then
    "$PHP_BIN" -r '
    require $argv[1];
    $d = @new mysqli($conf["db_host"], $conf["db_user"], $conf["db_password"], $conf["db_database"], (int)($conf["db_port"] ?? 3306));
    if (!$d->connect_errno) { $d->query("DELETE FROM ftp_user WHERE user_config = \"filegator-temp\""); echo "  removed " . $d->affected_rows . " temporary FTP account(s)\n"; }
    ' "$CONF" || true
fi

rm -f "$MENU" "$TABJS" /etc/cron.d/ispconfig-filemanager /usr/local/sbin/ispconfig-filemanager-uninstall
rm -rf "$WEB_DIR" "$FM_HOME" "$FM_DATA"
echo "Removed."
