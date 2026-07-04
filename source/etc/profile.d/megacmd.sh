#!/bin/sh
# Convenience env for interactive use of the mega-* CLI from an Unraid terminal.
# The mega-* client tools locate the running server via $HOME/.megaCmd, and the
# service runs with HOME pointed at persistent appdata storage, so plain
# `mega-whoami` etc. won't find it unless HOME is set the same way here.
# Usage: megacmd mega-whoami | megacmd mega-sync ...
export PATH="/opt/megacmd/bin:$PATH"
export LD_LIBRARY_PATH="/opt/megacmd/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
megacmd() { HOME="/mnt/user/appdata/megacmd/home" "$@"; }
