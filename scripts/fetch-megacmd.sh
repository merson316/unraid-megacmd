#!/bin/bash
# Downloads MEGAcmd's official prebuilt amd64 package straight from MEGA's own
# repo and installs its binaries + private libs under /opt/megacmd. No compiling,
# no vendoring MEGA's binaries in our own git repo -- always pulled fresh from
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

mkdir -p /opt/megacmd/bin /opt/megacmd/lib
cp -a "$TMPDIR/usr/bin/." /opt/megacmd/bin/
cp -a "$TMPDIR/opt/megacmd/lib/." /opt/megacmd/lib/

chmod -R a+rx /opt/megacmd/bin
find /opt/megacmd/lib -type f -exec chmod 644 {} \;

echo "Installed MEGAcmd ${MEGACMD_VERSION} (${DEB_NAME}) to /opt/megacmd"
