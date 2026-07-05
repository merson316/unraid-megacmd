#!/bin/bash
# Builds the plugin-logic package (rc.d script, webGUI page, event hooks) into a
# Slackware-style .txz. Does NOT bundle MEGAcmd's own binaries -- those are fetched
# fresh from MEGA's official repo at plugin install time (see megacmd.plg).
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="${1:?usage: build.sh <plugin-version, e.g. 2026.07.05 for the first release of the day, 2026.07.05.2/.3/... for same-day follow-ups>}"
OUT="dist/megacmd-plugin-${VERSION}-x86_64-1.txz"

rm -f "$OUT"
( cd source && tar -cJf "../$OUT" --owner=0 --group=0 ./etc ./usr )

echo "Built: $OUT"
echo "MD5:   $(md5sum "$OUT" | cut -d' ' -f1)"
