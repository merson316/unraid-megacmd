#!/bin/bash
# Builds the plugin-logic package (rc.d script, webGUI page, event hooks) into a
# Slackware-style .txz. Does NOT bundle MEGAcmd's own binaries; those are fetched
# fresh from MEGA's official repo at plugin install time (see megacmd.plg).
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="${1:?usage: build.sh <plugin-version, e.g. 2026.07.05 for the first release of the day, 2026.07.05.2/.3/... for same-day follow-ups>}"
OUT="dist/megacmd-plugin-${VERSION}-x86_64-1.txz"

rm -f "$OUT"
# fetch-megacmd.sh also goes into the package, as the installer's fallback when GitHub cannot
# be reached at boot (the .plg normally downloads the current copy from GitHub).
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
cp -a source/etc source/usr "$STAGE/"
install -m 0755 scripts/fetch-megacmd.sh "$STAGE/usr/local/emhttp/plugins/megacmd/scripts/fetch-megacmd.sh"
( cd "$STAGE" && tar -cJf "$OLDPWD/$OUT" --owner=0 --group=0 ./etc ./usr )

echo "Built: $OUT"
echo "MD5:   $(md5sum "$OUT" | cut -d' ' -f1)"
