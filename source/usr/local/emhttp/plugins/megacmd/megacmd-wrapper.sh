#!/bin/bash
# Every mega-* command on PATH (/usr/local/bin/mega-*) is a symlink to this
# script. The real binaries live in /opt/megacmd/bin and need HOME pointed at
# the same persistent session directory the mega-cmd-server service uses --
# otherwise a plain `mega-whoami` typed in a terminal would try to talk to a
# different (nonexistent) session under the shell's real $HOME.
export HOME="/mnt/user/appdata/megacmd/home"
export LD_LIBRARY_PATH="/opt/megacmd/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec "/opt/megacmd/bin/$(basename "$0")" "$@"
