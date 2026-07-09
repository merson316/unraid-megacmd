<?php
// Unraid's own Docker settings (Settings > Docker > "Default appdata storage location") control
// where appdata actually lives -- it's user-configurable and not necessarily /mnt/user/appdata
// (e.g. a direct pool path like /mnt/apps/appdata, bypassing the /mnt/user FUSE layer entirely
// for performance). Respect that setting instead of assuming the default, falling back to it
// only if the setting is missing or unreadable.
function getAppdataRoot() {
  $cfg = @file_get_contents("/boot/config/docker.cfg");
  if ($cfg !== false && preg_match('/DOCKER_APP_CONFIG_PATH="([^"]*)"/', $cfg, $m) && trim($m[1]) !== "") {
    return rtrim($m[1], "/");
  }
  return "/mnt/user/appdata";
}

function isMountpoint($path) {
  exec("mountpoint -q " . escapeshellarg($path) . " 2>/dev/null", $o, $ret);
  return $ret === 0;
}

// Whether the resolved appdata root's underlying storage is actually available yet (array/pool
// started). Deliberately checks the PARENT of the appdata root, not the root itself -- an
// "appdata" share that doesn't exist yet is normal and will be created on demand (same as any
// other plugin or Docker container), but a parent that isn't a genuine mount means the
// array/pool isn't up at all. A plain is_dir() check isn't enough: mountpoint stubs like
// /mnt/user exist on disk from early boot regardless of whether anything is actually mounted
// there yet -- treating that as "ready" lets writes land directly on the pre-mount stub,
// which can block Unraid's own user-share mount from ever completing.
function appdataStorageReady() {
  $root = getAppdataRoot();
  $parent = dirname($root);
  if (isMountpoint($parent)) return true;
  if ($parent === "/mnt/user" && isMountpoint("/mnt/user0")) return true;
  return false;
}

$megaHome = getAppdataRoot() . "/megacmd/home";
$rc = "/etc/rc.d/rc.megacmd";
// The locally-installed copy of our own .plg -- this is what Unraid actually re-reads and
// re-installs from on every boot (rc.local iterates /boot/config/plugins/*.plg), so rewriting
// the megacmd_version entity here is what makes a runtime MEGAcmd update survive a reboot.
$plgPath = "/boot/config/plugins/megacmd.plg";
// User-configurable settings, following Unraid's standard plugin-config convention
// (/boot/config/plugins/<name>/<name>.cfg, simple KEY="value" lines).
$cfgPath = "/boot/config/plugins/megacmd/megacmd.cfg";
$cfgDefaults = [
  "NOTIFY_RESTART" => "yes",
  "NOTIFY_LOGOUT" => "yes",
  "NOTIFY_SYNCERROR" => "yes",
  "NOTIFY_FSID" => "yes",
  "NOTIFY_QUOTA" => "yes",
  "NOTIFY_UPDATE" => "yes",
  "WATCHDOG" => "yes",
  "WATCHDOG_INTERVAL" => "5",
  "PERM_FILES" => "666",
  "PERM_FOLDERS" => "777",
  "SPEEDLIMIT_UP" => "",
  "SPEEDLIMIT_DOWN" => "",
];

function getConfig() {
  global $cfgPath, $cfgDefaults;
  $cfg = file_exists($cfgPath) ? (@parse_ini_file($cfgPath) ?: []) : [];
  return array_merge($cfgDefaults, $cfg);
}

function saveConfig($cfg) {
  global $cfgPath, $cfgDefaults;
  $cfg = array_merge($cfgDefaults, $cfg);
  $lines = [];
  foreach ($cfg as $key => $value) {
    $lines[] = $key . '="' . str_replace('"', '', $value) . '"';
  }
  if (!is_dir(dirname($cfgPath))) mkdir(dirname($cfgPath), 0755, true);
  return file_put_contents($cfgPath, implode("\n", $lines) . "\n") !== false;
}

// Rewrites the plugin's cron fragment (watchdog interval + daily update check) from the current
// settings and asks Unraid to re-merge it into the live crontab immediately -- so changing the
// watchdog interval on the settings page takes effect right away instead of waiting for a reboot
// or plugin reinstall.
function regenerateCron() {
  $cfg = getConfig();
  $interval = (int)($cfg["WATCHDOG_INTERVAL"] ?? 5);
  if (!in_array($interval, [1, 5, 10, 15, 30, 60], true)) $interval = 5;
  $dir = "/boot/config/plugins/megacmd";
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $cron = "*/$interval * * * * /usr/local/emhttp/plugins/megacmd/scripts/watchdog.php >/dev/null 2>&1\n"
        . "17 3 * * * /usr/local/emhttp/plugins/megacmd/scripts/check-update-notify.php >/dev/null 2>&1\n";
  file_put_contents("$dir/megacmd.cron", $cron);
  exec("/usr/local/sbin/update_cron >/dev/null 2>&1");
}

