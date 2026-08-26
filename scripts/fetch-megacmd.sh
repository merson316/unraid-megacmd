#!/bin/bash
# Downloads MEGAcmd's official prebuilt amd64 package straight from MEGA's own
# repo and installs its binaries + private libs under /opt/megacmd. No compiling,
# no vendoring MEGA's binaries in our own git repo. Always pulled fresh from
# upstream, pinned to a specific MEGAcmd release.
#
# Usage: fetch-megacmd.sh <debian-repo-codename> <megacmd-version-prefix>
# Example: fetch-megacmd.sh Debian_11 2.5.2
set -euo pipefail

REPO_CODENAME="${1:?usage: fetch-megacmd.sh <debian-repo-codename> <megacmd-version-prefix>}"
MEGACMD_VERSION="${2:?usage: fetch-megacmd.sh <debian-repo-codename> <megacmd-version-prefix>}"

BASE_URL="https://mega.nz/linux/repo/${REPO_CODENAME}/amd64"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

echo "Looking up megacmd_${MEGACMD_VERSION}-*_amd64.deb under ${BASE_URL} ..."
DEB_NAME="$(curl -fsSL "${BASE_URL}/" \
  | grep -oE "megacmd_${MEGACMD_VERSION}-[0-9.]+_amd64\.deb" \
  | head -n1)"

if [ -z "$DEB_NAME" ]; then
  echo "Could not find a megacmd ${MEGACMD_VERSION} package under ${BASE_URL}" >&2
  exit 1
fi

echo "Fetching ${DEB_NAME} ..."
curl -fsSL -o "$TMPDIR/pkg.deb" "${BASE_URL}/${DEB_NAME}"

bsdtar -xf "$TMPDIR/pkg.deb" -C "$TMPDIR" data.tar.xz
bsdtar -xf "$TMPDIR/data.tar.xz" -C "$TMPDIR" ./usr/bin ./opt/megacmd/lib

# Copying straight onto a file that is currently executing fails with ETXTBSY ("Text file
# busy"), and mega-cmd-server is normally running whenever this script runs: the plugin's
# install block calls it on every install and on every boot, while the service is up. Writing
# each file under a temporary name in the same directory and renaming it over the target
# avoids that entirely, since rename(2) only replaces the directory entry and a process that
# is already running keeps its own open inode until it is restarted. Callers that need the new
# binaries to actually be in use (the settings page's "Update to X.Y.Z" action) stop and start
# the service around this script themselves.
install_over() {
  local src="$1" dest="$2" mode="$3"
  local tmp="${dest}.new.$$"
  mkdir -p "$(dirname "$dest")"
  rm -rf "$tmp"
  cp -a "$src" "$tmp"                 # -a keeps symlinks as symlinks (the ffmpeg libs use them)
  [ -L "$tmp" ] || chmod "$mode" "$tmp"
  mv -f "$tmp" "$dest"
}

mkdir -p /opt/megacmd/bin /opt/megacmd/lib

while IFS= read -r -d '' f; do
  install_over "$f" "/opt/megacmd/bin/${f#$TMPDIR/usr/bin/}" 0755
done < <(find "$TMPDIR/usr/bin" -mindepth 1 \( -type f -o -type l \) -print0)

while IFS= read -r -d '' f; do
  install_over "$f" "/opt/megacmd/lib/${f#$TMPDIR/opt/megacmd/lib/}" 0644
done < <(find "$TMPDIR/opt/megacmd/lib" -mindepth 1 \( -type f -o -type l \) -print0)

echo "Installed MEGAcmd ${MEGACMD_VERSION} (${DEB_NAME}) to /opt/megacmd"
