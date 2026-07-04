<?php
$megaHome = "/mnt/user/appdata/megacmd/home";
$rc = "/etc/rc.d/rc.megacmd";

function megaExec($args) {
  global $megaHome;
  $cmd = "HOME=" . escapeshellarg($megaHome) .
         " LD_LIBRARY_PATH=/opt/megacmd/lib PATH=/opt/megacmd/bin:\$PATH " .
         $args . " 2>&1";
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

// Shortens a path for display in a dropdown so a long path can't blow out the
// <select>'s auto-sized width (which forces the whole page to scroll horizontally).
function shortenPath($path, $maxlen = 36) {
  if (strlen($path) <= $maxlen) return $path;
  $keep = intdiv($maxlen - 1, 2);
  return substr($path, 0, $keep) . "\xE2\x80\xA6" . substr($path, -$keep);
}