// Sends an Unraid notification (bell icon / optional email) via the standard dynamix script,
// gated on its own specific settings-page toggle ($cfgKey, e.g. "NOTIFY_QUOTA") rather than one
// shared switch, so each notification type can be turned on or off independently.
// $importance is one of "normal", "warning", "alert".
function notify($subject, $description, $cfgKey, $importance = "normal") {
  $cfg = getConfig();
  if (($cfg[$cfgKey] ?? "yes") !== "yes") return;
  exec(
    "/usr/local/emhttp/plugins/dynamix/scripts/notify" .
    " -e " . escapeshellarg("MEGAcmd") .
    " -s " . escapeshellarg($subject) .
    " -d " . escapeshellarg($description) .
    " -i " . escapeshellarg($importance) .
    " -l " . escapeshellarg("/Settings/megacmd") .
    " >/dev/null 2>&1"
  );
}

// Appends a timestamped line to watchdog.log -- kept deliberately sparse (only notable events,
// mirroring what already triggers a notify() call) rather than logging every 5-minute check, so
// it stays a useful diagnostic trail instead of noise.
function logWatchdog($message) {
  global $megaHome;
  $line = "[" . date("Y-m-d H:i:s") . "] " . $message . "\n";
  @file_put_contents("$megaHome/watchdog.log", $line, FILE_APPEND | LOCK_EX);
}

// Tails the last $lines of each of our own log files (they live under the appdata home dir,
// not Unraid's syslog, so there's no other built-in way to see them) for the "View recent logs"
// / "Download logs" buttons on the settings page.
function getRecentLogs($lines = 200) {
  global $megaHome;
  $sections = [
    "server.log" => "$megaHome/server.log",
    ".megaCmd/megacmdserver.log" => "$megaHome/.megaCmd/megacmdserver.log",
    "watchdog.log" => "$megaHome/watchdog.log",
  ];
  $out = "";
  foreach ($sections as $label => $path) {
    $out .= "==== $label ====\n";
    if (file_exists($path)) {
      $tailOut = [];
      exec("tail -n " . (int)$lines . " " . escapeshellarg($path) . " 2>&1", $tailOut);
      $out .= implode("\n", $tailOut) . "\n";
    } else {
      $out .= "(not present)\n";
    }
    $out .= "\n";
  }
  return sanitizeTerminalOutput($out);
}

function getPlgEntity($entityName) {
  global $plgPath;
  $xml = @file_get_contents($plgPath);
  if ($xml === false) return "";
  if (preg_match('/<!ENTITY\s+' . preg_quote($entityName, '/') . '\s+"([^"]*)"/', $xml, $m)) {
    return $m[1];
  }
  return "";
}

function setPlgEntity($entityName, $newValue) {
  global $plgPath;
  $xml = @file_get_contents($plgPath);
  if ($xml === false) return false;
  $pattern = '/(<!ENTITY\s+' . preg_quote($entityName, '/') . '\s+")[^"]*(")/';
  $updated = preg_replace($pattern, '${1}' . $newValue . '${2}', $xml, 1, $count);
  if ($count !== 1) return false;
  return @file_put_contents($plgPath, $updated) !== false;
}

function httpGet($url) {
  exec("curl -fsSL " . escapeshellarg($url) . " 2>/dev/null", $out, $ret);
  return $ret === 0 ? implode("\n", $out) : false;
}

// Queries MEGA's own repo listing for the newest available megacmd_X.Y.Z-N_amd64.deb under
// the given Debian repo codename. Returns the bare "X.Y.Z" version string, or "" on failure.
function getLatestMegacmdVersion($codename) {
  $html = httpGet("https://mega.nz/linux/repo/" . rawurlencode($codename) . "/amd64/");
  if ($html === false) return "";
  if (!preg_match_all('/megacmd_([0-9]+\.[0-9]+\.[0-9]+)-[0-9.]+_amd64\.deb/', $html, $m)) return "";
  $versions = array_unique($m[1]);
  usort($versions, fn($a, $b) => version_compare($b, $a));
  return $versions[0] ?? "";
}

// Runs as nobody:users (uid 99/gid 100), matching rc.megacmd's mega-cmd-server -- MEGAcmd's
// client/server IPC only works when both sides run as the same uid; a root-run client fails to
// detect the nobody-run server at all and silently spawns a second, conflicting root-owned one.
// MEGAcmd's own transfer-type legend (mega-transfers, mega-sync, etc.) uses a handful of Unicode
// arrow/symbol characters (download/upload/sync/backup) that render inconsistently across
// browsers/fonts inside a plain monospace text block -- some show as tiny/misaligned glyphs,
// others as full-color emoji, breaking the terminal-style look. Swap them for plain ASCII letters
// wherever command output is displayed.
function sanitizeTerminalOutput($text) {
  return strtr($text, [
    "\xE2\x87\x93" => "D", // download
    "\xE2\x87\x91" => "U", // upload
    "\xE2\x87\xB5" => "S", // sync
    "\xE2\x8F\xAB" => "B", // backup
  ]);
}

