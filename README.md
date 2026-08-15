# Unraid MEGAcmd Plugin

This plugin adds a Settings > MEGAcmd page to Unraid. Use it to sync files between a [MEGA](https://mega.nz) cloud storage account and your Unraid shares. It uses the official command-line client from MEGA, [MEGAcmd](https://github.com/meganz/MEGAcmd). This plugin does not include the MEGAcmd binaries; it fetches them fresh from the official MEGA repo at install and update time.

## Features

- Log in to, or out of, a MEGA account. Add or remove two-way syncs between local shares and MEGA folders, with folder pickers on both sides.
- One-way backups (`mega-backup` from MEGA, currently in BETA) as an alternative to two-way sync, with a period and retention count you can set.
- View current syncs, backups, transfer history, and backup history in the webGUI.
- Check for newer MEGAcmd releases, and install one, without a wait for a new plugin release.
- A watchdog that checks at an interval you choose (1 to 60 minutes). It can restart the service if the service crashes, reapply file and folder permissions, check for sync or login problems, and protect sync folders from Unraid's mover.
- Unraid notifications for problems: service crashes, unexpected logout, sync errors, a sync disabled by a filesystem-identity mismatch, MEGA bandwidth quota reached, and MEGAcmd updates available. You can turn each notification type on or off.
- Upload and download speed limits that you can set.
- A Dashboard tile that shows login status, cloud storage use, active sync and backup counts, and a warning when a sync needs attention.
- Login, session, and sync state that persists on the array (`/mnt/user/appdata/megacmd`). This state survives reboots, and the CA Backup/Restore Appdata plugin includes it like any other appdata folder.
- Every `mega-*` command on `PATH` in any terminal or SSH session, connected to the same persistent session that the background service uses.

## Installation

Add this URL under Community Applications > Install Plugin (or Plugins > Install Plugin manually):

```
https://raw.githubusercontent.com/merson316/unraid-megacmd/main/megacmd.plg
```

## Disclaimer

This plugin is almost entirely "vibe coded". [Claude Code](https://claude.com/claude-code) wrote it, under the direction of [merson316](https://github.com/merson316), to solve a personal need rather than as a polished, widely-reviewed project. No one with prior MEGAcmd or Unraid plugin development experience has reviewed it. It has no automated test suite. It is **largely untested**, beyond manual use on the author's own single Unraid server.

Use this plugin at your own risk. Read the changelog before you update. Back up anything important first. If you find a bug, open an issue.

## License

[MIT](LICENSE)
