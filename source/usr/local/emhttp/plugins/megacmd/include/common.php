<?php
$megaHome = "/mnt/user/appdata/megacmd/home";
$rc = "/etc/rc.d/rc.megacmd";
// The locally-installed copy of our own .plg -- this is what Unraid actually re-reads and
// re-installs from on every boot (rc.local iterates /boot/config/plugins/*.plg), so rewriting
// the megacmd_version entity here is what makes a runtime MEGAcmd update survive a reboot.
$plgPath = "/boot/config/plugins/megacmd.plg";

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
function megaExec($args) {
  global $megaHome;
  $cmd = "env HOME=" . escapeshellarg($megaHome) .
         " LD_LIBRARY_PATH=/opt/megacmd/lib PATH=/opt/megacmd/bin:\$PATH " .
         "setpriv --reuid=99 --regid=100 --clear-groups -- bash -c " . escapeshellarg($args) . " 2>&1";
  exec($cmd, $out, $ret);
  return array("output" => implode("\n", $out), "code" => $ret);
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