// mega-transfers' own column-width calculation for the TYPE field is inconsistent across
// different symbol combinations -- verified some combinations (e.g. upload+backup) get one
// fewer padding space than others (e.g. download+sync), even though every combination is the
// same 2 characters wide after sanitizeTerminalOutput(). That visibly misaligns every column
// after it. Since TYPE is always exactly 2 characters here, normalize the gap that follows it
// to a fixed width instead of trusting MEGAcmd's own (inconsistent) padding.
function fixTransferTypeAlignment($text) {
  return preg_replace('/^([DUSB]{2})\s+/m', '$1   ', $text);
}

function megaExec($args) {
  global $megaHome;
  $cmd = "env HOME=" . escapeshellarg($megaHome) .
         " LD_LIBRARY_PATH=/opt/megacmd/lib PATH=/opt/megacmd/bin:\$PATH " .
         "setpriv --reuid=99 --regid=100 --clear-groups -- bash -c " . escapeshellarg($args) . " 2>&1";
  exec($cmd, $out, $ret);
  return array("output" => sanitizeTerminalOutput(implode("\n", $out)), "code" => $ret);
}

// Re-applies the configured upload/download speed limits -- MEGAcmd only remembers these for
// the life of the current login session, so this needs to run again after every fresh login.
function applySpeedlimit() {
  $cfg = getConfig();
  $up = trim($cfg["SPEEDLIMIT_UP"] ?? "");
  $down = trim($cfg["SPEEDLIMIT_DOWN"] ?? "");
  if ($up !== "") megaExec("mega-speedlimit -u " . escapeshellarg($up));
  if ($down !== "") megaExec("mega-speedlimit -d " . escapeshellarg($down));
}

// MEGAcmd's own defaults for newly created files/folders (600/700) are far more restrictive
// than Unraid's "New Permissions" convention (777 for folders, 666 for files) that every share
// and Docker container on Unraid is expected to follow, so synced-down content would otherwise
// be unreadable/unwritable to other users and containers. Like speed limits, MEGAcmd only
// remembers this for the life of the current login session, so it must be re-applied after
// every fresh login -- it survives a plain service restart, but is silently reset on logout.
function applyPermissions() {
  $cfg = getConfig();
  $files = $cfg["PERM_FILES"] ?? "666";
  $folders = $cfg["PERM_FOLDERS"] ?? "777";
  if (!preg_match('/^[0-7]{3}$/', $files)) $files = "666";
  if (!preg_match('/^[0-7]{3}$/', $folders)) $folders = "777";
  megaExec("mega-permissions --files -s " . escapeshellarg($files));
  megaExec("mega-permissions --folders -s " . escapeshellarg($folders));
}

function serviceRunning() {
  global $rc;
  exec("$rc status", $out, $ret);
  return $ret === 0;
}

// Lists immediate subfolders of a remote MEGA path (non-recursive).
function megaListRemoteDirs($remotepath) {
  $r = megaExec("mega-ls -l " . escapeshellarg($remotepath));
  $dirs = [];
  foreach (explode("\n", $r["output"]) as $line) {
    // Columns: FLAGS VERS SIZE DATE TIME NAME (NAME may itself contain spaces)
    if (preg_match('/^(\S+)\s+\S+\s+\S+\s+\S+\s+\S+\s+(.*)$/', $line, $m) && $m[1][0] === 'd') {
      $dirs[] = $m[2];
    }
  }
  return $dirs;
}

// Parses `mega-sync` into a list of ['id','local','remote'] for the current syncs.
function megaListSyncs() {
  $r = megaExec('mega-sync --col-separator="|"');
  $lines = explode("\n", trim($r["output"]));
  array_shift($lines); // header row
  $syncs = [];
  foreach ($lines as $line) {
    if (trim($line) === "") continue;
    $cols = explode('|', $line);
    if (count($cols) < 3) continue;
    $syncs[] = ["id" => $cols[0], "local" => $cols[1], "remote" => $cols[2]];
  }
  return $syncs;
}

