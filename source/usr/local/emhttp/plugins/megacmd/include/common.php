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

// Whether the resolved appdata root's underlying storage is actually available yet (array/pool
// started). Deliberately checks the PARENT of the appdata root, not the root itself -- an
// "appdata" share that doesn't exist yet is normal and will be created on demand (same as any
// other plugin or Docker container), but a missing parent means the array/pool isn't up at all.
function appdataStorageReady() {
  $root = getAppdataRoot();
  $parent = dirname($root);
  if (is_dir($parent)) return true;
  if ($parent === "/mnt/user" && is_dir("/mnt/user0")) return true;
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
  "NOTIFY" => "yes",
  "WATCHDOG" => "yes",
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

// Sends an Unraid notification (bell icon / optional email) via the standard dynamix script.
// $importance is one of "normal", "warning", "alert".
function notify($subject, $description, $importance = "normal") {
  $cfg = getConfig();
  if (($cfg["NOTIFY"] ?? "yes") !== "yes") return;
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

// Same as megaListSyncs() but also includes the ERROR column (columns: ID LOCALPATH REMOTEPATH
// RUN_STATE STATUS ERROR SIZE FILES DIRS) -- used by the watchdog to detect new sync errors.
function megaListSyncsWithError() {
  $r = megaExec('mega-sync --col-separator="|"');
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
