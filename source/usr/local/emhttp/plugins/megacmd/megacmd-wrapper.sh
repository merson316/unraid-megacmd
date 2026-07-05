#!/bin/bash
# Every mega-* command on PATH (/usr/local/bin/mega-*) is a symlink to this
# script. The real binaries live in /opt/megacmd/bin and need HOME pointed at
# the same persistent session directory the mega-cmd-server service uses --
# otherwise a plain `mega-whoami` typed in a terminal would try to talk to a
# different (nonexistent) session under the shell's real $HOME.
# Respect Unraid's own "Default appdata storage location" (Settings > Docker) instead of
# assuming /mnt/user/appdata -- it's user-configurable and may point at a direct pool path.
APPDATA_ROOT="$(grep -oP '(?<=DOCKER_APP_CONFIG_PATH=")[^"]*' /boot/config/docker.cfg 2>/dev/null)"
APPDATA_ROOT="${APPDATA_ROOT%/}"
[ -z "$APPDATA_ROOT" ] && APPDATA_ROOT="/mnt/user/appdata"
export HOME="$APPDATA_ROOT/megacmd/home"
export LD_LIBRARY_PATH="/opt/megacmd/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
# Every mega-* script under /opt/megacmd/bin internally calls a bare "mega-exec ..." -- putting
# /opt/megacmd/bin first on PATH makes that resolve straight to the real binary instead of back
# through this same wrapper (which is also symlinked as /usr/local/bin/mega-exec). Without this,
# the inner call would re-enter this script and attempt a second setpriv from an already-dropped
# (non-root) process, and setgroups() can only be called while still root -- it fails silently
# uninformative-looking ("setgroups failed: Operation not permitted") otherwise.
export PATH="/opt/megacmd/bin:$PATH"
# Runs as nobody:users, matching rc.megacmd's mega-cmd-server -- MEGAcmd's client/server IPC
# only works when both sides run as the same uid; invoked from a root terminal/SSH session
# (Unraid's default), a mismatched-uid client fails to detect the real server and silently
# spawns a second, conflicting one instead of talking to it.
exec setpriv --reuid=99 --regid=100 --clear-groups -- "/opt/megacmd/bin/$(basename "$0")" "$@"
