# Unraid MEGAcmd Plugin

Adds a Utilities > MEGAcmd page to Unraid for syncing files between a [MEGA](https://mega.nz) cloud storage account and your Unraid shares, using MEGA's own official command-line client, [MEGAcmd](https://github.com/meganz/MEGAcmd). MEGAcmd's binaries are never vendored here -- they're fetched fresh from MEGA's official repo at install/update time.

## Features

- Log in/out of a MEGA account, and add/remove two-way syncs between local shares and MEGA folders, with folder pickers on both sides.
- View current syncs and sync history from the webGUI.
- Check for and apply newer MEGAcmd releases without waiting for a new plugin release.
- Persistent login/session/sync state stored on the array (`/mnt/user/appdata/megacmd`), survives reboots.
- Every `mega-*` command available on `PATH` in any terminal/SSH session, talking to the same persistent session the background service uses.

## Installation

Add this URL under Community Applications > Install Plugin (or Plugins > Install Plugin manually):

```
https://raw.githubusercontent.com/merson316/unraid-megacmd/main/megacmd.plg
```

## Disclaimer

This plugin is almost entirely "vibe coded" -- written by [Claude Code](https://claude.com/claude-code) under the direction of [merson316](https://github.com/merson316), who built it to solve a personal need rather than as a polished, widely-reviewed project. It has not been audited by anyone with prior MEGAcmd or Unraid plugin development experience, has no automated test suite, and is **largely untested** beyond manual use on the author's own single Unraid server.

Use at your own risk. Read the changelog before updating, back up anything important, and please open an issue if you hit a bug.
