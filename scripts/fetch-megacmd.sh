#!/bin/bash
# Installs MEGAcmd's official prebuilt amd64 package (binaries + private libs) under
# /opt/megacmd. No compiling, no vendoring MEGA's binaries in our own git repo.
#
# The .deb is kept on the flash drive after the first download, and later installs (every boot
# runs this again) use that copy. MEGA's repo only lists its newest release, so a version it
# has dropped can no longer be downloaded; without the cached copy, a boot after that (or a boot
# without internet access) would leave no MEGAcmd installed and the service could not start.
#
# Order: cached copy of the requested version, then MEGA's repo, then any cached version, then
# the newest version in MEGA's repo. The flash drive is only written when a new package is
# downloaded (the old one is deleted then) or when the installed version changes.
#
# Usage: fetch-megacmd.sh <debian-repo-codename> <megacmd-version-prefix> [cache-dir]
# Example: fetch-megacmd.sh Debian_11 2.6.0
set -euo pipefail

REPO_CODENAME="${1:?usage: fetch-megacmd.sh <debian-repo-codename> <megacmd-version-prefix> [cache-dir]}"
MEGACMD_VERSION="${2:?usage: fetch-megacmd.sh <debian-repo-codename> <megacmd-version-prefix> [cache-dir]}"
CACHE_DIR="${3:-/boot/config/plugins/megacmd}"
CFG="/boot/config/plugins/megacmd/megacmd.cfg"

BASE_URL="https://mega.nz/linux/repo/${REPO_CODENAME}/amd64"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

# Newest cached .deb whose version matches $1 (a version prefix, or [0-9]* for any).
cached_deb() {
  { find "$CACHE_DIR" -maxdepth 1 -name "megacmd_$1-*_amd64.deb" 2>/dev/null || true; } | sort -V | tail -n1
}

repo_listing() {
  curl -fsSL --max-time 30 "${BASE_URL}/" 2>/dev/null \
    | grep -oE 'megacmd_[0-9.]+-[0-9.]+_amd64\.deb' | sort -uV || true
}

DEB="$(cached_deb "$MEGACMD_VERSION")"
DEB_NAME=""
if [ -n "$DEB" ]; then
  echo "Using cached $(basename "$DEB")"
else
  echo "Looking up megacmd_${MEGACMD_VERSION}-*_amd64.deb under ${BASE_URL} ..."
  LISTING="$(repo_listing)"
  DEB_NAME="$(grep -F "megacmd_${MEGACMD_VERSION}-" <<<"$LISTING" | tail -n1 || true)"
  if [ -z "$DEB_NAME" ]; then
    DEB="$(cached_deb '[0-9]*')"
    if [ -n "$DEB" ]; then
      echo "MEGAcmd ${MEGACMD_VERSION} is not available from MEGA. Using cached $(basename "$DEB") instead."
    else
      DEB_NAME="$(tail -n1 <<<"$LISTING")"
      if [ -z "$DEB_NAME" ]; then
        echo "ERROR: could not reach ${BASE_URL}, and no MEGAcmd package is cached in ${CACHE_DIR}." >&2
        exit 1
      fi
      echo "MEGAcmd ${MEGACMD_VERSION} is no longer available from MEGA. Installing ${DEB_NAME} instead."
    fi
  fi
fi

if [ -z "$DEB" ]; then
  echo "Fetching ${DEB_NAME} ..."
  curl -fsSL --max-time 600 -o "$TMPDIR/$DEB_NAME" "${BASE_URL}/${DEB_NAME}"
  bsdtar -tf "$TMPDIR/$DEB_NAME" data.tar.xz >/dev/null   # refuse a truncated or bogus download
  mkdir -p "$CACHE_DIR"
  cp "$TMPDIR/$DEB_NAME" "$CACHE_DIR/$DEB_NAME.part"
  mv -f "$CACHE_DIR/$DEB_NAME.part" "$CACHE_DIR/$DEB_NAME"
  find "$CACHE_DIR" -maxdepth 1 -name 'megacmd_*_amd64.deb' ! -name "$DEB_NAME" -delete
  DEB="$CACHE_DIR/$DEB_NAME"
fi

INSTALLED_VERSION="$(basename "$DEB" | sed -E 's/^megacmd_([0-9.]+)-.*/\1/')"

bsdtar -xf "$DEB" -C "$TMPDIR" data.tar.xz
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

# What is actually installed, for the settings page and the update check (RAM, rewritten at
# each boot).
echo "$INSTALLED_VERSION" > /opt/megacmd/VERSION

# Remember the installed version in the plugin's settings file, so that the next boot installs
# the same version even after a plugin update replaces megacmd.plg (and its pinned version).
# Written only when it changes.
if [ -d "$(dirname "$CFG")" ] && ! grep -qx "MEGACMD_VERSION=\"$INSTALLED_VERSION\"" "$CFG" 2>/dev/null; then
  if grep -q '^MEGACMD_VERSION=' "$CFG" 2>/dev/null; then
    sed -i "s/^MEGACMD_VERSION=.*/MEGACMD_VERSION=\"$INSTALLED_VERSION\"/" "$CFG"
  else
    echo "MEGACMD_VERSION=\"$INSTALLED_VERSION\"" >> "$CFG"
  fi
fi

echo "Installed MEGAcmd ${INSTALLED_VERSION} ($(basename "$DEB")) to /opt/megacmd"
