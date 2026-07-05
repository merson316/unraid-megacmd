# Unraid MEGAcmd Plugin

Adds a Utilities > MEGAcmd page to Unraid for syncing files between a [MEGA](https://mega.nz) cloud storage account and your Unraid shares, using MEGA's own official command-line client, [MEGAcmd](https://github.com/meganz/MEGAcmd). MEGAcmd's binaries are never vendored here -- they're fetched fresh from MEGA's official repo at install/update time.

## Features

- Log in/out of a MEGA account, and add/remove two-way syncs between local shares and MEGA folders, with folder pickers on both sides.
- One-way backups (MEGA's own `mega-backup`, currently in BETA) as an alternative to two-way sync, with a configurable period and retention count.
- View current syncs, backups, and sync history from the webGUI.
- Check for and apply newer MEGAcmd releases without waiting for a new plugin release.
- A watchdog checks every 5 minutes and restarts the service if it's crashed (configurable).
- Unraid notifications for problems -- service crashes, unexpected logout, sync errors, MEGA bandwidth quota reached, and available MEGAcmd updates -- instead of having to check the settings page (configurable).
- Configurable upload/download speed limits.
- A Dashboard tile showing login status, active sync count, and installed MEGAcmd version at a glance.
- Persistent login/session/sync state stored on the array (`/mnt/user/appdata/megacmd`), survives reboots, and is automatically included by the CA Backup/Restore Appdata plugin like any other appdata folder.
- Every `mega-*` command available on `PATH` in any terminal/SSH session, talking to the same persistent session the background service uses.

## Installation

Add this URL under Community Applications > Install Plugin (or Plugins > Install Plugin manually):

```
https://raw.githubusercontent.com/merson316/unraid-megacmd/main/megacmd.plg
```

## Disclaimer

This plugin is almost entirely "vibe coded" -- written by [Claude Code](https://claude.com/claude-code) under the direction of [merson316](https://github.com/merson316), who built it to solve a personal need rather than as a polished, widely-reviewed project. It has not been audited by anyone with prior MEGAcmd or Unraid plugin development experience, has no automated test suite, and is **largely untested** beyond manual use on the author's own single Unraid server.

Use at your own risk. Read the changelog before updating, back up anything important, and please open an issue if you hit a bug.

## License

[MIT](LICENSE)