// Parses `mega-backup` into a list of ['tag','local','remote','status'] for configured backups.
// Unlike mega-sync/mega-transfers, mega-backup has no --col-separator option, so this slices
// the fixed-width table by the column start offsets found in its own header row (robust against
// spaces in local paths, unlike naive whitespace-splitting). --path-display-size=500 asks for
// paths wide enough that they're never truncated with "..." in the middle.
function megaListBackups() {
  $r = megaExec("mega-backup --path-display-size=500");
  $lines = explode("\n", $r["output"]);
  $header = array_shift($lines);
  $tagPos = strpos($header, "TAG");
  $localPos = strpos($header, "LOCALPATH");
  $remotePos = strpos($header, "REMOTEPARENTPATH");
  $statusPos = strpos($header, "STATUS");
  if ($tagPos === false || $localPos === false || $remotePos === false || $statusPos === false) return [];
  $backups = [];
  foreach ($lines as $line) {
    if (trim($line) === "") continue;
    $tag = trim(substr($line, $tagPos, $localPos - $tagPos));
    if ($tag === "") continue;
    $backups[] = [
      "tag" => $tag,
      "local" => trim(substr($line, $localPos, $remotePos - $localPos)),
      "remote" => trim(substr($line, $remotePos, $statusPos - $remotePos)),
      "status" => trim(substr($line, $statusPos)),
    ];
  }
  return $backups;
}

// Parses `mega-df` for the account's overall storage usage (used by the Dashboard tile). Returns
// null if the figures aren't present (e.g. not logged in, or MEGA changes this output format).
function megaGetAccountStorage() {
  $r = megaExec("mega-df");
  if (!preg_match('/USED STORAGE:\s*(\d+)\s+([\d.]+)% of (\d+)/', $r["output"], $m)) return null;
  return ["usedBytes" => (int)$m[1], "percent" => (float)$m[2], "totalBytes" => (int)$m[3]];
}

// Short human-readable byte size (e.g. "27.4 GB") for compact dashboard-tile display -- distinct
// from mega-df's own "-h" output since we need the raw numbers for the percent calc anyway.
function formatBytesShort($bytes) {
  $units = ["B", "KB", "MB", "GB", "TB", "PB"];
  $i = 0;
  $val = (float)$bytes;
  while ($val >= 1024 && $i < count($units) - 1) { $val /= 1024; $i++; }
  return round($val, $val < 10 ? 1 : 0) . " " . $units[$i];
}

// Same as megaListSyncs() but also includes the ERROR column (columns: ID LOCALPATH REMOTEPATH
// RUN_STATE STATUS ERROR SIZE FILES DIRS) -- used by the watchdog to detect new sync errors, and
// by the self-heal feature below, which needs the full untruncated path to re-add a sync
// correctly (hence --path-display-size, same reasoning as megaListBackups() above).
function megaListSyncsWithError() {
  $r = megaExec('mega-sync --col-separator="|" --path-display-size=500');
  $lines = explode("\n", trim($r["output"]));
  array_shift($lines); // header row
  $syncs = [];
  foreach ($lines as $line) {
    if (trim($line) === "") continue;
    $cols = explode('|', $line);
    if (count($cols) < 6) continue;
    $syncs[] = ["id" => $cols[0], "local" => $cols[1], "remote" => $cols[2], "error" => trim($cols[5])];
  }
  return $syncs;
}

// Syncs currently disabled by MEGAcmd's "Mismatch on sync root FSID" safety check (it refuses to
// keep syncing if the local root's underlying filesystem identity changes, e.g. after an unclean
// reboot -- see the self-heal note on selfHealSyncFsid() below). Used to show the self-heal
// button only when it's actually relevant, never unconditionally.
function megaListSyncsWithFsidMismatch() {
  $syncs = [];
  foreach (megaListSyncsWithError() as $s) {
    if (stripos($s["error"], "FSID") !== false) $syncs[] = $s;
  }
  return $syncs;
}

// Self-heals a sync stuck in the FSID-mismatch state. Confirmed empirically that simply
// re-enabling it (`mega-sync -e`) does NOT clear this -- it fails again with the exact same
// error, even though MEGAcmd's own docs describe a "Disabled" sync as having no cached state.
// The only fix found is to remove the sync's tracked configuration (files are untouched by this)
// and re-add it fresh, which makes MEGAcmd record a new filesystem-identity baseline and
// re-compare local vs remote from scratch. Deliberately never called automatically by the
// watchdog -- only from an explicit user action, since re-adding is a real (if normally harmless)
// reconciliation pass and the user should decide whether the local folder is actually trustworthy
// first.
function selfHealSyncFsid($syncId) {
  $target = null;
  foreach (megaListSyncsWithError() as $s) {
    if ($s["id"] === $syncId) { $target = $s; break; }
  }
  if ($target === null) return "Sync not found -- it may have already been removed or changed.";
  $del = megaExec("mega-sync -d " . escapeshellarg($syncId));
  $add = megaExec("mega-sync " . escapeshellarg($target["local"]) . " " . escapeshellarg($target["remote"]));
  return trim($del["output"] . "\n" . $add["output"]);
}
