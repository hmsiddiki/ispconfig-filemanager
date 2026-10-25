#!/usr/bin/env bash
#
# One-line installer for the ISPConfig File Manager.
#
#   curl -fsSL https://raw.githubusercontent.com/hmsiddiki/ispconfig-filemanager/main/get.sh | sudo bash
#   curl -fsSL .../get.sh | sudo bash -s -- --version 1.0.0            install a specific version
#   curl -fsSL .../get.sh | sudo bash -s -- --check                    only check the server, change nothing
#   anything else is passed on to install.sh (see its --help), for example --ttl-hours 4
#
# What it does: downloads a release from GitHub, VERIFIES ITS SHA-256 CHECKSUM, unpacks it to a temporary folder and runs
# the installer from there (which checks your server, installs, and tests itself before switching anything on).
# It never touches anything outside the temporary folder except what install.sh itself installs.
#
# Prefer to look first? Download the release tarball from the Releases page, read it, and run ./install.sh yourself.
#
set -euo pipefail

REPO="${FM_REPO:-hmsiddiki/ispconfig-filemanager}"   # GitHub "owner/name". Set FM_REPO, or edit this line in your fork.
DOWNLOAD_BASE="${FM_DOWNLOAD_BASE:-}"            # optional: your own https mirror of the release files (then --version is required)
VERSION=""
PASS=()

die()  { echo "  STOPPED: $*" >&2; exit 1; }
info() { echo "  ... $*"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION="${2:?--version needs a number like 1.0.0}"; shift 2 ;;
        -h|--help) sed -n '2,15p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) PASS+=("$1"); shift ;;
    esac
done

[ "$REPO" != "OWNER/ispconfig-filemanager" ] || die "this copy of get.sh does not know which GitHub repository to download from. Set FM_REPO=owner/name, or edit REPO at the top of the file."
[[ "$REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die "FM_REPO must look like owner/name"
[ "$(id -u)" -eq 0 ] || die "run this as root (put sudo before bash)"
for tool in curl tar sha256sum; do command -v "$tool" >/dev/null || die "'$tool' is required but not installed"; done

echo
echo "ISPConfig File Manager: download and install"
echo "============================================"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -z "$VERSION" ]; then
    [ -z "$DOWNLOAD_BASE" ] || die "with FM_DOWNLOAD_BASE the version cannot be looked up: pass --version X.Y.Z"
    info "looking up the latest release of $REPO"
    VERSION="$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" | grep -oP '"tag_name":\s*"v?\K[0-9][^"]*' | head -1 || true)"
    [ -n "$VERSION" ] || die "could not find a release for $REPO (has one been published?)"
fi
VERSION="${VERSION#v}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.]+)?$ ]] || die "odd version number: $VERSION"

NAME="ispconfig-filemanager-$VERSION"
PROTO=(--proto =https --tlsv1.2)
if [ -n "$DOWNLOAD_BASE" ]; then
    BASE="${DOWNLOAD_BASE%/}/v$VERSION"
    case "$BASE" in https://*) ;; *) echo "  warn  FM_DOWNLOAD_BASE is not https: the checksum still protects against a damaged file, but not against someone who can change both files"; PROTO=() ;; esac
else
    BASE="https://github.com/$REPO/releases/download/v$VERSION"
fi

info "downloading $NAME.tar.gz"
curl -fsSL "${PROTO[@]+"${PROTO[@]}"}" -o "$TMP/$NAME.tar.gz" "$BASE/$NAME.tar.gz" || die "download failed: $BASE/$NAME.tar.gz"
curl -fsSL "${PROTO[@]+"${PROTO[@]}"}" -o "$TMP/$NAME.tar.gz.sha256" "$BASE/$NAME.tar.gz.sha256" || die "checksum file download failed"

info "verifying the checksum"
( cd "$TMP" && sha256sum -c "$NAME.tar.gz.sha256" >/dev/null 2>&1 ) || die "CHECKSUM MISMATCH: the download is damaged or not the release it claims to be. Nothing was installed."
echo "  ok    checksum matches ($(cut -d' ' -f1 "$TMP/$NAME.tar.gz.sha256" | cut -c1-16)...)"

tar -xzf "$TMP/$NAME.tar.gz" -C "$TMP"
[ -x "$TMP/$NAME/install.sh" ] || die "the package does not contain install.sh"

# a bare "check only" run must not then install: the installer's own --check does exactly that
cd "$TMP/$NAME"
./install.sh "${PASS[@]+"${PASS[@]}"}"
